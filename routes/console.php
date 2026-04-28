<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

$behaviourTz = 'Europe/London';

Schedule::command('behaviour:import-staff-directory')
    ->dailyAt('07:00')
    ->timezone($behaviourTz)
    ->withoutOverlapping(30);

Schedule::command('behaviour:import-tutor-hoy')
    ->dailyAt('07:02')
    ->timezone($behaviourTz)
    ->withoutOverlapping(30);

Schedule::command('behaviour:import-parents')
    ->dailyAt('07:04')
    ->timezone($behaviourTz)
    ->withoutOverlapping(30);

// 08:00 – evaluate and log notifications (uses previous school day)
Schedule::command('behaviour:evaluate')
    ->dailyAt('08:00')
    ->timezone($behaviourTz)
    ->withoutOverlapping(30);

// 08:05 – send emails for today’s logs
Schedule::command('behaviour:send-today')
    ->dailyAt('08:05')
    ->timezone($behaviourTz)
    ->withoutOverlapping(30);

// Realtime notification sends for realtime_today groups.
Schedule::command('behaviour:realtime')
    ->everyFifteenMinutes()
    ->timezone($behaviourTz)
    ->withoutOverlapping(10);

Schedule::command('writeback:FetchSystemData')->hourlyAt(59);
Schedule::command('writeback:Fetchloggeditems')->everyFourMinutes();
Schedule::command('writeback:processAll')->everyMinute()->withoutOverlapping(2);
Schedule::command('writeback:reconcile-statuses --chunk=1000')->dailyAt('03:30')->withoutOverlapping(10);
