<?php

namespace Tests\Feature;

use App\Models\PollingUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A live service without a polling unit register: USSD says so instead of
 * showing an empty LGA list, and the next database update loads the register.
 */
class EmptyRegisterTest extends TestCase
{
    use RefreshDatabase;

    private function dial(string $text): string
    {
        return $this->post('/api/ussd', ['sessionId' => 's', 'serviceCode' => '*1#', 'phoneNumber' => '+2348099990000', 'text' => $text])->assertOk()->getContent();
    }

    public function test_ussd_says_the_service_is_being_set_up_instead_of_an_empty_list(): void
    {
        $this->assertSame(0, PollingUnit::count());

        $this->assertSame("END Service is being set up.\nPlease try again later.", $this->dial('1*1'));   // report an incident
        $this->assertSame("END Service is being set up.\nPlease try again later.", $this->dial('2*1'));   // how can you help
    }

    public function test_the_database_update_loads_the_register_when_it_is_empty(): void
    {
        $migration = require database_path('migrations/2026_11_20_000001_load_polling_unit_register_if_empty.php');
        $this->app['env'] = 'production';

        $migration->up();
        $this->assertSame(2940, PollingUnit::count());
        $this->assertStringStartsWith("CON Where? Choose LGA:\n1.Abakaliki", $this->dial('1*1'));

        // A register that already has rows is left alone.
        PollingUnit::query()->where('code', '110101001')->update(['name' => 'Renamed']);
        $migration->up();
        $this->assertSame('Renamed', PollingUnit::query()->where('code', '110101001')->value('name'));
        $this->app['env'] = 'testing';
    }
}
