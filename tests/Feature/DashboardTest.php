<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Jobs\PushToDashboard;
use App\Models\Agent;
use App\Models\Presence;
use App\Services\DashboardClient;
use App\Services\ElectionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.dashboard' => [
            'url' => 'https://dashboard.test/api/election-shield',
            'token' => 'dash-token',
            'secret' => 'dash-secret',
            'timeout' => 10,
        ]]);

        $this->agent = Agent::factory()->create(['name' => 'Ada Obi', 'phone_number' => '+2348011111111']);
    }

    private function recorder(): ElectionRecorder
    {
        return app(ElectionRecorder::class);
    }

    public function test_results_incidents_and_presence_are_queued_for_the_dashboard(): void
    {
        Queue::fake();

        $result = $this->recorder()->submitResult($this->agent, '02345', 120, 300);
        $incident = $this->recorder()->logIncident($this->agent, '02345', IncidentType::Delay, 'Late start');
        $presence = $this->recorder()->confirmPresence($this->agent, '02345');

        Queue::assertPushed(PushToDashboard::class, 3);
        Queue::assertPushed(PushToDashboard::class, fn ($job) => $job->record->is($result));
        Queue::assertPushed(PushToDashboard::class, fn ($job) => $job->record->is($incident));
        Queue::assertPushed(PushToDashboard::class, fn ($job) => $job->record->is($presence));
    }

    public function test_nothing_is_queued_when_the_dashboard_is_not_configured(): void
    {
        Queue::fake();
        config(['services.dashboard.url' => null]);

        $this->recorder()->submitResult($this->agent, '02345', 120, 300);

        Queue::assertNotPushed(PushToDashboard::class);
    }

    public function test_result_is_delivered_as_signed_json_and_marked_synced(): void
    {
        Http::fake(['dashboard.test/*' => Http::response(['ok' => true])]);

        $result = $this->recorder()->submitResult($this->agent, '02345', 120, 300);

        Http::assertSent(function (Request $request) use ($result) {
            $expectedSignature = 'sha256='.hash_hmac('sha256', $request->body(), 'dash-secret');

            return $request->url() === 'https://dashboard.test/api/election-shield'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer dash-token')
                && $request->hasHeader('Idempotency-Key', $result->reference)
                && $request->hasHeader('X-Election-Shield-Event', 'result.submitted')
                && $request->hasHeader('X-Election-Shield-Signature', $expectedSignature)
                && $request['event'] === 'result.submitted'
                && $request['data'] === [
                    'reference' => $result->reference,
                    'polling_unit_code' => '02345',
                    'candidate_votes' => 120,
                    'total_votes' => 300,
                    'agent' => ['name' => 'Ada Obi', 'phone_number' => '+2348011111111'],
                    'submitted_at' => $result->created_at->toIso8601String(),
                ];
        });

        $this->assertNotNull($result->refresh()->dashboard_synced_at);
    }

    public function test_incident_and_presence_payloads(): void
    {
        Http::fake();

        $incident = $this->recorder()->logIncident($this->agent, '02345', IncidentType::VoteBuying, 'Cash at queue');
        $this->recorder()->confirmPresence($this->agent, '02345');

        Http::assertSent(fn (Request $request) => $request['event'] === 'incident.reported'
            && $request['data']['reference'] === $incident->reference
            && $request['data']['type'] === 'vote_buying'
            && $request['data']['type_label'] === 'Vote Buying'
            && $request['data']['note'] === 'Cash at queue');

        Http::assertSent(fn (Request $request) => $request['event'] === 'presence.confirmed'
            && $request['data']['polling_unit_code'] === '02345'
            && $request->hasHeader('Idempotency-Key', 'presence-'.Presence::sole()->id));
    }

    public function test_failed_delivery_leaves_the_record_unsynced(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('down', 503)]);

        $result = $this->recorder()->submitResult($this->agent, '02345', 120, 300);

        try {
            (new PushToDashboard($result))->handle(app(DashboardClient::class));
            $this->fail('Expected the delivery to throw so the queue retries it.');
        } catch (RequestException) {
            // expected
        }

        $this->assertNull($result->refresh()->dashboard_synced_at);
    }

    public function test_already_synced_records_are_not_resent(): void
    {
        Queue::fake();
        Http::fake();

        $result = $this->recorder()->submitResult($this->agent, '02345', 120, 300);
        $result->forceFill(['dashboard_synced_at' => now()])->save();

        (new PushToDashboard($result))->handle(app(DashboardClient::class));

        Http::assertNothingSent();
    }

    public function test_sync_command_requeues_unsynced_records(): void
    {
        Queue::fake();

        $old = $this->recorder()->submitResult($this->agent, '02345', 120, 300);
        $old->forceFill(['created_at' => now()->subHour()])->save();

        $synced = $this->recorder()->submitResult($this->agent, '02346', 50, 90);
        $synced->forceFill(['created_at' => now()->subHour(), 'dashboard_synced_at' => now()])->save();

        $recent = $this->recorder()->submitResult($this->agent, '02347', 10, 20);

        Queue::fake(); // forget the jobs queued on submission

        $this->artisan('dashboard:sync')->assertSuccessful();

        Queue::assertPushed(PushToDashboard::class, 1);
        Queue::assertPushed(PushToDashboard::class, fn ($job) => $job->record->is($old));
    }
}
