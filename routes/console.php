<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('kantoor:check')->everyMinute()->withoutOverlapping();
