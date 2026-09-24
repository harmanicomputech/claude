<?php

namespace App\Jobs;

use App\Contracts\DashboardRecord;
use App\Services\DashboardClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

class PushToDashboard implements ShouldQueue
{
    use Queueable;

    /**
     * Retries span about 25 minutes so a short dashboard outage loses nothing.
     * Anything still unsynced after that is picked up by `dashboard:sync`.
     */
    public int $tries = 8;

    public array $backoff = [5, 15, 30, 60, 120, 300, 900];

    public function __construct(public Model&DashboardRecord $record) {}

    public function handle(DashboardClient $dashboard): void
    {
        $this->record->refresh();

        if ($this->record->dashboard_synced_at !== null || ! $dashboard->enabled()) {
            return;
        }

        $dashboard->push($this->record);

        $this->record->forceFill(['dashboard_synced_at' => now()])->save();
    }
}
