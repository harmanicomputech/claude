<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Jobs\SendSms;
use App\Models\Coordinator;
use App\Services\ElectionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class UrgentAlertTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->setUpUssd();

        Coordinator::create(['name' => 'Abakaliki Lead', 'phone_number' => '08020000001', 'lga' => 'Abakaliki']);
        Coordinator::create(['name' => 'Ikwo Lead', 'phone_number' => '08020000002', 'lga' => 'Ikwo']);
        Coordinator::create(['name' => 'State Lead', 'phone_number' => '08020000003']);
    }

    public function test_violence_alerts_the_lga_and_state_coordinators(): void
    {
        $incident = app(ElectionRecorder::class)->logIncident($this->agent, self::PU, IncidentType::Violence, 'Thugs at PU');

        $recipients = Queue::pushed(SendSms::class)->pluck('to')->all();
        sort($recipients);

        $this->assertSame(['+2348020000001', '+2348020000003'], $recipients);

        Queue::assertPushed(SendSms::class, fn (SendSms $job) => $job->message
            === "VIOLENCE ALERT: Amachi Pry Sch, Abakaliki (PU 110101001). \"Thugs at PU\" - Ada Obi +2348011111111. Ref {$incident->reference}");
    }

    public function test_non_urgent_incidents_do_not_alert(): void
    {
        app(ElectionRecorder::class)->logIncident($this->agent, self::PU, IncidentType::Delay, 'Late start');

        Queue::assertNotPushed(SendSms::class);
    }

    public function test_urgent_types_are_configurable(): void
    {
        config(['election.urgent_incident_types' => ['violence', 'vote_buying']]);

        app(ElectionRecorder::class)->logIncident($this->agent, self::PU, IncidentType::VoteBuying, 'Cash');

        Queue::assertPushed(SendSms::class, 2);
    }

    public function test_coordinator_command(): void
    {
        $this->artisan('coordinator:add', ['phone' => '08020000004', 'name' => 'Ezza Lead', '--lga' => 'Ezza North'])
            ->expectsOutputToContain('Ezza North LGA')
            ->assertSuccessful();

        $this->assertSame('Ezza North', Coordinator::where('phone_number', '+2348020000004')->sole()->lga);
    }
}
