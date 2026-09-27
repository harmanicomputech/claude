<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\AgentRegistrar;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Agent accounts for the Election Shield web app: adding agents, resetting
 * PINs, and checking an agent's PIN when they sign in to the web app. Wrong
 * PINs count towards the same lock-out as on USSD.
 */
class AgentAccountController extends Controller
{
    public function store(Request $request, AgentRegistrar $registrar): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone_number' => ['required', 'string', 'max:20'],
            'polling_unit' => ['nullable', 'string', 'max:30'],
            'pin' => ['nullable', 'string'],
            'sms_pin' => ['boolean'],
            'by' => ['nullable', 'string', 'max:100'],
        ]);

        $existed = Agent::findByPhone($validated['phone_number']) !== null;

        try {
            [$agent, $pin] = $registrar->register(
                $validated['phone_number'],
                $validated['name'],
                $validated['polling_unit'] ?? null,
                $validated['pin'] ?? null,
                (bool) ($validated['sms_pin'] ?? false),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error('invalid', $e->getMessage(), 422);
        }

        Audit::record($existed ? 'agent.updated' : 'agent.created', ($existed ? 'Updated' : 'Added')." agent {$agent->name} ({$agent->phone_number}) from the web app", $agent, actor: $this->actor($validated));

        return response()->json(['agent' => $this->present($agent), 'pin' => $pin, 'created' => ! $existed], $existed ? 200 : 201);
    }

    public function resetPin(Request $request, AgentRegistrar $registrar): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:20'],
            'pin' => ['nullable', 'string'],
            'sms_pin' => ['boolean'],
            'by' => ['nullable', 'string', 'max:100'],
        ]);

        $agent = Agent::findByPhone($validated['phone_number']);

        if ($agent === null) {
            return $this->error('unknown_agent', 'No agent has this phone number.', 404);
        }

        try {
            $pin = $registrar->resetPin($agent, $validated['pin'] ?? null, (bool) ($validated['sms_pin'] ?? false));
        } catch (InvalidArgumentException $e) {
            return $this->error('invalid', $e->getMessage(), 422);
        }

        Audit::record('agent.pin_reset', "Reset the PIN for {$agent->name} ({$agent->phone_number}) from the web app", $agent, actor: $this->actor($validated));

        return response()->json(['agent' => $this->present($agent->refresh()), 'pin' => $pin]);
    }

    public function verifyPin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:20'],
            'pin' => ['required', 'string', 'max:10'],
        ]);

        $agent = Agent::findByPhone($validated['phone_number']);

        if ($agent === null) {
            return $this->error('unknown_agent', 'This phone number is not registered as an agent.', 404);
        }

        if ($agent->isLocked()) {
            return $this->error('locked', 'Too many wrong PINs. Try again after '.$agent->locked_until->setTimezone(config('election.timezone'))->format('g:i A').'.', 423);
        }

        if (! $agent->hasPin()) {
            return $this->error('no_pin', 'No PIN is set for this agent. Ask a coordinator to reset it.', 409);
        }

        if (! $agent->pinMatches($validated['pin'])) {
            $left = $agent->recordFailedPin();

            if ($left === 0) {
                Audit::record('agent.locked', "Agent {$agent->name} ({$agent->phone_number}) locked after too many wrong PINs", $agent, actor: 'Web app');

                return $this->error('locked', 'Too many wrong PINs. Try again in '.config('election.pin_lock_minutes').' minutes.', 423);
            }

            return response()->json(['error' => 'wrong_pin', 'message' => "Wrong PIN. {$left} ".($left === 1 ? 'try' : 'tries').' left.', 'tries_left' => $left], 401);
        }

        $agent->clearFailedPins();

        return response()->json(['agent' => $this->present($agent)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Agent $agent): array
    {
        $agent->loadMissing('pollingUnit');

        return [
            'id' => $agent->id,
            ...$agent->toSummaryArray(),
            'polling_unit' => $agent->pollingUnit?->toSummaryArray() ?? ($agent->polling_unit_code ? ['code' => $agent->polling_unit_code] : null),
            'has_pin' => $agent->hasPin(),
            'locked' => $agent->isLocked(),
            'last_seen_at' => $agent->last_seen_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function actor(array $validated): string
    {
        return 'Web app'.(filled($validated['by'] ?? null) ? " ({$validated['by']})" : '');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
