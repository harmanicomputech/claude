<?php

namespace App\Http\Controllers\Api;

use App\Enums\IncidentType;
use App\Enums\MaterialStatus;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\PollingUnit;
use App\Services\ElectionRecorder;
use App\Support\ElectionCalendar;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Submissions an agent makes in the Election Shield web app. The web app
 * signs the agent in (with their USSD PIN) and sends their phone number; the
 * same rules as the USSD menu apply, and the records go through
 * ElectionRecorder with channel "web", so SMS receipts, alerts, emails and
 * the dashboard webhook all behave as for USSD.
 */
class FieldController extends Controller
{
    public function __construct(
        private ElectionRecorder $recorder,
        private ElectionCalendar $calendar,
    ) {}

    public function result(Request $request): JsonResponse
    {
        $parties = config('election.parties');
        $max = (int) str_repeat('9', (int) config('ussd.max_vote_digits'));

        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:20'],
            'polling_unit' => ['nullable', 'string', 'max:30'],
            'accredited_voters' => ['required', 'integer', 'min:0', "max:{$max}"],
            'votes' => ['required', 'array'],
            ...collect($parties)->mapWithKeys(fn (string $party) => ["votes.{$party}" => ['required', 'integer', 'min:0', "max:{$max}"]])->all(),
            'rejected_votes' => ['required', 'integer', 'min:0', "max:{$max}"],
            'correction' => ['boolean'],
        ]);

        $agent = $this->agent($validated['phone_number']);
        $this->closed($this->calendar->resultsClosedMessage());
        [$code, $unit] = $this->pollingUnit($agent, $validated['polling_unit'] ?? null);

        $votes = collect($parties)->mapWithKeys(fn (string $party) => [$party => (int) $validated['votes'][$party]])->all();
        $accredited = (int) $validated['accredited_voters'];
        $rejected = (int) $validated['rejected_votes'];

        if ($unit?->registered_voters !== null && $accredited > $unit->registered_voters) {
            $this->fail('above_registered', "Accredited voters ({$accredited}) are more than the PU's registered voters ({$unit->registered_voters}).", 422);
        }

        if (array_sum($votes) + $rejected > $accredited) {
            $this->fail('over_accredited', 'Votes cast ('.(array_sum($votes) + $rejected).") are more than accredited voters ({$accredited}). Check the figures.", 422);
        }

        $exists = $this->recorder->hasAcceptedResultFor($code);
        $correction = $exists && (bool) ($validated['correction'] ?? false);

        if ($exists && ! $correction) {
            $this->fail('result_exists', 'A result was already submitted for this PU. Send a correction instead.', 409);
        }

        $result = $this->recorder->submitResult($agent, $code, $accredited, $votes, $rejected, correction: $correction, channel: 'web');

        if ($result === null) {
            $this->fail('result_exists', 'Another agent submitted a result for this PU a moment ago. Send a correction if it is wrong.', 409);
        }

        return response()->json([
            'message' => $result->isCorrection() ? "Correction sent for review. Ref: {$result->reference}" : "Result submitted. Ref: {$result->reference}",
            'result' => $result->toDashboardArray(),
        ], 201);
    }

    public function incident(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:20'],
            'polling_unit' => ['nullable', 'string', 'max:30'],
            'type' => ['required', Rule::enum(IncidentType::class)],
            'note' => ['required', 'string', 'min:2', 'max:1000'],
        ]);

        $agent = $this->agent($validated['phone_number']);
        [$code] = $this->pollingUnit($agent, $validated['polling_unit'] ?? null);

        $incident = $this->recorder->logIncident($agent, $code, IncidentType::from($validated['type']), trim($validated['note']), channel: 'web');

        return response()->json(['message' => "Incident logged. Ref: {$incident->reference}", 'incident' => $incident->toDashboardArray()], 201);
    }

    public function presence(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:20'],
            'polling_unit' => ['nullable', 'string', 'max:30'],
        ]);

        $agent = $this->agent($validated['phone_number']);
        $this->closed($this->calendar->presenceClosedMessage());
        [$code] = $this->pollingUnit($agent, $validated['polling_unit'] ?? null);

        $presence = $this->recorder->confirmPresence($agent, $code, channel: 'web');

        return response()->json(['message' => 'Presence confirmed.', 'presence' => $presence->toDashboardArray()], 201);
    }

    public function materials(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => ['required', 'string', 'max:20'],
            'polling_unit' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::enum(MaterialStatus::class)],
        ]);

        $agent = $this->agent($validated['phone_number']);
        [$code] = $this->pollingUnit($agent, $validated['polling_unit'] ?? null);

        $report = $this->recorder->reportMaterials($agent, $code, MaterialStatus::from($validated['status']), channel: 'web');

        return response()->json(['message' => 'Materials report saved.', 'materials' => $report->toDashboardArray()], 201);
    }

    private function agent(string $phone): Agent
    {
        return Agent::findByPhone($phone) ?? $this->fail('unknown_agent', 'This phone number is not registered as an agent.', 404);
    }

    /**
     * The agent's assigned PU, or (for agents without one) the PU they name,
     * checked like the USSD menu checks it.
     *
     * @return array{0: string, 1: ?PollingUnit}
     */
    private function pollingUnit(Agent $agent, ?string $requested): array
    {
        if ($agent->hasAssignedPollingUnit()) {
            $code = $agent->polling_unit_code;

            if (filled($requested) && PollingUnit::normalizeCode($requested) !== $code) {
                $this->fail('wrong_polling_unit', "You are assigned to PU {$code}. Ask a coordinator to change it.", 422);
            }
        } elseif (blank($requested)) {
            $this->fail('polling_unit_required', 'Enter the PU code.', 422);
        } else {
            $code = PollingUnit::normalizeCode($requested);

            if (! preg_match(config('ussd.polling_unit_pattern'), $code)) {
                $this->fail('invalid_polling_unit', 'That PU code is not valid.', 422);
            }
        }

        $unit = PollingUnit::findByCode($code);

        if ($unit === null && config('election.require_known_polling_unit')) {
            $this->fail('unknown_polling_unit', "PU {$code} is not in the register. Contact a coordinator.", 422);
        }

        return [$code, $unit];
    }

    private function closed(?string $message): void
    {
        if ($message !== null) {
            $this->fail('closed', str_replace("\n", ' ', $message), 409);
        }
    }

    private function fail(string $code, string $message, int $status): never
    {
        throw new HttpResponseException(response()->json(['error' => $code, 'message' => $message], $status));
    }
}
