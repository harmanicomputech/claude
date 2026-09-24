<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Enums\MaterialStatus;
use App\Models\Agent;
use App\Models\PollingUnit;
use App\Models\Result;
use App\Services\CorrectionReviewer;
use App\Services\ElectionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class DataApiTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    private array $headers = ['Authorization' => 'Bearer api-secret'];

    private Result $abakaliki;

    private Result $ikwo;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['election.api_token' => 'api-secret']);
        $this->setUpUssd();

        PollingUnit::factory()->create(['code' => '21802700001', 'name' => 'Ikwo Town Hall 001', 'ward' => 'Ikwo Ward 01', 'lga' => 'Ikwo']);
        $ikwoAgent = Agent::factory()->assignedTo('21802700001')->create(['name' => 'Ikwo Agent']);

        $recorder = app(ElectionRecorder::class);
        $this->abakaliki = $recorder->submitResult($this->agent, self::PU, 300, ['APC' => 150, 'PDP' => 100, 'LP' => 20], 5);
        $this->ikwo = $recorder->submitResult($ikwoAgent, '21802700001', 400, ['APC' => 50, 'PDP' => 200, 'LP' => 100], 10);
        $recorder->logIncident($this->agent, self::PU, IncidentType::Violence, 'Fight');
        $recorder->logIncident($ikwoAgent, '21802700001', IncidentType::Malpractice, 'Sheet swapped');
        $recorder->confirmPresence($ikwoAgent, '21802700001');
        $recorder->reportMaterials($this->agent, self::PU, MaterialStatus::NotArrived);
        $recorder->reportMaterials($this->agent, self::PU, MaterialStatus::Arrived);
    }

    public function test_requires_the_api_token(): void
    {
        $this->getJson('/api/results')->assertUnauthorized();
        $this->getJson('/api/results', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    }

    public function test_results_in_webhook_shape_with_filters(): void
    {
        $this->getJson('/api/results', $this->headers)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.reference', $this->abakaliki->reference)
            ->assertJsonPath('data.0.votes.APC', 150)
            ->assertJsonPath('data.0.polling_unit.lga', 'Abakaliki')
            ->assertJsonPath('data.0.rehearsal', null)
            ->assertJsonPath('rehearsal_mode', false)
            ->assertJsonStructure(['data' => [['reference', 'status', 'polling_unit', 'votes', 'agent', 'created_at', 'updated_at']], 'next_cursor', 'server_time']);

        $this->getJson('/api/results?lga=Ikwo', $this->headers)->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $this->ikwo->reference);
        $this->getJson('/api/results?polling_unit=EB/218/02700/001', $this->headers)->assertJsonCount(1, 'data');
        $this->getJson('/api/results?status=pending', $this->headers)->assertJsonCount(0, 'data');
        $this->getJson("/api/results/{$this->ikwo->reference}", $this->headers)->assertJsonPath('data.votes.PDP', 200);
        $this->getJson('/api/results/RS000000', $this->headers)->assertNotFound();
    }

    public function test_updated_since_picks_up_status_changes(): void
    {
        $this->travel(10)->minutes();
        $since = now()->toIso8601String();
        $this->travel(1)->minutes();

        $correction = app(ElectionRecorder::class)->submitResult($this->agent, self::PU, 300, ['APC' => 160, 'PDP' => 100, 'LP' => 20], 5, correction: true);
        app(CorrectionReviewer::class)->approve($correction, 'Admin');

        $references = collect($this->getJson('/api/results?updated_since='.urlencode($since), $this->headers)->json('data'))
            ->pluck('status', 'reference');

        // The superseded original and the approved correction both come back.
        $this->assertSame([$this->abakaliki->reference => 'superseded', $correction->reference => 'accepted'], $references->all());
    }

    public function test_cursor_pagination(): void
    {
        $first = $this->getJson('/api/results?per_page=1', $this->headers)->assertJsonCount(1, 'data');
        $cursor = $first->json('next_cursor');
        $this->assertNotNull($cursor);

        $second = $this->getJson('/api/results?per_page=1&cursor='.$cursor, $this->headers)->assertJsonCount(1, 'data');
        $this->assertNotSame($first->json('data.0.reference'), $second->json('data.0.reference'));
        $this->assertNull($second->json('next_cursor'));

        $this->getJson('/api/results?per_page=501', $this->headers)->assertUnprocessable();
    }

    public function test_incidents_presences_materials_units_agents(): void
    {
        $this->getJson('/api/incidents?type=malpractice', $this->headers)->assertJsonCount(1, 'data')->assertJsonPath('data.0.urgent', true);
        $this->getJson('/api/presences', $this->headers)->assertJsonCount(1, 'data')->assertJsonPath('data.0.polling_unit.code', '21802700001');

        $this->getJson('/api/materials', $this->headers)->assertJsonCount(2, 'data');
        $this->getJson('/api/materials?latest=1', $this->headers)->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'arrived');

        $this->getJson('/api/polling-units?lga=Ikwo', $this->headers)->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ikwo Town Hall 001');

        $agents = $this->getJson('/api/agents', $this->headers)->assertJsonCount(2, 'data')->json('data');
        $this->assertArrayNotHasKey('pin', $agents[0]);
        $this->getJson('/api/agents?lga=Ikwo', $this->headers)->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ikwo Agent');
    }
}
