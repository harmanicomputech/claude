<?php

namespace App\Console\Commands;

use App\Jobs\PushToDashboard;
use App\Models\Incident;
use App\Models\Presence;
use App\Models\Result;
use App\Services\DashboardClient;
use Illuminate\Console\Command;

class SyncDashboard extends Command
{
    protected $signature = 'dashboard:sync
        {--older-than=30 : Only resend records created at least this many minutes ago}';

    protected $description = 'Queue every result, incident and presence the dashboard has not acknowledged yet';

    public function handle(DashboardClient $dashboard): int
    {
        if (! $dashboard->enabled()) {
            $this->warn('DASHBOARD_WEBHOOK_URL is not set; nothing to sync.');

            return self::SUCCESS;
        }

        $cutoff = now()->subMinutes((int) $this->option('older-than'));

        foreach ([Result::class, Incident::class, Presence::class] as $model) {
            $count = 0;

            $model::whereNull('dashboard_synced_at')
                ->where('created_at', '<=', $cutoff)
                ->each(function ($record) use (&$count) {
                    PushToDashboard::dispatch($record);
                    $count++;
                });

            $this->line("Queued {$count} ".class_basename($model).' record(s).');
        }

        return self::SUCCESS;
    }
}
