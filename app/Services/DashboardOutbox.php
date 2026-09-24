<?php

namespace App\Services;

use App\Enums\ResultStatus;
use App\Jobs\PushToDashboard;
use App\Models\DashboardDelivery;
use App\Models\Incident;
use App\Models\Presence;
use App\Models\Result;
use App\Support\Rehearsal;

/**
 * Records dashboard events in the outbox and queues their delivery.
 *
 * Each event's payload is frozen when it happens, so a result that is later
 * corrected still reaches the dashboard exactly as it was at each step.
 */
class DashboardOutbox
{
    public function __construct(private DashboardClient $dashboard) {}

    /**
     * Queue every existing record, so a dashboard connected after data was
     * already collected catches up. Uses the same idempotency keys as live
     * events, so nothing is sent twice. Returns the number of new events.
     */
    public function backfill(): int
    {
        if (! $this->dashboard->enabled()) {
            return 0;
        }

        $before = DashboardDelivery::count();

        Result::with('corrects')->orderBy('id')->lazy(500)->each(function (Result $result) {
            [$event, $extra] = match (true) {
                $result->status === ResultStatus::Pending => ['result.correction_requested', []],
                $result->status === ResultStatus::Rejected => ['result.correction_rejected', []],
                $result->status === ResultStatus::Accepted && $result->isCorrection() => ['result.corrected', ['superseded_reference' => $result->corrects?->reference]],
                default => ['result.submitted', []],
            };

            $this->record($event, $result->reference, [...$result->toDashboardArray(), ...$extra]);
        });

        Incident::orderBy('id')->lazy(500)->each(fn (Incident $incident) => $this->record('incident.reported', $incident->reference, $incident->toDashboardArray()));
        Presence::orderBy('id')->lazy(500)->each(fn (Presence $presence) => $this->record('presence.confirmed', (string) $presence->id, $presence->toDashboardArray()));

        return DashboardDelivery::count() - $before;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $event, string $key, array $payload): void
    {
        if (! $this->dashboard->enabled()) {
            return;
        }

        $delivery = DashboardDelivery::firstOrCreate(
            ['idempotency_key' => "{$event}:{$key}"],
            ['event' => $event, 'payload' => [...$payload, 'rehearsal' => Rehearsal::active()]],
        );

        if ($delivery->wasRecentlyCreated) {
            PushToDashboard::dispatch($delivery);
        }
    }
}
