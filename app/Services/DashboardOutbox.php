<?php

namespace App\Services;

use App\Jobs\PushToDashboard;
use App\Models\DashboardDelivery;

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
     * @param  array<string, mixed>  $payload
     */
    public function record(string $event, string $key, array $payload): void
    {
        if (! $this->dashboard->enabled()) {
            return;
        }

        $delivery = DashboardDelivery::firstOrCreate(
            ['idempotency_key' => "{$event}:{$key}"],
            ['event' => $event, 'payload' => $payload],
        );

        if ($delivery->wasRecentlyCreated) {
            PushToDashboard::dispatch($delivery);
        }
    }
}
