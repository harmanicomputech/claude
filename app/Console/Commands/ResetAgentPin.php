<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\AgentRegistrar;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ResetAgentPin extends Command
{
    protected $signature = 'agent:pin
        {phone : Agent phone number}
        {--pin= : New 4-digit PIN (random if omitted)}
        {--sms : Text the new PIN to the agent}';

    protected $description = 'Set a new PIN for an agent and unlock their account';

    public function handle(AgentRegistrar $registrar): int
    {
        $agent = Agent::findByPhone($this->argument('phone'));

        if ($agent === null) {
            $this->error('No agent with that phone number.');

            return self::FAILURE;
        }

        try {
            $pin = $registrar->resetPin($agent, $this->option('pin'), (bool) $this->option('sms'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("New PIN for {$agent->name} ({$agent->phone_number}): {$pin}");

        if ($this->option('sms')) {
            $this->line('PIN SMS queued.');
        }

        return self::SUCCESS;
    }
}
