<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:release-expired-holds')->everyMinute()->withoutOverlapping();
