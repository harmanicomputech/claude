<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Enums\MaterialStatus;
use App\Enums\VolunteerRole;
use App\Models\Agent;
use App\Models\Coordinator;
use App\Models\PollingUnit;
use App\Models\Result;
use App\Models\User;
use App\Services\ElectionRecorder;
use App\Services\PollingUnitRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Opens every page and download of the admin console (with common filters)
 * as each kind of account, on the real polling unit register with data in
 * it, and fails on any server error. New GET routes are picked up
 * automatically.
 */
class EveryPageLoadsTest extends TestCase
{
    use RefreshDatabase;

    private const QUERIES = [
        '', '?lga=Abakaliki', '?lga=Abakaliki&ward=Abakpa', '?lga=Nowhere&ward=Nowhere', '?q=ADAZI', '?q=110101001', '?q=%27%22%3C',
        '?status=pending', '?status=accepted', '?status=personnel_missing', '?status=not_arrived', '?status=bogus',
        '?type=ballot_snatching', '?type=violence', '?type=bogus', '?source=public', '?source=agent', '?view=missing', '?page=2', '?page=999', '?level=ward',
    ];

    private Result $result;

    private Result $correction;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        app(PollingUnitRegister::class)->import(database_path('data/ebonyi_polling_units.csv'));
        $recorder = app(ElectionRecorder::class);

        $assigned = Agent::factory()->assignedTo('110101001')->create(['name' => 'Assigned Agent']);
        $roaming = Agent::factory()->create(['name' => 'Roaming Agent']);
        Agent::factory()->assignedTo('110101002')->create(['name' => 'Silent Agent', 'pin' => null]);
        $ikwo = PollingUnit::query()->where('lga', 'Ikwo')->orderBy('code')->value('code');
        $ikwoAgent = Agent::factory()->assignedTo($ikwo)->create(['name' => 'Ikwo Agent']);

        $this->result = $recorder->submitResult($assigned, '110101001', 300, ['APC' => 150, 'PDP' => 100, 'LP' => 20], 5);
        $this->correction = $recorder->submitResult($assigned, '110101001', 310, ['APC' => 155, 'PDP' => 100, 'LP' => 20], 5, correction: true);
        $recorder->submitResult($roaming, '110101003', 200, ['APC' => 50, 'PDP' => 100, 'LP' => 20], 2);
        $recorder->submitResult($ikwoAgent, $ikwo, 250, ['APC' => 100, 'PDP' => 100, 'LP' => 20], 3);

        foreach ([[$assigned, '110101001'], [$roaming, '110101003'], [$ikwoAgent, $ikwo]] as [$agent, $code]) {
            $recorder->confirmPresence($agent, $code);
            $recorder->logIncident($agent, $code, IncidentType::BallotSnatching, 'Box taken <script>x</script>');
            $recorder->logIncident($agent, $code, IncidentType::VoteBuying, 'An older type');
        }
        $recorder->reportMaterials($assigned, '110101001', MaterialStatus::Arrived, personnelArrived: true);
        $recorder->reportMaterials($roaming, '110101003', MaterialStatus::Incomplete);
        $recorder->reportMaterials($ikwoAgent, $ikwo, MaterialStatus::NotArrived, personnelArrived: false);
        $recorder->logPublicIncident('+2348099999999', 'Abakaliki', 'Abakpa', null, IncidentType::Intimidation, 'Thugs');
        $recorder->logPublicIncident('+2348099999998', 'Ikwo', 'Ikwo Ward 01', null, IncidentType::Violence, 'Fight');
        $recorder->registerVolunteer('+2348099999999', '+2348099999999', 'Ada Volunteer', 'Abakaliki', 'Abakpa', [VolunteerRole::Canvass, VolunteerRole::Professional], ['legal'], 'Driving', false);
        Coordinator::create(['name' => 'Abakaliki Lead', 'phone_number' => '08020000001', 'lga' => 'Abakaliki']);
        Coordinator::create(['name' => 'State Lead', 'phone_number' => '08020000002']);
    }

    /**
     * @return array<string, User>
     */
    private function accounts(): array
    {
        return [
            'admin' => User::factory()->admin()->create(),
            'LGA coordinator' => User::factory()->create(['lga' => 'Abakaliki']),
            'ward coordinator' => User::factory()->create(['lga' => 'Abakaliki', 'ward' => 'Abakpa']),
            'coordinator without an area' => User::factory()->create(),
            'coordinator of an LGA with no data' => User::factory()->create(['lga' => 'Onicha']),
        ];
    }

    /**
     * @return list<string>
     */
    private function pages(): array
    {
        $fill = [
            'result' => $this->result->reference,
            'tab' => 'materials',
        ];
        $pages = [];

        foreach (Router::getRoutes() as $route) {
            /** @var Route $route */
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with($route->uri(), 'admin')) {
                continue;
            }
            $uri = preg_replace_callback('/\{(\w+)(?::\w+)?\??\}/', fn ($m) => $fill[$m[1]] ?? 'MISSING', $route->uri());
            $this->assertStringNotContainsString('MISSING', $uri, "No test value for a parameter of {$route->uri()}");
            $pages[] = '/'.$uri;
        }

        $pages[] = '/admin/field-reports/checkins/export';
        $pages[] = '/admin/results/'.$this->correction->reference;

        return $pages;
    }

    public function test_every_admin_page_loads_for_every_kind_of_account(): void
    {
        $pages = $this->pages();
        $this->assertGreaterThan(20, count($pages));
        $failures = [];

        foreach ($this->accounts() as $who => $user) {
            foreach ($pages as $page) {
                foreach (self::QUERIES as $query) {
                    $response = $this->actingAs($user)->get($page.$query);
                    $status = $response->getStatusCode();
                    if ($status >= 500) {
                        $failures[] = "{$who} {$page}{$query}: {$status} ".mb_substr((string) ($response->exception?->getMessage() ?? ''), 0, 200);
                    } elseif ($status === 200 && method_exists($response->baseResponse, 'getCallback') && $response->baseResponse->getCallback()) {
                        $response->streamedContent(); // run the download too
                    }
                }
            }
        }

        $this->assertSame([], $failures);
    }

    public function test_guest_pages_and_redirects(): void
    {
        $this->get('/')->assertRedirect('/admin');
        config(['election.admin_password' => 'setup-key']);
        $this->get('/admin/login')->assertOk()->assertSee('setup', false);
        User::factory()->admin()->create();
        $this->get('/admin/login')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/admin')->assertRedirect();
        $this->get('/admin/results')->assertRedirect();
        $this->get('/cron/wrong')->assertNotFound();
    }

    public function test_the_data_api_loads(): void
    {
        config(['election.api_token' => 'api-secret']);
        $failures = [];
        foreach (['/api/agents', '/api/corrections', '/api/incidents', '/api/materials', '/api/materials?latest=1', '/api/polling-units?lga=Ikwo', '/api/presences',
            '/api/reports/missing?type=presence', '/api/reports/missing?type=results&lga=Ikwo', '/api/reports/summary', '/api/results', '/api/results/'.$this->result->reference, '/api/volunteers', '/api/incidents?type=ballot_snatching'] as $url) {
            $status = $this->getJson($url, ['Authorization' => 'Bearer api-secret'])->getStatusCode();
            $status === 200 || $failures[] = "{$url}: {$status}";
        }
        $this->assertSame([], $failures);
    }
}
