<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('locations-feed:run --daily')
    ->dailyAt('00:00')
    ->timezone('UTC')
    ->withoutOverlapping();

Schedule::command('locations-feed:run --resubmit')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
