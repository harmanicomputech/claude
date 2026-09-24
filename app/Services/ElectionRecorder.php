<?php

namespace App\Services;

use App\Enums\IncidentType;
use App\Enums\MaterialStatus;
use App\Enums\ResultStatus;
use App\Jobs\SendSms;
use App\Mail\CorrectionRequested;
use App\Mail\IncidentReported;
use App\Mail\ResultSubmitted;
use App\Models\Agent;
use App\Models\Coordinator;
use App\Models\Incident;
use App\Models\MaterialReport;
use App\Models\Presence;
use App\Models\Result;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Every write the USSD flows make, plus the notifications each one triggers.
 * Notifications are all queued so the USSD reply is never held up.
 */
class ElectionRecorder
{
    public function __construct(
        private ReferenceGenerator $references,
        private DashboardOutbox $outbox,
    ) {}

    public function hasAcceptedResultFor(string $pollingUnitCode): bool
    {
        return Result::where('accepted_polling_unit_code', $pollingUnitCode)->exists();
    }

    /**
     * Store an EC8A result. The PU's first result is accepted straight away;
     * with $correction, a result for a PU that already has one is stored as
     * pending until a coordinator reviews it. Returns null when a first
     * result loses a race with another agent's submission for the same PU.
     *
     * @param  array<string, int>  $votes  party => votes, in ballot order
     */
    public function submitResult(
        Agent $agent,
        string $pollingUnitCode,
        int $accreditedVoters,
        array $votes,
        int $rejectedVotes,
        bool $correction = false,
    ): ?Result {
        $current = $correction
            ? Result::where('accepted_polling_unit_code', $pollingUnitCode)->first()
            : null;

        $validVotes = array_sum($votes);

        try {
            $result = DB::transaction(function () use ($agent, $pollingUnitCode, $accreditedVoters, $votes, $rejectedVotes, $current, $validVotes) {
                $result = $agent->results()->create([
                    'reference' => $this->references->generate('RS', Result::class),
                    'polling_unit_code' => $pollingUnitCode,
                    'status' => $current ? ResultStatus::Pending : ResultStatus::Accepted,
                    'accepted_polling_unit_code' => $current ? null : $pollingUnitCode,
                    'corrects_result_id' => $current?->id,
                    'accredited_voters' => $accreditedVoters,
                    'rejected_votes' => $rejectedVotes,
                    'total_valid_votes' => $validVotes,
                    'total_votes_cast' => $validVotes + $rejectedVotes,
                ]);

                foreach ($votes as $party => $count) {
                    $result->votes()->create(['party' => $party, 'votes' => $count]);
                }

                return $result;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two agents confirming the same PU at once: the second one loses.
            if (! $correction && $this->hasAcceptedResultFor($pollingUnitCode)) {
                return null;
            }

            throw $e;
        }

        $result->isCorrection()
            ? $this->announceCorrectionRequest($result)
            : $this->announceAcceptedResult($result);

        return $result;
    }

    public function logIncident(Agent $agent, string $pollingUnitCode, IncidentType $type, string $note): Incident
    {
        $incident = $agent->incidents()->create([
            'reference' => $this->references->generate('IN', Incident::class),
            'polling_unit_code' => $pollingUnitCode,
            'type' => $type,
            'note' => $note,
        ]);

        $this->outbox->record('incident.reported', $incident->reference, $incident->toDashboardArray());
        $this->email(new IncidentReported($incident));

        if ($incident->isUrgent()) {
            $this->alertCoordinators($incident);
        }

        return $incident;
    }

    public function confirmPresence(Agent $agent, string $pollingUnitCode): Presence
    {
        $now = now();

        $agent->update(['is_active' => true, 'last_seen_at' => $now]);

        $presence = $agent->presences()->create([
            'polling_unit_code' => $pollingUnitCode,
            'confirmed_at' => $now,
        ]);

        $this->outbox->record('presence.confirmed', (string) $presence->id, $presence->toDashboardArray());

        return $presence;
    }

    /**
     * Record the election materials status at a PU. Agents may report again
     * as things change; the latest report is the PU's current status.
     */
    public function reportMaterials(Agent $agent, string $pollingUnitCode, MaterialStatus $status): MaterialReport
    {
        $report = $agent->materialReports()->create([
            'polling_unit_code' => $pollingUnitCode,
            'status' => $status,
            'reported_at' => now(),
        ]);

        $this->outbox->record('materials.reported', (string) $report->id, $report->toDashboardArray());

        return $report;
    }

    private function announceAcceptedResult(Result $result): void
    {
        $this->sms($result->agent->phone_number, "Result received. Ref: {$result->reference}");
        $this->outbox->record('result.submitted', $result->reference, $result->toDashboardArray());

        if (config('election.email_each_result')) {
            $this->email(new ResultSubmitted($result));
        }
    }

    private function announceCorrectionRequest(Result $result): void
    {
        $this->sms($result->agent->phone_number, "Correction received and awaiting review. Ref: {$result->reference}");
        $this->outbox->record('result.correction_requested', $result->reference, $result->toDashboardArray());
        $this->email(new CorrectionRequested($result));
    }

    /**
     * SMS the coordinators for the incident's LGA and the state-wide ones.
     */
    private function alertCoordinators(Incident $incident): void
    {
        $unit = $incident->pollingUnit;
        $where = $unit ? "{$unit->shortName(30)}, {$unit->lga}" : "PU {$incident->polling_unit_code}";

        $message = Str::upper($incident->type->label())." ALERT: {$where} (PU {$incident->polling_unit_code}). "
            ."\"{$incident->note}\" - {$incident->agent->name} {$incident->agent->phone_number}. Ref {$incident->reference}";

        Coordinator::covering($unit?->lga)->each(
            fn (Coordinator $coordinator) => SendSms::dispatch($coordinator->phone_number, $message)
        );
    }

    private function sms(string $to, string $message): void
    {
        if (config('ussd.sms_confirmation')) {
            SendSms::dispatch($to, $message);
        }
    }

    private function email(Mailable $mail): void
    {
        $recipients = config('ussd.notify_emails');

        if ($recipients !== []) {
            Mail::to($recipients)->queue($mail);
        }
    }
}
