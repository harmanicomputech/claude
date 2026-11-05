<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\Result;
use App\Models\User;
use App\Services\AgentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithUssd;
use Tests\TestCase;

/**
 * Agents registered for a ward (from the ward lists): stored with their LGA
 * and ward, shown to that area's coordinators, and asked on USSD only for
 * the PU number in their ward.
 */
class AgentWardTest extends TestCase
{
    use InteractsWithUssd, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->setUpUssd(); // 110101001 Amachi Pry Sch, Abakaliki / Amachi Ward

        PollingUnit::factory()->create(['code' => '110101002', 'name' => 'Amachi Market', 'ward' => 'Amachi Ward', 'lga' => 'Abakaliki']);
        // "Abakpa" is a ward name in two LGAs.
        PollingUnit::factory()->create(['code' => '110102001', 'name' => 'Adazi-Enu Hall', 'ward' => 'Abakpa', 'lga' => 'Abakaliki']);
        PollingUnit::factory()->create(['code' => '120101007', 'name' => 'Abakpa Town Hall', 'ward' => 'Abakpa', 'lga' => 'Ebonyi']);
        PollingUnit::factory()->create(['code' => '150101001', 'name' => 'Ikwo Market', 'ward' => 'Ikwo Ward 01', 'lga' => 'Ikwo']);
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'agents');
        file_put_contents($path, $content);

        return $path;
    }

    public function test_importing_agents_with_their_ward(): void
    {
        $report = app(AgentImporter::class)->import($this->csv(
            "name,phone,lga,ward\n"
            ."Ada Ward,08031110001,Abakaliki,amachi ward\n"   // any capitals: stored as in the register
            ."Bayo Abakpa,08031110002,Ebonyi,Abakpa\n"
            ."Chika Nolga,08031110003,,Abakpa\n"             // Abakpa is in two LGAs
            ."Dayo Nowhere,08031110004,Ikwo,Atlantis\n"
            ."Ebere Ikwo,08031110005,,Ikwo Ward 01\n"        // unique ward name: LGA not needed
        ));

        $this->assertCount(3, $report['imported']);
        $this->assertSame(['Abakaliki', 'Amachi Ward'], [$report['imported'][0]['lga'], $report['imported'][0]['ward']]);
        $this->assertNotNull($report['imported'][0]['pin']);
        $this->assertSame('Ebonyi', Agent::findByPhone('08031110002')->lga);
        $this->assertSame('Ikwo', Agent::findByPhone('08031110005')->lga);
        $this->assertStringContainsString('Line 4: Ward Abakpa is in more than one LGA (Abakaliki, Ebonyi)', implode("\n", $report['errors']));
        $this->assertStringContainsString('Line 5: Unknown ward: Atlantis in Ikwo LGA', implode("\n", $report['errors']));
        $this->assertNull(Agent::findByPhone('08031110003'));
    }

    public function test_a_six_digit_pin_from_the_phone_number_works_on_ussd(): void
    {
        // The ward lists: each agent's PIN is the last 6 digits of their phone.
        $report = app(AgentImporter::class)->import($this->csv("name,phone,lga,ward,pin\nSix Pin,08031234567,Abakaliki,Amachi Ward,234567\nSeven,08031110010,Abakaliki,Amachi Ward,1234567\n"));
        $this->assertSame('234567', $report['imported'][0]['pin']);
        $this->assertStringContainsString('PIN must be 4 to 6 digits', implode(' ', $report['errors']));

        $agent = Agent::findByPhone('08031234567');
        $this->assertTrue($agent->pinMatches('234567'));
        $this->ussd('5*001*300*120*80*20*5*1*234567', $agent)->assertSee('END Submitted', false);
    }

    public function test_an_assigned_pu_sets_the_ward_and_a_reimport_keeps_the_pin(): void
    {
        $report = app(AgentImporter::class)->import($this->csv("name,phone,pu_code\nPu Agent,08031110006,11/01/01/002\n"));
        $agent = Agent::findByPhone('08031110006');
        $this->assertSame(['Abakaliki', 'Amachi Ward', '110101002'], [$agent->lga, $agent->ward, $agent->polling_unit_code]);

        $pin = $report['imported'][0]['pin'];
        app(AgentImporter::class)->import($this->csv("name,phone,lga,ward\nPu Agent,08031110006,Ebonyi,Abakpa\n"));
        $agent->refresh();
        $this->assertSame(['Ebonyi', 'Abakpa', null], [$agent->lga, $agent->ward, $agent->polling_unit_code]);
        $this->assertTrue($agent->pinMatches($pin));
    }

    public function test_ussd_asks_only_for_the_pu_number_in_the_agents_ward(): void
    {
        $this->agent->update(['lga' => 'Abakaliki', 'ward' => 'Amachi Ward']);
        $prompt = "Amachi Ward\nPU number: last 3 digits\nof the PU code (e.g. 007):\n0. Other ward";

        $this->ussd('5')->assertContent("CON {$prompt}");
        $this->ussd('5*001')->assertContent("CON Amachi Pry Sch\nAccredited Voters:");
        $this->ussd('5*001*300*120*80*20*5*1*'.self::PIN)->assertSee('END Submitted', false);
        $this->assertSame('110101001', Result::sole()->polling_unit_code);

        $this->ussd('1')->assertContent("CON {$prompt}");
        $this->ussd('1*002')->assertContent("END Presence Confirmed ✔\nAmachi Market");
        $this->assertSame('110101002', Presence::sole()->polling_unit_code);

        $this->ussd('2*001')->assertSee('Material Status:', false);
        $this->ussd('4*1')->assertContent("CON {$prompt}");
        $this->ussd('4*1*002')->assertContent('CON Short Note:');

        // A PU number that isn't in the ward, then another ward altogether.
        $this->ussd('1*009')->assertContent("CON No PU 009 in this ward.\n{$prompt}");
        $this->ussd('1*0')->assertSee("CON Your PU's LGA:\n1.Abakaliki", false);
        $this->ussd('1*0*3*1*001')->assertContent("END Presence Confirmed ✔\nIkwo Market");
    }

    public function test_agents_assigned_to_a_pu_still_skip_the_pu_entirely(): void
    {
        $this->agent->update(['polling_unit_code' => '110101001', 'lga' => 'Abakaliki', 'ward' => 'Amachi Ward']);

        $this->ussd('5')->assertContent("CON Amachi Pry Sch\nAccredited Voters:");
    }

    public function test_the_ward_prompt_fits_the_screen(): void
    {
        PollingUnit::factory()->create(['code' => '111401001', 'name' => 'Long', 'ward' => 'Ndieze Inyimagu II Ndiabor Ishiagu', 'lga' => 'Izzi']);
        $this->agent->update(['lga' => 'Izzi', 'ward' => 'Ndieze Inyimagu II Ndiabor Ishiagu']);

        foreach (['5', '5*999', '5*abc', '1', '2', '4*1'] as $text) {
            $this->assertLessThanOrEqual(182, mb_strlen($this->ussd($text)->getContent()), $text);
        }
    }

    public function test_coordinators_see_the_agents_of_their_ward(): void
    {
        Agent::factory()->create(['name' => 'Ward Agent', 'lga' => 'Abakaliki', 'ward' => 'Amachi Ward']);
        Agent::factory()->create(['name' => 'Ikwo Ward Agent', 'lga' => 'Ikwo', 'ward' => 'Ikwo Ward 01']);

        $this->actingAs(User::factory()->create(['lga' => 'Abakaliki', 'ward' => 'Amachi Ward']))
            ->get('/admin/agents')->assertOk()->assertSee('Ward Agent')->assertDontSee('Ikwo Ward Agent');
        $this->actingAs(User::factory()->create(['lga' => 'Ikwo']))
            ->get('/admin/agents')->assertOk()->assertSee('Ikwo Ward Agent')->assertDontSee('>Ward Agent<', false);
    }

    public function test_admins_add_an_agent_with_a_ward_and_print_cards(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/admin/agents', ['name' => 'Form Agent', 'phone' => '08031110007', 'area' => 'Ebonyi|Abakpa'])->assertSessionHas('status');
        $this->assertSame(['Ebonyi', 'Abakpa'], [Agent::findByPhone('08031110007')->lga, Agent::findByPhone('08031110007')->ward]);

        $this->get('/admin/agents')->assertOk()->assertSee('Abakpa');
        $this->get('/admin/agents/cards?lga=Ebonyi')->assertOk()->assertSee('Form Agent')->assertSee('type the PU number');
    }

    public function test_the_api_gives_each_agents_ward(): void
    {
        config(['election.api_token' => 'api-secret']);
        Agent::factory()->create(['name' => 'Ward Agent', 'lga' => 'Ebonyi', 'ward' => 'Abakpa']);
        $headers = ['Authorization' => 'Bearer api-secret'];

        $this->getJson('/api/agents?lga=Ebonyi', $headers)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ward', 'Abakpa')->assertJsonPath('data.0.lga', 'Ebonyi');
        $this->getJson('/api/agents?ward=Abakpa', $headers)->assertJsonCount(1, 'data');

        $this->postJson('/api/agents', ['name' => 'Web Agent', 'phone_number' => '08031110008', 'ward' => 'Ikwo Ward 01'], $headers)
            ->assertCreated()->assertJsonPath('agent.lga', 'Ikwo')->assertJsonPath('agent.ward', 'Ikwo Ward 01');
        $this->postJson('/api/agents', ['name' => 'Bad', 'phone_number' => '08031110009', 'ward' => 'Abakpa'], $headers)->assertStatus(422);
    }
}
