<?php

namespace App\Console\Commands;

use App\Mail\ElectionSummary;
use App\Services\ElectionStats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendSummary extends Command
{
    protected $signature = 'election:summary {--print : Show the numbers instead of emailing them}';

    protected $description = 'Email the election summary (results, turnout, incidents by LGA) to NOTIFY_EMAILS';

    public function handle(ElectionStats $stats): int
    {
        $summary = $stats->summary();

        if ($this->option('print')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $recipients = config('ussd.notify_emails');

        if ($recipients === []) {
            $this->warn('NOTIFY_EMAILS is empty; nothing sent.');

            return self::SUCCESS;
        }

        Mail::to($recipients)->queue(new ElectionSummary($summary));
        $this->info('Summary email queued to '.implode(', ', $recipients).'.');

        return self::SUCCESS;
    }
}
