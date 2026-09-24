<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Models\DashboardDelivery;
use App\Models\Incident;
use App\Models\Presence;
use App\Models\User;
use App\Services\CorrectionReviewer;
use App\Services\ElectionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class DashboardBackfillTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    public function test_existing_data_is_sent_once_the_dashboard_is_connected(): void
    {
        Queue::fake();
        $this->setUpUssd();

        // Collected before any dashboard was configured: nothing recorded.
        $recorder = app(ElectionRecorder::class);
        $original = $recorder->submitResult($this->agent, self::PU, 300, ['APC' => 120, 'PDP' => 80, 'LP' => 20], 5);
        $correction = $recorder->submitResult($this->agent, self::PU, 300, ['APC' => 130, 'PDP' => 80, 'LP' => 20], 5, correction: true);
        app(CorrectionReviewer::class)->approve($correction, 'Admin');
        $recorder->logIncident($this->agent, self::PU, IncidentType::Delay, 'Late');
        $recorder->confirmPresence($this->agent, self::PU);
        $this->assertDatabaseCount('dashboard_deliveries', 0);

        config(['services.dashboard.url' => 'https://dashboard.test']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/settings/dashboard-backfill')->assertSessionHas('status', fn ($s) => str_starts_with($s, '4 event(s)'));

        $incident = Incident::sole();
        $presence = Presence::sole();
        $events = DashboardDelivery::orderBy('id')->get()->mapWithKeys(fn ($delivery) => [$delivery->idempotency_key => $delivery->payload]);

        $this->assertSame([
            "result.submitted:{$original->reference}",
            "result.corrected:{$correction->reference}",
            "incident.reported:{$incident->reference}",
            "presence.confirmed:{$presence->id}",
        ], $events->keys()->all());
        $this->assertSame('superseded', $events["result.submitted:{$original->reference}"]['status']);
        $this->assertSame($original->reference, $events["result.corrected:{$correction->reference}"]['superseded_reference']);
        $this->assertFalse($events["incident.reported:{$incident->reference}"]['rehearsal']);

        // Safe to repeat.
        $this->artisan('dashboard:backfill')->expectsOutputToContain('Queued 0 event(s)')->assertSuccessful();
        $this->assertDatabaseHas('audit_logs', ['action' => 'dashboard.backfilled']);
    }
}
