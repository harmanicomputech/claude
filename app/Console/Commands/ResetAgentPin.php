<?php

namespace App\Console\Commands;

use App\Jobs\SendSms;
use App\Models\Agent;
use Illuminate\Console\Command;

class ResetAgentPin extends Command
{
    protected $signature = 'agent:pin
        {phone : Agent phone number}
        {--pin= : New 4-digit PIN (random if omitted)}
        {--sms : Text the new PIN to the agent}';

    protected $description = 'Set a new PIN for an agent and unlock their account';

    public function handle(): int
    {
        $agent = Agent::findByPhone($this->argument('phone'));

        if ($agent === null) {
            $this->error('No agent with that phone number.');

            return self::FAILURE;
        }

        $pin = $this->option('pin') ?? str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        if (! preg_match('/^\d{4}$/', $pin)) {
            $this->error('PIN must be exactly 4 digits.');

            return self::FAILURE;
        }

        $agent->forceFill(['pin' => $pin, 'failed_pin_attempts' => 0, 'locked_until' => null])->save();

        $this->info("New PIN for {$agent->name} ({$agent->phone_number}): {$pin}");

        if ($this->option('sms')) {
            SendSms::dispatch($agent->phone_number, "Election Shield: your new PIN is {$pin}. Keep it secret.");
            $this->line('PIN SMS queued.');
        }

        return self::SUCCESS;
    }
}
