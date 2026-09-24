<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Jobs\PushToDashboard;
use App\Models\DashboardDelivery;
use App\Models\Presence;
use App\Models\Result;
use App\Services\CorrectionReviewer;
use App\Services\DashboardClient;
use App\Services\ElectionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.dashboard' => [
            'url' => 'https://dashboard.test/api/election-shield',
            'token' => 'dash-token',
            'secret' => 'dash-secret',
            'timeout' => 10,
        ]]);

        $this->setUpUssd();
    }

    private function submit(string $pu = self::PU, bool $correction = false): ?Result
    {
        return app(ElectionRecorder::class)->submitResult($this->agent, $pu, 300, ['APC' => 120, 'PDP' => 80, 'LP' => 20], 5, $correction);
    }

    public function test_every_event_is_recorded_and_queued(): void
    {
        Queue::fake();

        $this->submit();
        app(ElectionRecorder::class)->logIncident($this->agent, self::PU, IncidentType::Delay, 'Late start');
        app(ElectionRecorder::class)->confirmPresence($this->agent, self::PU);

        $this->assertSame(
            ['result.submitted', 'incident.reported', 'presence.confirmed'],
            DashboardDelivery::orderBy('id')->pluck('event')->all(),
        );
        Queue::assertPushed(PushToDashboard::class, 3);
    }

    public function test_nothing_is_recorded_when_the_dashboard_is_not_configured(): void
    {
        Queue::fake();
        config(['services.dashboard.url' => null]);

        $this->submit();

        $this->assertDatabaseCount('dashboard_deliveries', 0);
        Queue::assertNotPushed(PushToDashboard::class);
    }

    public function test_result_is_delivered_as_signed_json(): void
    {
        Http::fake(['dashboard.test/*' => Http::response(['ok' => true])]);

        $result = $this->submit();

        Http::assertSent(function (Request $request) use ($result) {
            $expectedSignature = 'sha256='.hash_hmac('sha256', $request->body(), 'dash-secret');

            return $request->url() === 'https://dashboard.test/api/election-shield'
                && $request->hasHeader('Authorization', 'Bearer dash-token')
                && $request->hasHeader('Idempotency-Key', "result.submitted:{$result->reference}")
                && $request->hasHeader('X-Election-Shield-Event', 'result.submitted')
                && $request->hasHeader('X-Election-Shield-Signature', $expectedSignature)
                && $request['event'] === 'result.submitted'
                && $request['data']['reference'] === $result->reference
                && $request['data']['status'] === 'accepted'
                && $request['data']['polling_unit'] === [
                    'code' => self::PU,
                    'name' => 'Amachi Pry Sch',
                    'ward' => 'Amachi Ward',
                    'lga' => 'Abakaliki',
                    'registered_voters' => 1000,
                ]
                && $request['data']['accredited_voters'] === 300
                && $request['data']['votes'] === ['APC' => 120, 'PDP' => 80, 'LP' => 20]
                && $request['data']['total_valid_votes'] === 220
                && $request['data']['rejected_votes'] === 5
                && $request['data']['total_votes_cast'] === 225
                && $request['data']['agent'] === ['name' => 'Ada Obi', 'phone_number' => '+2348011111111'];
        });

        $this->assertNotNull(DashboardDelivery::sole()->delivered_at);
    }

    public function test_correction_events(): void
    {
        Http::fake();

        $original = $this->submit();
        $correction = $this->submit(correction: true);
        app(CorrectionReviewer::class)->approve($correction, 'Coordinator');

        Http::assertSent(fn (Request $request) => $request['event'] === 'result.correction_requested'
            && $request['data']['status'] === 'pending'
            && $request['data']['corrects_reference'] === $original->reference);

        Http::assertSent(fn (Request $request) => $request['event'] === 'result.corrected'
            && $request['data']['reference'] === $correction->reference
            && $request['data']['status'] === 'accepted'
            && $request['data']['superseded_reference'] === $original->reference);
    }

    public function test_incident_and_presence_payloads(): void
    {
        Http::fake();

        $incident = app(ElectionRecorder::class)->logIncident($this->agent, self::PU, IncidentType::VoteBuying, 'Cash at queue');
        app(ElectionRecorder::class)->confirmPresence($this->agent, self::PU);

        Http::assertSent(fn (Request $request) => $request['event'] === 'incident.reported'
            && $request['data']['reference'] === $incident->reference
            && $request['data']['type'] === 'vote_buying'
            && $request['data']['urgent'] === false
            && $request['data']['polling_unit']['lga'] === 'Abakaliki');

        Http::assertSent(fn (Request $request) => $request['event'] === 'presence.confirmed'
            && $request['data']['polling_unit']['code'] === self::PU
            && $request->hasHeader('Idempotency-Key', 'presence.confirmed:'.Presence::sole()->id));
    }

    public function test_failed_delivery_is_left_undelivered_with_the_error(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('down', 503)]);

        $this->submit();
        $delivery = DashboardDelivery::sole();

        try {
            (new PushToDashboard($delivery))->handle(app(DashboardClient::class));
            $this->fail('Expected the delivery to throw so the queue retries it.');
        } catch (RequestException) {
            // expected
        }

        $delivery->refresh();
        $this->assertNull($delivery->delivered_at);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringContainsString('503', $delivery->last_error);
    }

    public function test_delivered_events_are_not_resent(): void
    {
        Queue::fake();
        Http::fake();

        $this->submit();
        $delivery = DashboardDelivery::sole();
        $delivery->forceFill(['delivered_at' => now()])->save();

        (new PushToDashboard($delivery))->handle(app(DashboardClient::class));

        Http::assertNothingSent();
    }

    public function test_sync_command_requeues_old_undelivered_events(): void
    {
        Queue::fake();

        $this->submit();
        app(ElectionRecorder::class)->confirmPresence($this->agent, self::PU);
        app(ElectionRecorder::class)->logIncident($this->agent, self::PU, IncidentType::Other, 'x');

        [$old, $delivered, $recent] = DashboardDelivery::orderBy('id')->get()->all();
        $old->forceFill(['created_at' => now()->subHour()])->save();
        $delivered->forceFill(['created_at' => now()->subHour(), 'delivered_at' => now()])->save();

        Queue::fake(); // forget the jobs queued on creation

        $this->artisan('dashboard:sync')->assertSuccessful();

        Queue::assertPushed(PushToDashboard::class, 1);
        Queue::assertPushed(PushToDashboard::class, fn ($job) => $job->delivery->is($old));
    }
}
