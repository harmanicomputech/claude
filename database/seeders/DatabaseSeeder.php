<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Coordinator;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Loads the Ebonyi polling unit register plus demo agents (PIN 1234) and
     * a demo coordinator for trying the flow in the Africa's Talking
     * simulator. Remove the demo people before election day.
     */
    public function run(): void
    {
        Artisan::call('pu:import', ['file' => database_path('data/ebonyi_polling_units.csv')]);

        Agent::updateOrCreate(
            ['phone_number' => '+2348000000001'],
            ['name' => 'Demo Agent (any PU)', 'pin' => '1234'],
        );

        Agent::updateOrCreate(
            ['phone_number' => '+2348000000002'],
            ['name' => 'Demo Agent (assigned PU)', 'polling_unit_code' => '21202633002', 'pin' => '1234'],
        );

        Coordinator::updateOrCreate(
            ['phone_number' => '+2348000000009'],
            ['name' => 'Demo State Coordinator'],
        );
    }
}
