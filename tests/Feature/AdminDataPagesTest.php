<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Models\Agent;
use App\Models\PollingUnit;
use App\Models\Result;
use App\Models\User;
use App\Services\ElectionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

class AdminDataPagesTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    private Result $abakaliki;

    private Result $ikwo;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['election.admin_password' => 'correct-horse']);
        $this->setUpUssd();

        PollingUnit::factory()->create(['code' => '21802700001', 'name' => 'Ikwo Town Hall 001', 'ward' => 'Ikwo Ward 01', 'lga' => 'Ikwo', 'registered_voters' => 800]);
        PollingUnit::factory()->create(['code' => '21802700002', 'name' => 'Ikwo Market 002', 'ward' => 'Ikwo Ward 01', 'lga' => 'Ikwo', 'registered_voters' => 500]);

        $recorder = app(ElectionRecorder::class);
        $this->abakaliki = $recorder->submitResult($this->agent, self::PU, 300, ['APC' => 150, 'PDP' => 100, 'LP' => 20], 5);
        $ikwoAgent = Agent::factory()->assignedTo('21802700001')->create(['name' => 'Ikwo Agent']);
        $this->ikwo = $recorder->submitResult($ikwoAgent, '21802700001', 400, ['APC' => 50, 'PDP' => 200, 'LP' => 100], 10);
        $recorder->confirmPresence($ikwoAgent, '21802700001');
        $recorder->logIncident($this->agent, self::PU, IncidentType::Violence, 'Thugs at PU');
        $recorder->logIncident($ikwoAgent, '21802700001', IncidentType::Delay, 'Late start');
    }

    private function admin(): static
    {
        return $this->actingAs(User::factory()->admin()->create());
    }

    /**
     * @return list<array<int, string>>
     */
    private function csv(TestResponse $response): array
    {
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        return array_map(fn ($line) => str_getcsv($line, escape: ''), array_filter(explode("\n", substr($content, 3))));
    }

    public function test_overview_cards_link_to_their_pages(): void
    {
        $this->admin()->get('/admin')->assertOk()
            ->assertSee(route('admin.polling-units.index'), false)
            ->assertSee(route('admin.agents.index', ['status' => 'checked_in']), false)
            ->assertSee(route('admin.results.index'), false)
            ->assertSee(route('admin.corrections.index'), false)
            ->assertSee(route('admin.incidents.index'), false);
    }

    public function test_results_page_shows_totals_collation_and_rows(): void
    {
        $this->admin()->get('/admin/results')->assertOk()
            ->assertSee('Votes by party')
            ->assertSee('Francis Ogbonna Nwifuru')
            ->assertSee('Collation by LGA')
            ->assertSee('Abakaliki')
            ->assertSee('Ikwo')
            ->assertSee($this->abakaliki->reference)
            ->assertSee($this->ikwo->reference)
            ->assertSee('620'); // total valid: 270 + 350
    }

    public function test_results_can_be_filtered_by_lga_and_search(): void
    {
        $this->admin()->get('/admin/results?lga=Ikwo')->assertOk()
            ->assertSee($this->ikwo->reference)
            ->assertDontSee($this->abakaliki->reference)
            ->assertSee('Collation by ward')
            ->assertSee('1 / 2'); // 1 of Ikwo's 2 PUs reported

        $this->admin()->get('/admin/results?q=Amachi')->assertSee($this->abakaliki->reference)->assertDontSee($this->ikwo->reference);
        $this->admin()->get('/admin/results?q='.$this->ikwo->reference)->assertSee($this->ikwo->reference)->assertDontSee($this->abakaliki->reference);
    }

    public function test_status_filter_includes_pending_corrections(): void
    {
        $correction = app(ElectionRecorder::class)->submitResult($this->agent, self::PU, 300, ['APC' => 160, 'PDP' => 100, 'LP' => 20], 5, correction: true);

        $this->admin()->get('/admin/results')->assertDontSee($correction->reference);
        $this->admin()->get('/admin/results?status=pending')->assertSee($correction->reference);
        $this->admin()->get('/admin/results?status=all')->assertSee($correction->reference)->assertSee($this->abakaliki->reference);
    }

    public function test_result_detail_page(): void
    {
        $this->admin()->get("/admin/results/{$this->abakaliki->reference}")->assertOk()
            ->assertSee('Result sheet (EC8A)')
            ->assertSee('Amachi Pry Sch')
            ->assertSee('30%') // turnout 300 / 1000
            ->assertSee('History for this polling unit');

        $this->admin()->get('/admin/results/RS000000')->assertNotFound();
    }

    public function test_results_export(): void
    {
        $rows = $this->csv($this->admin()->get('/admin/results/export?lga=Abakaliki'));

        $this->assertSame(['Reference', 'Status', 'PU code', 'Polling unit', 'Ward', 'LGA', 'Registered voters', 'Accredited voters', 'Turnout %', 'APC', 'PDP', 'LP'], array_slice($rows[0], 0, 12));
        $this->assertCount(2, $rows);
        $this->assertSame([$this->abakaliki->reference, 'accepted', self::PU, 'Amachi Pry Sch'], array_slice($rows[1], 0, 4));
        $this->assertSame(['150', '100', '20', '270', '5', '275'], array_slice($rows[1], 9, 6));
    }

    public function test_collation_export(): void
    {
        $rows = $this->csv($this->admin()->get('/admin/results/export-collation'));

        $this->assertSame(['Lga', 'PUs reported', 'PUs total', '% reported', 'APC', 'PDP', 'LP', 'Total valid', 'Rejected', 'Accredited'], $rows[0]);
        $ikwo = collect($rows)->firstWhere(0, 'Ikwo');
        $this->assertSame(['Ikwo', '1', '2', '50', '50', '200', '100', '350', '10', '400'], $ikwo);
    }

    public function test_incidents_page_filters_and_export(): void
    {
        $this->admin()->get('/admin/incidents')->assertOk()
            ->assertSee('Thugs at PU')
            ->assertSee('Late start')
            ->assertSee('Urgent: coordinators alerted');

        $this->admin()->get('/admin/incidents?type=delay')->assertSee('Late start')->assertDontSee('Thugs at PU');
        $this->admin()->get('/admin/incidents?lga=Abakaliki')->assertSee('Thugs at PU')->assertDontSee('Late start');

        $rows = $this->csv($this->admin()->get('/admin/incidents/export?type=violence'));
        $this->assertCount(2, $rows);
        $this->assertSame(['Violence', 'yes', 'Thugs at PU'], array_slice($rows[1], 1, 3));
    }

    public function test_csv_neutralises_formulas_but_keeps_phone_numbers(): void
    {
        app(ElectionRecorder::class)->logIncident($this->agent, self::PU, IncidentType::Other, '=HYPERLINK("x")');

        $rows = $this->csv($this->admin()->get('/admin/incidents/export?type=other'));

        $this->assertSame("'=HYPERLINK(\"x\")", $rows[1][3]);
        $this->assertSame('+2348011111111', $rows[1][9]);
    }

    public function test_polling_units_page_statuses_and_export(): void
    {
        $this->admin()->get('/admin/polling-units')->assertOk()
            ->assertSee('Result received')
            ->assertSee('Ikwo Market 002')
            ->assertSee($this->ikwo->reference);

        $this->admin()->get('/admin/polling-units?status=no_result')->assertSee('Ikwo Market 002')->assertDontSee('Ikwo Town Hall 001');
        $this->admin()->get('/admin/polling-units?status=checked_in')->assertSee('Ikwo Town Hall 001')->assertDontSee('Amachi Pry Sch');

        $rows = $this->csv($this->admin()->get('/admin/polling-units/export?lga=Ikwo'));
        $this->assertCount(3, $rows);
        $townHall = collect($rows)->firstWhere(0, '21802700001');
        $this->assertSame($this->ikwo->reference, $townHall[7]);
        $this->assertNotSame('', $townHall[6]); // checked in
    }

    public function test_agents_status_filter_and_export(): void
    {
        $this->admin()->get('/admin/agents?status=checked_in')->assertSee('Ikwo Agent')->assertDontSee('Ada Obi');
        $this->admin()->get('/admin/agents?status=not_checked_in')->assertSee('Ada Obi')->assertDontSee('Ikwo Agent');

        $rows = $this->csv($this->admin()->get('/admin/agents/export'));
        $ada = collect($rows)->firstWhere(0, 'Ada Obi');
        $this->assertSame(['Ada Obi', '+2348011111111'], array_slice($ada, 0, 2));
        $this->assertSame(['1', '1', 'no'], array_slice($ada, 7, 3)); // 1 result, 1 incident, not locked
        $this->assertStringNotContainsString('1234', implode(',', $ada)); // never the PIN
    }

    public function test_corrections_export(): void
    {
        $correction = app(ElectionRecorder::class)->submitResult($this->agent, self::PU, 300, ['APC' => 160, 'PDP' => 100, 'LP' => 20], 5, correction: true);

        $rows = $this->csv($this->admin()->get('/admin/corrections/export'));

        $this->assertSame(['Reference', 'Decision', 'PU code', 'Polling unit', 'LGA', 'Replaces', 'APC before', 'APC proposed'], array_slice($rows[0], 0, 8));
        $this->assertSame([$correction->reference, 'pending', self::PU, 'Amachi Pry Sch', 'Abakaliki', $this->abakaliki->reference, '150', '160'], array_slice($rows[1], 0, 8));
    }
}
