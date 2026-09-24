<?php

namespace Database\Seeders;

use App\Models\Agent;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Demo agents for trying the flow in the Africa's Talking simulator.
     */
    public function run(): void
    {
        Agent::updateOrCreate(
            ['phone_number' => '+2348000000001'],
            ['name' => 'Demo Agent (any PU)'],
        );

        Agent::updateOrCreate(
            ['phone_number' => '+2348000000002'],
            ['name' => 'Demo Agent (assigned PU)', 'polling_unit_code' => '02345'],
        );
    }
}
