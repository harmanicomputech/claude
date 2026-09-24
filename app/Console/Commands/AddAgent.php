<?php

namespace App\Console\Commands;

use App\Services\AgentRegistrar;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AddAgent extends Command
{
    protected $signature = 'agent:add
        {phone : Agent phone number, e.g. 08012345678 or +2348012345678}
        {name : Agent full name}
        {--pu= : Assigned polling unit code (skips PU entry on USSD)}
        {--pin= : 4-digit PIN (a random one is generated for new agents)}
        {--sms-pin : Text the PIN to the agent}';

    protected $description = 'Register (or update) a polling agent allowed to use the USSD service';

    public function handle(AgentRegistrar $registrar): int
    {
        try {
            [$agent, $pin] = $registrar->register(
                $this->argument('phone'),
                $this->argument('name'),
                $this->option('pu'),
                $this->option('pin'),
                (bool) $this->option('sms-pin'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($agent->wasRecentlyCreated ? 'Added' : 'Updated')." agent {$agent->name} ({$agent->phone_number})"
            .($agent->hasAssignedPollingUnit() ? " assigned to PU {$agent->polling_unit_code}" : ''));

        if ($pin !== null) {
            $this->line("PIN: {$pin}");

            if ($this->option('sms-pin')) {
                $this->line('PIN SMS queued.');
            }
        }

        return self::SUCCESS;
    }
}
