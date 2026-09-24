<?php

namespace App\Services;

use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\PollingUnit;
use App\Support\PhoneNumber;
use InvalidArgumentException;

/**
 * Creates or updates agents, shared by the CLI commands and the admin console.
 */
class AgentRegistrar
{
    /**
     * Register an agent (or update the one with this phone number).
     *
     * A new agent without a PIN gets a random one; an existing agent keeps
     * theirs unless $pin is given. Returns the agent and the PIN that was set
     * (null when the PIN did not change).
     *
     * @return array{0: Agent, 1: ?string}
     *
     * @throws InvalidArgumentException for an invalid phone, PU code or PIN
     */
    public function register(string $phone, string $name, ?string $pollingUnit = null, ?string $pin = null, bool $smsPin = false): array
    {
        $phone = trim($phone);
        $name = trim($name);

        if (strlen(preg_replace('/\D/', '', $phone)) < 10) {
            throw new InvalidArgumentException("Invalid phone number: {$phone}");
        }

        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }

        $pollingUnitCode = filled($pollingUnit) ? PollingUnit::normalizeCode($pollingUnit) : null;

        if ($pollingUnitCode !== null && ! PollingUnit::isAssignable($pollingUnitCode)) {
            throw new InvalidArgumentException("Unknown or invalid PU code: {$pollingUnit}");
        }

        $pin = filled($pin) ? trim($pin) : null;

        if ($pin !== null && ! preg_match('/^\d{4}$/', $pin)) {
            throw new InvalidArgumentException('PIN must be exactly 4 digits.');
        }

        $agent = Agent::firstOrNew(['phone_number' => PhoneNumber::normalize($phone)]);
        $agent->fill(['name' => $name, 'polling_unit_code' => $pollingUnitCode]);

        if ($pin === null && ! $agent->hasPin()) {
            $pin = $this->randomPin();
        }

        if ($pin !== null) {
            $agent->pin = $pin;
        }

        $agent->save();

        if ($pin !== null && $smsPin) {
            $this->smsPin($agent, $pin);
        }

        return [$agent, $pin];
    }

    /**
     * Set a new PIN (random unless given) and unlock the agent.
     */
    public function resetPin(Agent $agent, ?string $pin = null, bool $smsPin = false): string
    {
        $pin = filled($pin) ? trim($pin) : $this->randomPin();

        if (! preg_match('/^\d{4}$/', $pin)) {
            throw new InvalidArgumentException('PIN must be exactly 4 digits.');
        }

        $agent->forceFill(['pin' => $pin, 'failed_pin_attempts' => 0, 'locked_until' => null])->save();

        if ($smsPin) {
            $this->smsPin($agent, $pin);
        }

        return $pin;
    }

    private function randomPin(): string
    {
        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    private function smsPin(Agent $agent, string $pin): void
    {
        SendSms::dispatch($agent->phone_number, "Election Shield: your PIN is {$pin}. Keep it secret. You need it to submit results. Dial ".config('ussd.service_code').'.');
    }
}
