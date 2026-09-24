<?php

use Illuminate\Support\Facades\Schedule;

// Safety net for records whose dashboard delivery ran out of retries.
Schedule::command('dashboard:sync')->everyFifteenMinutes()->withoutOverlapping();
