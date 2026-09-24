<?php

namespace App\Console\Commands;

use App\Support\SystemStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class Heartbeat extends Command
{
    protected $signature = 'election:heartbeat';

    protected $description = 'Record that the scheduler cron ran (shown in the admin console)';

    public function handle(): int
    {
        Cache::forever(SystemStatus::HEARTBEAT_KEY, now()->toIso8601String());

        return self::SUCCESS;
    }
}
