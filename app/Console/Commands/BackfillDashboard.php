<?php

namespace App\Console\Commands;

use App\Services\DashboardClient;
use App\Services\DashboardOutbox;
use Illuminate\Console\Command;

class BackfillDashboard extends Command
{
    protected $signature = 'dashboard:backfill';

    protected $description = 'Send every existing result, incident and check-in to the dashboard (safe to repeat)';

    public function handle(DashboardClient $dashboard, DashboardOutbox $outbox): int
    {
        if (! $dashboard->enabled()) {
            $this->warn('DASHBOARD_WEBHOOK_URL is not set.');

            return self::FAILURE;
        }

        $this->info('Queued '.$outbox->backfill().' event(s) for the dashboard.');

        return self::SUCCESS;
    }
}
