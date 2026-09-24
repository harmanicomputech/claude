<?php

namespace App\Console\Commands;

use App\Support\BackgroundRunner;
use Illuminate\Console\Command;

class Tick extends Command
{
    protected $signature = 'election:tick {--seconds=50 : How long to work the queue}';

    protected $description = 'Run due scheduled tasks and send waiting SMS/emails (for cron, any interval)';

    public function handle(BackgroundRunner $runner): int
    {
        $ran = $runner->run(queueSeconds: (int) $this->option('seconds'), source: 'cron');

        $this->info($ran ? 'Background work done.' : 'Another run is in progress; skipped.');

        return self::SUCCESS;
    }
}
