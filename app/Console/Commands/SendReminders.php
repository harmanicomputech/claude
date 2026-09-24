<?php

namespace App\Console\Commands;

use App\Services\ElectionReminders;
use Illuminate\Console\Command;

class SendReminders extends Command
{
    protected $signature = 'election:remind {type : presence or results}';

    protected $description = 'SMS agents who have not confirmed presence / submitted their PU result';

    public function handle(ElectionReminders $reminders): int
    {
        $count = match ($this->argument('type')) {
            'presence' => $reminders->presence(),
            'results' => $reminders->results(),
            default => null,
        };

        if ($count === null) {
            $this->error('Type must be "presence" or "results".');

            return self::FAILURE;
        }

        $this->info("Queued {$count} reminder SMS.");

        return self::SUCCESS;
    }
}
