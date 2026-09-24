<?php

namespace App\Console\Commands;

use App\Jobs\PushToDashboard;
use App\Models\DashboardDelivery;
use App\Services\DashboardClient;
use Illuminate\Console\Command;

class SyncDashboard extends Command
{
    protected $signature = 'dashboard:sync
        {--older-than=30 : Only resend events created at least this many minutes ago}';

    protected $description = 'Queue every dashboard event that has not been delivered yet';

    public function handle(DashboardClient $dashboard): int
    {
        if (! $dashboard->enabled()) {
            $this->warn('DASHBOARD_WEBHOOK_URL is not set; nothing to sync.');

            return self::SUCCESS;
        }

        $count = 0;

        DashboardDelivery::whereNull('delivered_at')
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('older-than')))
            ->each(function (DashboardDelivery $delivery) use (&$count) {
                PushToDashboard::dispatch($delivery);
                $count++;
            });

        $this->info("Queued {$count} undelivered event(s).");

        return self::SUCCESS;
    }
}
