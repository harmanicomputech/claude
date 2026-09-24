<?php

use Illuminate\Support\Facades\Schedule;

// Everything periodic goes through election:tick (see App\Support\BackgroundRunner):
// due tasks (dashboard resend, hourly summary, election-day reminders) and the
// queue. Tasks remember their last slot, so any cron interval works and
// overlapping with the web/pinger triggers is safe.
Schedule::command('election:tick')->everyMinute()->withoutOverlapping(2);
