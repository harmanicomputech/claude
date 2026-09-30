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
use App\Models\PollingUnit;
use App\Models\Presence;
use App\Models\Result;
use App\Models\Volunteer;
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
        string $channel = 'ussd',
    ): ?Result {
        $current = $correction
            ? Result::where('accepted_polling_unit_code', $pollingUnitCode)->first()
            : null;

        $validVotes = array_sum($votes);

        try {
            $result = DB::transaction(function () use ($agent, $pollingUnitCode, $accreditedVoters, $votes, $rejectedVotes, $current, $validVotes, $channel) {
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
                    'channel' => $channel,
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

    public function logIncident(Agent $agent, string $pollingUnitCode, IncidentType $type, string $note, string $channel = 'ussd'): Incident
    {
        $incident = $agent->incidents()->create([
            'reference' => $this->references->generate('IN', Incident::class),
            'polling_unit_code' => $pollingUnitCode,
            'type' => $type,
            'note' => $note,
            'channel' => $channel,
        ]);

        $this->outbox->record('incident.reported', $incident->reference, $incident->toDashboardArray());
        $this->email(new IncidentReported($incident));

        if ($incident->isUrgent()) {
            $this->alertCoordinators($incident);
        }

        return $incident;
    }

    /**
     * An incident reported by a member of the public (anyone who dials the
     * code). Shown to the situation room as an unverified public report; no
     * SMS alerts or emails, so a prank can't set off alarms.
     */
    public function logPublicIncident(string $phoneNumber, string $lga, string $ward, ?PollingUnit $unit, IncidentType $type, string $note, string $channel = 'ussd'): Incident
    {
        $incident = Incident::create([
            'reference' => $this->references->generate('IN', Incident::class),
            'agent_id' => null,
            'polling_unit_code' => $unit?->code,
            'lga' => $unit?->lga ?? $lga,
            'ward' => $unit?->ward ?? $ward,
            'source' => Incident::SOURCE_PUBLIC,
            'reporter_phone' => $phoneNumber,
            'type' => $type,
            'note' => $note,
            'channel' => $channel,
        ]);

        $this->outbox->record('incident.reported', $incident->reference, $incident->toDashboardArray());

        return $incident;
    }

    /**
     * How many public reports this number sent in the last 24 hours.
     */
    public function publicReportsToday(string $phoneNumber): int
    {
        return Incident::query()->where('source', Incident::SOURCE_PUBLIC)->where('reporter_phone', $phoneNumber)->where('created_at', '>=', now()->subDay())->count();
    }

    /**
     * A "How can you help?" sign-up. One per phone number: signing up again
     * updates the same record (and the web app gets the new version).
     *
     * @param  list<string>  $roles  VolunteerRole values
     * @param  list<string>  $skills  keys of VolunteerRole::SKILLS
     */
    public function registerVolunteer(string $phoneNumber, string $contactPhone, string $name, string $lga, string $ward, array $roles, array $skills, ?string $other, bool $isAgent, string $channel = 'ussd'): Volunteer
    {
        $volunteer = Volunteer::query()->firstOrNew(['phone_number' => $phoneNumber]);
        $volunteer->fill([
            'reference' => $volunteer->reference ?? $this->references->generate('VL', Volunteer::class),
            'contact_phone' => $contactPhone,
            'name' => $name,
            'lga' => $lga,
            'ward' => $ward,
            'roles' => array_values($roles),
            'skills' => $skills === [] ? null : array_values($skills),
            'other' => $other,
            'is_agent' => $isAgent,
            'channel' => $channel,
        ]);
        $volunteer->save();

        // A new key for each version, so an update reaches the web app too.
        $this->outbox->record('volunteer.registered', $volunteer->reference.':'.$volunteer->updated_at->timestamp, $volunteer->toDashboardArray());

        return $volunteer;
    }

    public function confirmPresence(Agent $agent, string $pollingUnitCode, string $channel = 'ussd'): Presence
    {
        $now = now();

        $agent->update(['is_active' => true, 'last_seen_at' => $now]);

        $presence = $agent->presences()->create([
            'polling_unit_code' => $pollingUnitCode,
            'confirmed_at' => $now,
            'channel' => $channel,
        ]);

        $this->outbox->record('presence.confirmed', (string) $presence->id, $presence->toDashboardArray());

        return $presence;
    }

    /**
     * Record the election materials status at a PU. Agents may report again
     * as things change; the latest report is the PU's current status.
     */
    public function reportMaterials(Agent $agent, string $pollingUnitCode, MaterialStatus $status, string $channel = 'ussd'): MaterialReport
    {
        $report = $agent->materialReports()->create([
            'polling_unit_code' => $pollingUnitCode,
            'status' => $status,
            'reported_at' => now(),
            'channel' => $channel,
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
            .'"'.Str::limit($incident->note, 100).'" - '."{$incident->agent->name} {$incident->agent->phone_number}. Ref {$incident->reference}";

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
