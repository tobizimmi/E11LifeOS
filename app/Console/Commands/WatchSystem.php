<?php

namespace App\Console\Commands;

use App\Support\Operations\AdminAlert;
use App\Support\Operations\LogFiles;
use App\Support\Operations\SystemHealth;
use App\Support\SmartHome\ConnectionWatch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('system:watch')]
#[Description('Checks scheduler, jobs, disk space, backups and the smart home connections and alerts when something turns red or is fine again')]
class WatchSystem extends Command
{
    public function handle(ConnectionWatch $smartHome): int
    {
        // A log file another user recreated would make every log write (and the command) fail.
        LogFiles::repair();

        // Home Assistant and evcc of the households: the owner hears about outages.
        $smartHome->check();

        $checks = SystemHealth::checks();
        $before = Cache::get(SystemHealth::STATE_KEY, []);

        foreach ($checks as $key => $check) {
            $wasOk = $before[$key] ?? true;

            if ($wasOk && ! $check['ok']) {
                AdminAlert::send('health:'.$key.':down', '⚠️ '.$check['label'], $check['detail'], 60 * 6);
            } elseif (! $wasOk && $check['ok']) {
                AdminAlert::send('health:'.$key.':up', '✅ '.$check['label'], __('Fine again: :detail', ['detail' => $check['detail']]), 60);
            }
        }

        Cache::forever(SystemHealth::STATE_KEY, array_map(fn (array $check) => $check['ok'], $checks));
        $this->info(collect($checks)->map(fn (array $check, string $key) => $key.'='.($check['ok'] ? 'ok' : 'FAIL'))->implode(' '));

        return self::SUCCESS;
    }
}
