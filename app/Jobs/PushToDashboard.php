<?php

namespace App\Jobs;

use App\Models\DashboardDelivery;
use App\Services\DashboardClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class PushToDashboard implements ShouldQueue
{
    use Queueable;

    /**
     * Retries span about 25 minutes so a short dashboard outage loses nothing.
     * Anything still undelivered after that is picked up by `dashboard:sync`.
     */
    public int $tries = 8;

    public array $backoff = [5, 15, 30, 60, 120, 300, 900];

    public function __construct(public DashboardDelivery $delivery) {}

    public function handle(DashboardClient $dashboard): void
    {
        $this->delivery->refresh();

        if ($this->delivery->delivered_at !== null || ! $dashboard->enabled()) {
            return;
        }

        $this->delivery->increment('attempts');

        try {
            $dashboard->push($this->delivery);
        } catch (Throwable $e) {
            $this->delivery->forceFill(['last_error' => Str::limit($e->getMessage(), 1000)])->save();

            throw $e;
        }

        $this->delivery->forceFill(['delivered_at' => now(), 'last_error' => null])->save();
    }
}
