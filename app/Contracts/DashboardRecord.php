<?php

namespace App\Contracts;

/**
 * A record that is pushed to the external results dashboard.
 */
interface DashboardRecord
{
    /**
     * Event name sent to the dashboard, e.g. "result.submitted".
     */
    public function dashboardEvent(): string;

    /**
     * Stable key the dashboard can use to ignore retried deliveries.
     */
    public function dashboardIdempotencyKey(): string;

    /**
     * @return array<string, mixed>
     */
    public function dashboardPayload(): array;
}
