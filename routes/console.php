<?php

use App\Support\ElectionCalendar;
use App\Support\Queues;
use Illuminate\Support\Facades\Schedule;

$calendar = app(ElectionCalendar::class);
$timezone = config('election.timezone');

// Lets the admin console show whether the cron job is running. A command
// rather than a closure, so it also proves the host lets the scheduler start
// processes (some shared hosts disable proc_open).
Schedule::command('election:heartbeat')->everyMinute();

// Safety net for dashboard events whose delivery ran out of retries.
Schedule::command('dashboard:sync')->everyFifteenMinutes()->withoutOverlapping();

// Hourly summary email while the election is running.
Schedule::command('election:summary')
    ->hourly()
    ->when(fn () => config('election.hourly_summary') && $calendar->inReportingPeriod());

// Election-day SMS reminders to agents who haven't checked in / reported.
foreach (['presence' => 'presence_reminder_at', 'results' => 'results_reminder_at'] as $type => $setting) {
    if (filled(config("election.{$setting}"))) {
        Schedule::command("election:remind {$type}")
            ->dailyAt(config("election.{$setting}"))
            ->timezone($timezone)
            ->when(fn () => $calendar->isElectionDay());
    }
}

// Shared hosting (no permanent worker): work the queue for most of each
// minute from the scheduler cron. Must stay last, as it runs for ~50s.
if (config('election.scheduler_runs_queue')) {
    Schedule::command('queue:work --queue='.Queues::WORKER_ORDER.' --stop-when-empty --max-time=50 --tries=3')
        ->everyMinute()
        ->withoutOverlapping(2);
}
