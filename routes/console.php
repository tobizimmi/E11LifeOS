<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs "php artisan schedule:run" every minute on the server (cron) or "php artisan schedule:work".
Schedule::command('reminders:send')->dailyAt('07:00');
Schedule::command('reminders:timed')->everyMinute()->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('business:run-recurring')->dailyAt('06:30')->withoutOverlapping();
Schedule::command('business:overdue-notices')->dailyAt('08:05');
