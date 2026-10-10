<?php

namespace App\Jobs;

use App\Support\AppSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs a long artisan command (e.g. a data import) started on the admin page in the queue
 * worker and keeps its output for the page.
 */
class RunAdminCommand implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3000;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(public string $name, public string $command, public array $arguments = [])
    {
        $this->onQueue('admin');
    }

    public static function cacheKey(string $name): string
    {
        return 'admin-command:'.$name;
    }

    public function handle(): void
    {
        // A long-running queue worker keeps the configuration from its boot. Re-apply the admin
        // settings and drop the legacy import connections so freshly entered values (e.g. the
        // RezepteApp2 / portfolio database credentials) take effect for this run.
        AppSettings::apply();

        foreach (['rezepteapp', 'portfolio_legacy'] as $connection) {
            DB::purge($connection);
        }

        Cache::forever(self::cacheKey($this->name), ['status' => 'running', 'at' => now()->toIso8601String(), 'output' => '']);

        try {
            $exitCode = Artisan::call($this->command, $this->arguments);
            $status = $exitCode === 0 ? 'done' : 'failed';
            $output = Artisan::output();
        } catch (Throwable $exception) {
            $status = 'failed';
            $output = $exception->getMessage();
        }

        Cache::forever(self::cacheKey($this->name), ['status' => $status, 'at' => now()->toIso8601String(), 'output' => mb_substr(trim($output), -4000)]);
    }
}
