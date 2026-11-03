<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reports:refresh-summaries')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('email:poll-inbound')->everyMinute()->withoutOverlapping();
Schedule::command('email:prune-inbound-log')->daily();

// make sure php artisan schedule:run runs every minute from cron
Schedule::call(function () {
    Artisan::call('schedule:run');
})->everyMinute();