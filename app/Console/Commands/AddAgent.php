<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

class AddAgent extends Command
{
    protected $signature = 'agent:add
        {phone : Agent phone number, e.g. 08012345678 or +2348012345678}
        {name : Agent full name}
        {--pu= : Assigned polling unit code (skips PU entry on USSD)}';

    protected $description = 'Register (or update) a polling agent allowed to use the USSD service';

    public function handle(): int
    {
        $pollingUnitCode = $this->option('pu');

        if ($pollingUnitCode !== null && ! preg_match(config('ussd.polling_unit_pattern'), $pollingUnitCode)) {
            $this->error("Invalid PU code: {$pollingUnitCode}");

            return self::FAILURE;
        }

        $agent = Agent::updateOrCreate(
            ['phone_number' => PhoneNumber::normalize($this->argument('phone'))],
            ['name' => $this->argument('name'), 'polling_unit_code' => $pollingUnitCode],
        );

        $this->info(($agent->wasRecentlyCreated ? 'Added' : 'Updated')." agent {$agent->name} ({$agent->phone_number})"
            .($agent->hasAssignedPollingUnit() ? " assigned to PU {$agent->polling_unit_code}" : ''));

        return self::SUCCESS;
    }
}
