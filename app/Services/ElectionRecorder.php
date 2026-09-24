<?php

namespace App\Services;

use App\Enums\IncidentType;
use App\Jobs\SendSms;
use App\Models\Agent;
use App\Models\Incident;
use App\Models\Presence;
use App\Models\Result;
use Illuminate\Database\UniqueConstraintViolationException;

class ElectionRecorder
{
    public function __construct(private ReferenceGenerator $references) {}

    public function hasResultFor(string $pollingUnitCode): bool
    {
        return Result::where('polling_unit_code', $pollingUnitCode)->exists();
    }

    /**
     * Store a polling unit result. Returns null if the PU already has one.
     */
    public function submitResult(Agent $agent, string $pollingUnitCode, int $candidateVotes, int $totalVotes): ?Result
    {
        try {
            $result = $agent->results()->create([
                'reference' => $this->references->generate('RS', Result::class),
                'polling_unit_code' => $pollingUnitCode,
                'candidate_votes' => $candidateVotes,
                'total_votes' => $totalVotes,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Two agents confirming the same PU at once: the second one loses.
            if ($this->hasResultFor($pollingUnitCode)) {
                return null;
            }

            throw $e;
        }

        if (config('ussd.sms_confirmation')) {
            SendSms::dispatch($agent->phone_number, "Result received. Ref: {$result->reference}");
        }

        return $result;
    }

    public function logIncident(Agent $agent, string $pollingUnitCode, IncidentType $type, string $note): Incident
    {
        return $agent->incidents()->create([
            'reference' => $this->references->generate('IN', Incident::class),
            'polling_unit_code' => $pollingUnitCode,
            'type' => $type,
            'note' => $note,
        ]);
    }

    public function confirmPresence(Agent $agent, string $pollingUnitCode): Presence
    {
        $now = now();

        $agent->update(['is_active' => true, 'last_seen_at' => $now]);

        return $agent->presences()->create([
            'polling_unit_code' => $pollingUnitCode,
            'confirmed_at' => $now,
        ]);
    }
}
