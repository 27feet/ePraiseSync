<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

//Schedule::command('behaviour:import-staff')->dailyAt('07:00');
//Schedule::command('behaviour:import-tutor-hoy')->dailyAt('07:02');
//Schedule::command('behaviour:import-parents')->dailyAt('07:04');

// 08:00 – evaluate and log notifications (uses previous school day)
//Schedule::command('behaviour:evaluate')->dailyAt('08:00');

// 08:05 – send emails for today’s logs
//Schedule::command('behaviour:send-today')->dailyAt('08:05');


Schedule::command('writeback:FetchSystemData')->hourlyAt(59);
Schedule::command('writeback:Fetchloggeditems')->everyFourMinutes();
Schedule::command('writeback:processAll')->everyMinute()->withoutOverlapping();
