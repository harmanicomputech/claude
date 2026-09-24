<?php

namespace App\Console\Commands;

use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\PollingUnit;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

class AddAgent extends Command
{
    protected $signature = 'agent:add
        {phone : Agent phone number, e.g. 08012345678 or +2348012345678}
        {name : Agent full name}
        {--pu= : Assigned polling unit code (skips PU entry on USSD)}
        {--pin= : 4-digit PIN (a random one is generated for new agents)}
        {--sms-pin : Text the PIN to the agent}';

    protected $description = 'Register (or update) a polling agent allowed to use the USSD service';

    public function handle(): int
    {
        $pollingUnitCode = $this->option('pu') !== null ? PollingUnit::normalizeCode($this->option('pu')) : null;

        if ($pollingUnitCode !== null && ! PollingUnit::isAssignable($pollingUnitCode)) {
            $this->error("Unknown or invalid PU code: {$this->option('pu')}. Import the PU register first (pu:import).");

            return self::FAILURE;
        }

        $pin = $this->option('pin');

        if ($pin !== null && ! preg_match('/^\d{4}$/', $pin)) {
            $this->error('PIN must be exactly 4 digits.');

            return self::FAILURE;
        }

        $agent = Agent::firstOrNew(['phone_number' => PhoneNumber::normalize($this->argument('phone'))]);
        $agent->fill(['name' => $this->argument('name'), 'polling_unit_code' => $pollingUnitCode]);

        if ($pin === null && ! $agent->hasPin()) {
            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        }

        if ($pin !== null) {
            $agent->pin = $pin;
        }

        $created = ! $agent->exists;
        $agent->save();

        $this->info(($created ? 'Added' : 'Updated')." agent {$agent->name} ({$agent->phone_number})"
            .($agent->hasAssignedPollingUnit() ? " assigned to PU {$agent->polling_unit_code}" : ''));

        if ($pin !== null) {
            $this->line("PIN: {$pin}");

            if ($this->option('sms-pin')) {
                SendSms::dispatch($agent->phone_number, "Election Shield: your PIN is {$pin}. Keep it secret. You need it to submit results.");
                $this->line('PIN SMS queued.');
            }
        }

        return self::SUCCESS;
    }
}
