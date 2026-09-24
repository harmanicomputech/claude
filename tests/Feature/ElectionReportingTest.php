<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\PollingUnit;
use App\Services\ElectionRecorder;
use App\Services\ElectionStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class ElectionReportingTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    private PollingUnit $quietUnit;

    private Agent $quietAgent;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->setUpUssd();

        config(['election.date' => '2027-02-06']);
        $this->travelTo(Carbon::parse('2027-02-06 16:00', 'Africa/Lagos'));

        $this->quietUnit = PollingUnit::factory()->create(['code' => '110201001', 'name' => 'Quiet Hall', 'ward' => 'Ward 9', 'lga' => 'Ikwo']);
        $this->quietAgent = Agent::factory()->assignedTo('110201001')->create(['name' => 'Quiet Agent']);
        $this->agent->update(['polling_unit_code' => self::PU]);

        $recorder = app(ElectionRecorder::class);
        $recorder->confirmPresence($this->agent, self::PU);
        $recorder->submitResult($this->agent, self::PU, 300, ['APC' => 120, 'PDP' => 80, 'LP' => 20], 5);
        $recorder->logIncident($this->agent, self::PU, IncidentType::Violence, 'Fight');
    }

    public function test_summary_numbers(): void
    {
        $summary = app(ElectionStats::class)->summary();

        $this->assertSame(2, $summary['polling_units']);
        $this->assertSame(1, $summary['presence']['polling_units']);
        $this->assertSame(50.0, $summary['presence']['percent']);
        $this->assertSame(1, $summary['results']['polling_units']);
        $this->assertSame(['APC' => 120, 'PDP' => 80, 'LP' => 20], $summary['results']['party_votes']);
        $this->assertSame(220, $summary['results']['total_valid_votes']);
        $this->assertSame(300, $summary['results']['accredited_voters']);
        $this->assertSame(['violence' => 1], $summary['incidents']['by_type']);
        $this->assertSame([
            ['lga' => 'Abakaliki', 'polling_units' => 1, 'presence' => 1, 'results' => 1, 'results_percent' => 100.0],
            ['lga' => 'Ikwo', 'polling_units' => 1, 'presence' => 0, 'results' => 0, 'results_percent' => 0.0],
        ], $summary['by_lga']);
    }

    public function test_presence_before_election_day_does_not_count(): void
    {
        $this->travelTo(Carbon::parse('2027-02-05 10:00', 'Africa/Lagos'));
        app(ElectionRecorder::class)->confirmPresence($this->quietAgent, '110201001');
        $this->travelTo(Carbon::parse('2027-02-06 16:00', 'Africa/Lagos'));

        $this->assertSame(['110201001'], app(ElectionStats::class)->missing('presence')->pluck('code')->all());
    }

    public function test_presence_just_after_midnight_counts_for_election_day(): void
    {
        $this->travelTo(Carbon::parse('2027-02-06 00:30', 'Africa/Lagos'));
        app(ElectionRecorder::class)->confirmPresence($this->quietAgent, '110201001');
        $this->travelTo(Carbon::parse('2027-02-06 16:00', 'Africa/Lagos'));

        $this->assertSame([], app(ElectionStats::class)->missing('presence')->pluck('code')->all());
    }

    public function test_missing_lists_pus_with_their_agents(): void
    {
        $missing = app(ElectionStats::class)->missing('results');

        $this->assertSame(['110201001'], $missing->pluck('code')->all());
        $this->assertSame('Quiet Agent', $missing->first()['agents'][0]['name']);

        $this->artisan('election:missing', ['type' => 'presence'])
            ->expectsOutputToContain('Quiet Hall')
            ->expectsOutputToContain('1 polling unit(s) missing presence.')
            ->assertSuccessful();
    }

    public function test_reminders_only_go_to_agents_who_have_not_reported(): void
    {
        $this->artisan('election:remind', ['type' => 'presence'])->expectsOutputToContain('Queued 1 reminder')->assertSuccessful();
        Queue::assertPushed(SendSms::class, fn (SendSms $job) => $job->to === $this->quietAgent->phone_number
            && str_contains($job->message, 'confirm you are at your PU'));

        Queue::fake();
        $this->artisan('election:remind', ['type' => 'results'])->expectsOutputToContain('Queued 1 reminder')->assertSuccessful();
        Queue::assertPushed(SendSms::class, 1);
        Queue::assertPushed(SendSms::class, fn (SendSms $job) => $job->to === $this->quietAgent->phone_number
            && str_contains($job->message, 'result has not been received'));
    }

    public function test_reports_api(): void
    {
        config(['election.api_token' => 'api-secret']);
        $headers = ['Authorization' => 'Bearer api-secret'];

        $this->getJson('/api/reports/summary', $headers)
            ->assertOk()
            ->assertJsonPath('data.results.polling_units', 1)
            ->assertJsonPath('data.results.party_votes.APC', 120);

        $this->getJson('/api/reports/missing?type=results', $headers)
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.code', '110201001');

        $this->getJson('/api/reports/missing?type=results&lga=Abakaliki', $headers)->assertJsonPath('count', 0);
        $this->getJson('/api/reports/missing?type=bogus', $headers)->assertUnprocessable();
    }
}
