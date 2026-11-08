<?php

use App\Models\ApiToken;
use App\Support\Helpdesk\Tickets;
use App\Support\Security\SecurityLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs "php artisan schedule:run" every minute on the server (cron) or "php artisan schedule:work".
Schedule::command('collections:deadline-tasks')->dailyAt('06:45');
Schedule::command('life:deadline-tasks')->dailyAt('06:48');
Schedule::command('calendar:sync-google')->hourlyAt(20)->withoutOverlapping();
Schedule::command('real-estate:reminders')->dailyAt('07:10');
Schedule::command('real-estate:purge-applicants')->dailyAt('03:40');
Schedule::command('net-worth:snapshot')->dailyAt('23:40');
Schedule::command('reminders:send')->dailyAt('07:00');
Schedule::command('life:reminders')->dailyAt('07:05');
Schedule::call(function (): void {
    SecurityLog::prune();
})->dailyAt('03:40')->name('security-log-prune')->withoutOverlapping();
Schedule::call(function (): void {
    ApiToken::pruneExpired();
})->dailyAt('03:45')->name('api-token-prune')->withoutOverlapping();
Schedule::call(function (): void {
    Tickets::closeSolved();
})->dailyAt('03:50')->name('support-close-solved')->withoutOverlapping();
Schedule::command('kids:allowance')->dailyAt('06:10')->withoutOverlapping();
Schedule::command('emergency:grant-access')->everyFifteenMinutes();
Schedule::command('health:medication-reminders')->everyMinute()->withoutOverlapping();
Schedule::command('reminders:timed')->everyMinute()->withoutOverlapping();
Schedule::command('habits:remind')->everyMinute()->withoutOverlapping();
Schedule::command('automations:run')->everyMinute()->withoutOverlapping(5);
Schedule::command('smart-home:energy')->everyFiveMinutes()->withoutOverlapping(5);
Schedule::command('smart-home:energy --close')->dailyAt('00:10')->withoutOverlapping();
Schedule::command('digest:weekly')->hourlyAt(2)->withoutOverlapping();
Schedule::command('briefing:morning')->hourlyAt(0)->withoutOverlapping();
Schedule::command('finance:check-budgets')->dailyAt('19:05');
Schedule::command('wishlist:check-prices')->dailyAt('08:20')->withoutOverlapping();
Schedule::command('recipes:prune-temporary')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('finance:watch-subscriptions')->dailyAt('07:40')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('backup:verify')->weeklyOn(0, '04:30')->withoutOverlapping();
Schedule::command('system:watch')->everyTenMinutes()->withoutOverlapping();
Schedule::command('business:run-recurring')->dailyAt('06:30')->withoutOverlapping();
Schedule::command('business:overdue-notices')->dailyAt('08:05');
Schedule::command('portfolio:update-prices')->everyFifteenMinutes()->weekdays()->between('07:30', '22:30')->withoutOverlapping();
Schedule::command('portfolio:update-prices --history')->dailyAt('23:10')->withoutOverlapping();
Schedule::command('portfolio:check-alerts')->everyFifteenMinutes()->between('07:30', '22:45')->withoutOverlapping();
Schedule::command('portfolio:execute-savings-plans')->dailyAt('09:15');
Schedule::command('portfolio:market-scan')->dailyAt('06:10')->withoutOverlapping();
Schedule::command('portfolio:fetch-news')->hourly()->between('06:00', '23:00')->withoutOverlapping();
Schedule::command('banking:sync')->dailyAt('05:40')->withoutOverlapping();
Schedule::command('banking:sync-links')->dailyAt('05:50')->withoutOverlapping();
Schedule::command('portfolio:instagram')->everySixHours()->withoutOverlapping();

// Heartbeat for the admin page (shows whether the cron job runs) and the queue for imports started there.
// Callbacks return nothing: a returned false (e.g. a cache write that changed no row) counts as a failed task.
Schedule::call(function (): void {
    Cache::store('monitoring')->forever('scheduler:heartbeat', now()->toIso8601String());
})->everyMinute()->name('scheduler-heartbeat');
Schedule::command('queue:work --queue=admin --stop-when-empty --max-time=3000 --tries=1')->everyMinute()->withoutOverlapping(60)->runInBackground();
Schedule::command('maps:warm')->everySixHours();
Schedule::command('push:release-quiet')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('mail:fetch')->everyTwoMinutes()->withoutOverlapping(10);
Schedule::command('paperless:flush-uploads')->everyFiveMinutes()->withoutOverlapping(10);
