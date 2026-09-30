<?php

namespace Tests\Feature;

use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\PollingUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pu');
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_pu_import_normalises_inec_codes_and_upserts(): void
    {
        $path = $this->csv("code,name,ward,lga,registered_voters\n11-05-03-004,Amachi Pry Sch,Amachi,Abakaliki,812\n11/02/01/001,Town Hall,Ward 1,Ikwo,\n");

        $this->artisan('pu:import', ['file' => $path])->expectsOutputToContain('Imported 2')->assertSuccessful();

        $unit = PollingUnit::findByCode('110503004');
        $this->assertSame('Amachi Pry Sch', $unit->name);
        $this->assertSame(812, $unit->registered_voters);
        $this->assertNull(PollingUnit::findByCode('110201001')->registered_voters);

        $this->artisan('pu:import', ['file' => $this->csv("code,name,ward,lga\n110503004,Amachi Primary School,Amachi,Abakaliki\n")])
            ->assertSuccessful();
        $this->assertSame('Amachi Primary School', PollingUnit::findByCode('110503004')->name);
        $this->assertSame(2, PollingUnit::count());
    }

    public function test_the_ebonyi_register_imports_cleanly(): void
    {
        $this->artisan('pu:import', ['file' => database_path('data/ebonyi_polling_units.csv')])
            ->expectsOutputToContain('Imported 2940 polling unit(s); 0 row(s) skipped.')
            ->assertSuccessful();

        $this->assertSame(13, PollingUnit::distinct()->count('lga'));
        $this->assertSame(171, PollingUnit::select('lga', 'ward')->distinct()->get()->count());

        $unit = PollingUnit::findByCode('11/01/01/007');
        $this->assertSame('110101007', $unit->code);
        $this->assertSame('VANCO HOTEL SPACE I', $unit->shortName());
        $this->assertSame('Abakpa', $unit->ward);
        $this->assertSame('Abakaliki', $unit->lga);
    }

    public function test_pu_import_keeps_voter_numbers_when_the_file_has_none(): void
    {
        PollingUnit::factory()->create(['code' => '110101001', 'name' => 'Old name', 'registered_voters' => 640]);

        $this->artisan('pu:import', ['file' => $this->csv('code,name,ward,lga,registered_voters
11/01/01/001,ADAZI-ENU HALL I,Abakpa,Abakaliki,
')])->assertSuccessful();

        $unit = PollingUnit::findByCode('110101001');
        $this->assertSame('ADAZI-ENU HALL I', $unit->name);
        $this->assertSame(640, $unit->registered_voters);
    }

    public function test_pu_import_can_replace_the_register(): void
    {
        PollingUnit::factory()->create(['code' => '21202633007']);
        $kept = Agent::factory()->create(['name' => 'Kept', 'polling_unit_code' => '110101001']);
        $moved = Agent::factory()->create(['name' => 'Moved', 'polling_unit_code' => '21202633007']);

        $this->artisan('pu:import', ['file' => $this->csv('code,name,ward,lga
11/01/01/001,ADAZI-ENU HALL I,Abakpa,Abakaliki
'), '--replace' => true])
            ->expectsOutputToContain('1 removed; 1 agent(s) unassigned')
            ->assertSuccessful();

        $this->assertSame(['110101001'], PollingUnit::pluck('code')->all());
        $this->assertSame('110101001', $kept->fresh()->polling_unit_code);
        $this->assertNull($moved->fresh()->polling_unit_code);

        // A file with a bad row never replaces anything.
        $this->artisan('pu:import', ['file' => $this->csv('code,name,ward,lga
x,,,
'), '--replace' => true])->assertFailed();
        $this->assertSame(1, PollingUnit::count());
    }

    public function test_the_made_up_register_is_swapped_for_inecs_on_update(): void
    {
        PollingUnit::factory()->create(['code' => '21202633007']);
        $agent = Agent::factory()->create(['polling_unit_code' => '21202633007']);

        $migration = require database_path('migrations/2026_10_01_000001_use_inec_polling_unit_register.php');
        $migration->up();

        $this->assertSame(2940, PollingUnit::count());
        $this->assertNull(PollingUnit::findByCode('21202633007'));
        $this->assertNull($agent->fresh()->polling_unit_code);
        $this->assertDatabaseHas('audit_logs', ['action' => 'polling_unit.register_replaced']);

        // Running again (or on a database already on INEC's codes) does nothing.
        PollingUnit::findByCode('110101001')->update(['registered_voters' => 500]);
        $migration->up();
        $this->assertSame(500, PollingUnit::findByCode('110101001')->registered_voters);
    }

    public function test_pu_import_reports_bad_rows_and_missing_columns(): void
    {
        $this->artisan('pu:import', ['file' => $this->csv("code,name\n1,x\n")])
            ->expectsOutputToContain('missing column(s): ward, lga')
            ->assertFailed();

        $this->artisan('pu:import', ['file' => $this->csv("code,name,ward,lga\nabc,Bad,W,L\n110503004,Good,W,L\n")])
            ->expectsOutputToContain('Line 2')
            ->assertFailed();

        $this->assertSame(1, PollingUnit::count());
    }

    public function test_agent_add_generates_and_texts_a_pin(): void
    {
        Queue::fake();
        PollingUnit::factory()->create(['code' => '110503004']);

        $this->artisan('agent:add', ['phone' => '08012345678', 'name' => 'Ada Obi', '--pu' => '11-05-03-004', '--sms-pin' => true])
            ->expectsOutputToContain('assigned to PU 110503004')
            ->assertSuccessful();

        $agent = Agent::findByPhone('+2348012345678');
        $this->assertTrue($agent->hasPin());

        Queue::assertPushed(SendSms::class, function (SendSms $job) use ($agent) {
            preg_match('/PIN is (\d{4})/', $job->message, $match);

            return $job->to === $agent->phone_number && $agent->pinMatches($match[1]);
        });
    }

    public function test_agent_add_keeps_existing_pin_on_update(): void
    {
        $agent = Agent::factory()->create(['phone_number' => '+2348012345678', 'pin' => '4321']);

        $this->artisan('agent:add', ['phone' => '08012345678', 'name' => 'Renamed'])
            ->doesntExpectOutputToContain('PIN:')
            ->assertSuccessful();

        $this->assertTrue($agent->refresh()->pinMatches('4321'));
        $this->assertSame('Renamed', $agent->name);
    }

    public function test_agent_add_rejects_unknown_pu(): void
    {
        $this->artisan('agent:add', ['phone' => '08012345678', 'name' => 'Ada', '--pu' => '119999999'])
            ->expectsOutputToContain('Unknown or invalid PU code')
            ->assertFailed();
    }

    public function test_agent_import_command(): void
    {
        Queue::fake();
        PollingUnit::factory()->create(['code' => '21202633007']);
        PollingUnit::factory()->create(['code' => '21202633002']);

        $this->artisan('agent:import', ['file' => database_path('data/agents.example.csv'), '--sms-pins' => true])
            ->expectsOutputToContain('3 agent(s) imported; 0 row(s) skipped.')
            ->assertSuccessful();

        $this->assertSame('21202633007', Agent::findByPhone('08012345678')->polling_unit_code);
        $this->assertTrue(Agent::findByPhone('08023456789')->pinMatches('4821'));
        Queue::assertPushed(SendSms::class, 3);
    }
}
