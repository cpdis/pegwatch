<?php

use Illuminate\Support\Facades\Schedule;

// Needs the scheduler running (on Laravel Cloud: enable it for the app).
Schedule::command('pegwatch:release-reminders')->everyFifteenMinutes()->withoutOverlapping();
// Unconfirmed watches a day after their link expired, and accounts nobody confirmed.
Schedule::command('model:prune')->daily();
