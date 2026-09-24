<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Coordinator;
use App\Models\PollingUnit;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Demo data for trying the flow in the Africa's Talking simulator.
     * Demo agent PIN: 1234. Replace the PUs with the real INEC register
     * using `php artisan pu:import`.
     */
    public function run(): void
    {
        $units = [
            ['110101001', 'Demo Pry Sch I', 'Demo Ward 1', 'Abakaliki', 750],
            ['110101002', 'Demo Town Hall', 'Demo Ward 1', 'Abakaliki', 620],
            ['110201001', 'Demo Village Square', 'Demo Ward 2', 'Afikpo North', 540],
        ];

        foreach ($units as [$code, $name, $ward, $lga, $registered]) {
            PollingUnit::updateOrCreate(['code' => $code], [
                'name' => $name, 'ward' => $ward, 'lga' => $lga, 'registered_voters' => $registered,
            ]);
        }

        Agent::updateOrCreate(
            ['phone_number' => '+2348000000001'],
            ['name' => 'Demo Agent (any PU)', 'pin' => '1234'],
        );

        Agent::updateOrCreate(
            ['phone_number' => '+2348000000002'],
            ['name' => 'Demo Agent (assigned PU)', 'polling_unit_code' => '110101002', 'pin' => '1234'],
        );

        Coordinator::updateOrCreate(
            ['phone_number' => '+2348000000009'],
            ['name' => 'Demo State Coordinator'],
        );
    }
}
