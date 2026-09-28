<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs "php artisan schedule:run" every minute on the server (cron) or "php artisan schedule:work".
Schedule::command('collections:deadline-tasks')->dailyAt('06:45');
Schedule::command('real-estate:reminders')->dailyAt('07:10');
Schedule::command('reminders:send')->dailyAt('07:00');
Schedule::command('reminders:timed')->everyMinute()->withoutOverlapping();
Schedule::command('digest:weekly')->hourlyAt(2)->withoutOverlapping();
Schedule::command('briefing:morning')->hourlyAt(0)->withoutOverlapping();
Schedule::command('finance:check-budgets')->dailyAt('19:05');
Schedule::command('backup:run')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('business:run-recurring')->dailyAt('06:30')->withoutOverlapping();
Schedule::command('business:overdue-notices')->dailyAt('08:05');
Schedule::command('portfolio:update-prices')->everyFifteenMinutes()->weekdays()->between('07:30', '22:30')->withoutOverlapping();
Schedule::command('portfolio:update-prices --history')->dailyAt('23:10')->withoutOverlapping();
Schedule::command('portfolio:check-alerts')->everyFifteenMinutes()->between('07:30', '22:45')->withoutOverlapping();
Schedule::command('portfolio:execute-savings-plans')->dailyAt('09:15');
Schedule::command('portfolio:market-scan')->dailyAt('06:10')->withoutOverlapping();
Schedule::command('portfolio:fetch-news')->hourly()->between('06:00', '23:00')->withoutOverlapping();
Schedule::command('banking:sync')->dailyAt('05:40')->withoutOverlapping();
Schedule::command('portfolio:instagram')->everySixHours()->withoutOverlapping();

// Heartbeat for the admin page (shows whether the cron job runs) and the queue for imports started there.
Schedule::call(fn () => Cache::forever('scheduler:heartbeat', now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');
Schedule::command('queue:work --queue=admin --stop-when-empty --max-time=3000 --tries=1')->everyMinute()->withoutOverlapping(60)->runInBackground();
