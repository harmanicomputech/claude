<?php

use App\Support\ElectionCalendar;
use Illuminate\Support\Facades\Schedule;

$calendar = app(ElectionCalendar::class);
$timezone = config('election.timezone');

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
