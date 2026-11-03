<?php

namespace Tests\Feature;

use App\Enums\IncidentType;
use App\Enums\MaterialStatus;
use App\Models\Agent;
use App\Models\PollingUnit;
use App\Models\Result;
use App\Models\User;
use App\Services\ElectionRecorder;
use App\Support\Area;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

/**
 * Coordinators see only their LGA or ward in the admin console; admins see
 * everything. Also the Check-ins and Materials pages.
 */
class AreaScopingTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    private Result $ikwoResult;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->setUpUssd(); // Amachi Pry Sch: Abakaliki / Amachi Ward

        PollingUnit::factory()->create(['code' => '110102001', 'name' => 'Azuiyiokwu Town Hall', 'ward' => 'Azuiyiokwu', 'lga' => 'Abakaliki', 'registered_voters' => 700]);
        PollingUnit::factory()->create(['code' => '150101001', 'name' => 'Ikwo Market Square', 'ward' => 'Ikwo Ward 01', 'lga' => 'Ikwo', 'registered_voters' => 800]);

        $recorder = app(ElectionRecorder::class);
        $agents = [
            self::PU => $this->agent,
            '110102001' => Agent::factory()->assignedTo('110102001')->create(['name' => 'Azu Agent']),
            '150101001' => Agent::factory()->assignedTo('150101001')->create(['name' => 'Ikwo Agent']),
        ];
        foreach ($agents as $code => $agent) {
            $result = $recorder->submitResult($agent, $code, 300, ['APC' => 150, 'PDP' => 100, 'LP' => 20], 5);
            $recorder->confirmPresence($agent, $code);
            $recorder->logIncident($agent, $code, IncidentType::Violence, "Trouble at {$code}");
        }
        $this->ikwoResult = $result;

        $recorder->reportMaterials($agents[self::PU], self::PU, MaterialStatus::Arrived, personnelArrived: true);
        $recorder->reportMaterials($agents['110102001'], '110102001', MaterialStatus::Arrived, personnelArrived: false);
        $recorder->reportMaterials($agents['150101001'], '150101001', MaterialStatus::NotArrived, personnelArrived: false);
    }

    private function user(?string $lga = null, ?string $ward = null): User
    {
        return User::factory()->create(['lga' => $lga, 'ward' => $ward]);
    }

    public function test_admins_see_every_lga(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/results')->assertOk()->assertSee('Amachi Pry Sch')->assertSee('Ikwo Market Square');
        $this->get('/admin/incidents')->assertOk()->assertSee('Trouble at 110101001')->assertSee('Trouble at 150101001');
        $this->get('/admin/check-ins')->assertOk()->assertSee('Azuiyiokwu Town Hall')->assertSee('Ikwo Market Square');
        $this->get('/admin/agents')->assertOk()->assertSee('Ikwo Agent');
        $this->get('/admin/results/'.$this->ikwoResult->reference)->assertOk();
    }

    public function test_an_lga_coordinator_sees_only_their_lga(): void
    {
        $this->actingAs($this->user('Abakaliki'));

        $this->get('/admin/results')->assertOk()->assertSee('Amachi Pry Sch')->assertSee('Azuiyiokwu Town Hall')->assertDontSee('Ikwo Market Square');
        $this->get('/admin/incidents')->assertOk()->assertSee('Trouble at 110102001')->assertDontSee('Trouble at 150101001');
        $this->get('/admin/check-ins')->assertOk()->assertSee('Azuiyiokwu Town Hall')->assertDontSee('Ikwo Market Square');
        $this->get('/admin/materials')->assertOk()->assertSee('Amachi Pry Sch')->assertDontSee('Ikwo Market Square');
        // Agents assigned to a PU here, and unassigned agents who reported from one (Ada Obi).
        $this->get('/admin/agents')->assertOk()->assertSee('Azu Agent')->assertSee('Ada Obi')->assertDontSee('Ikwo Agent');
        $this->get('/admin/polling-units')->assertOk()->assertSee('Azuiyiokwu Town Hall')->assertDontSee('Ikwo Market Square');
        $this->get('/admin')->assertOk()->assertSee('Abakaliki LGA');

        // Records from another LGA are not found, even by their address.
        $this->get('/admin/results/'.$this->ikwoResult->reference)->assertNotFound();

        $csv = $this->get('/admin/results/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('110101001', $csv);
        $this->assertStringNotContainsString('150101001', $csv);
    }

    public function test_a_ward_coordinator_sees_only_their_ward(): void
    {
        $this->actingAs($this->user('Abakaliki', 'Amachi Ward'));

        $this->get('/admin/results')->assertOk()->assertSee('Amachi Pry Sch')->assertDontSee('Azuiyiokwu Town Hall')->assertDontSee('Ikwo Market Square');
        $this->get('/admin/check-ins')->assertOk()->assertSee('Amachi Pry Sch')->assertDontSee('Azuiyiokwu Town Hall');
        $this->get('/admin/incidents')->assertOk()->assertSee('Trouble at 110101001')->assertDontSee('Trouble at 110102001');
        $this->get('/admin')->assertOk()->assertSee('Amachi Ward');
    }

    public function test_a_coordinator_without_an_area_sees_nothing(): void
    {
        $this->actingAs($this->user());

        $this->get('/admin')->assertOk()->assertSee('No LGA has been assigned to your account yet');
        $this->get('/admin/results')->assertOk()->assertDontSee('Amachi Pry Sch')->assertDontSee('Ikwo Market Square');
        $this->get('/admin/agents')->assertOk()->assertDontSee('Ikwo Agent');
    }

    public function test_the_area_is_cleared_after_each_request(): void
    {
        $this->actingAs($this->user('Ikwo'))->get('/admin/results')->assertOk();

        $this->assertFalse(Area::restricted());
        $this->assertSame(3, Result::count());
    }

    public function test_admins_give_coordinators_an_lga_or_ward(): void
    {
        $coordinator = $this->user();
        $this->actingAs(User::factory()->admin()->create());

        $this->patch("/admin/users/{$coordinator->id}", ['role' => 'coordinator', 'area' => 'Abakaliki|Amachi Ward'])->assertSessionHas('status');
        $this->assertSame(['Abakaliki', 'Amachi Ward'], [$coordinator->refresh()->lga, $coordinator->ward]);

        $this->patch("/admin/users/{$coordinator->id}", ['role' => 'coordinator', 'area' => 'Ikwo|'])->assertSessionHas('status');
        $this->assertSame(['Ikwo', null], [$coordinator->refresh()->lga, $coordinator->ward]);

        // Places not in the register are not saved.
        $this->patch("/admin/users/{$coordinator->id}", ['role' => 'coordinator', 'area' => 'Ikwo|Amachi Ward']);
        $this->assertNull($coordinator->refresh()->lga);
    }

    public function test_materials_page_shows_personnel_and_filters(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/materials')->assertOk()
            ->assertSee('Personnel arrived')
            ->assertSee('No personnel yet');
        $this->get('/admin/materials?status=personnel_missing')->assertOk()
            ->assertSee('Azuiyiokwu Town Hall')->assertSee('Ikwo Market Square')->assertDontSee('Amachi Pry Sch');
        $this->get('/admin/materials?status=not_arrived')->assertOk()
            ->assertSee('Ikwo Market Square')->assertDontSee('Azuiyiokwu Town Hall');

        $csv = $this->get('/admin/field-reports/materials/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Personnel', $csv);
        $this->assertStringContainsString('No personnel yet', $csv);

        $csv = $this->get('/admin/field-reports/checkins/export?lga=Ikwo')->assertOk()->streamedContent();
        $this->assertStringContainsString('150101001', $csv);
        $this->assertStringNotContainsString('110101001', $csv);
    }

    public function test_the_web_app_api_takes_personnel(): void
    {
        config(['election.api_token' => 'api-secret']);
        $this->agent->update(['polling_unit_code' => self::PU]);

        $this->postJson('/api/field/materials', ['phone_number' => '+2348011111111', 'status' => 'arrived', 'personnel_arrived' => false], ['Authorization' => 'Bearer api-secret'])
            ->assertCreated()
            ->assertJsonPath('materials.status', 'arrived')
            ->assertJsonPath('materials.personnel_arrived', false)
            ->assertJsonPath('materials.personnel_label', 'No personnel yet');
    }
}
