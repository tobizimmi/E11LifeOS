<?php

namespace App\Support\Operations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * The health of the server at a glance: scheduler, background jobs, disk space, backups and the
 * last restore test. The watcher (system:watch) alerts the admins when a check turns red.
 */
class SystemHealth
{
    public const STATE_KEY = 'health:state';

    public const VERIFY_KEY = 'backup:verify-last';

    /** Free disk space below this share or size counts as a problem. */
    private const MIN_FREE_SHARE = 0.10;

    private const MIN_FREE_BYTES = 2 * 1024 ** 3;

    /**
     * @return array<string, array{ok: bool, label: string, detail: string}>
     */
    public static function checks(): array
    {
        return [
            'scheduler' => self::scheduler(),
            'failed_jobs' => self::failedJobs(),
            'stuck_jobs' => self::stuckJobs(),
            'disk' => self::disk(),
            'backup' => self::backup(),
            'backup_remote' => self::remoteBackup(),
            'restore_test' => self::restoreTest(),
        ];
    }

    /**
     * @return array{ok: bool, label: string, detail: string}
     */
    private static function scheduler(): array
    {
        $heartbeat = Cache::get('scheduler:heartbeat');
        $at = $heartbeat ? Carbon::parse($heartbeat) : null;

        return [
            'ok' => $at !== null && $at->gt(now()->subMinutes(5)),
            'label' => __('Scheduler'),
            'detail' => $at ? __('Last run :time', ['time' => $at->diffForHumans()]) : __('Never ran'),
        ];
    }

    /**
     * New failed jobs since the admin last looked (deleting them on the admin page clears this).
     *
     * @return array{ok: bool, label: string, detail: string}
     */
    private static function failedJobs(): array
    {
        $recent = rescue(fn () => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(), 0, false);

        return [
            'ok' => $recent === 0,
            'label' => __('Background jobs'),
            'detail' => $recent === 0 ? __('No failures in the last 24 hours') : trans_choice(':count job failed in the last 24 hours|:count jobs failed in the last 24 hours', $recent),
        ];
    }

    /**
     * @return array{ok: bool, label: string, detail: string}
     */
    private static function stuckJobs(): array
    {
        $oldest = rescue(fn () => Schema::hasTable('jobs') ? DB::table('jobs')->whereNull('reserved_at')->min('available_at') : null, null, false);
        $waiting = $oldest ? now()->timestamp - (int) $oldest : 0;

        return [
            'ok' => $waiting < 30 * 60,
            'label' => __('Job queue'),
            'detail' => $oldest ? __('Oldest job waits for :minutes min', ['minutes' => intdiv($waiting, 60)]) : __('Empty'),
        ];
    }

    /**
     * @return array{ok: bool, label: string, detail: string}
     */
    private static function disk(): array
    {
        $path = storage_path();
        $free = (float) @disk_free_space($path);
        $total = (float) @disk_total_space($path);

        if ($total <= 0) {
            return ['ok' => true, 'label' => __('Disk space'), 'detail' => __('Unknown')];
        }

        return [
            'ok' => $free / $total >= self::MIN_FREE_SHARE || $free >= self::MIN_FREE_BYTES,
            'label' => __('Disk space'),
            'detail' => __(':free GB of :total GB free', ['free' => number_format($free / 1024 ** 3, 1, ',', '.'), 'total' => number_format($total / 1024 ** 3, 1, ',', '.')]),
        ];
    }

    /**
     * @return array{ok: bool, label: string, detail: string}
     */
    private static function backup(): array
    {
        $latest = self::latestBackup();
        $at = $latest ? Carbon::createFromTimestamp((int) filemtime($latest)) : null;

        return [
            'ok' => $at !== null && $at->gt(now()->subHours(26)),
            'label' => __('Daily backup'),
            'detail' => $at ? __('Last backup :time', ['time' => $at->diffForHumans()]) : __('No backup yet'),
        ];
    }

    /**
     * @return array{ok: bool, label: string, detail: string}
     */
    private static function remoteBackup(): array
    {
        if (blank(config('backup.remote_url')) || blank(config('backup.encryption_password'))) {
            return ['ok' => true, 'label' => __('Copy off the server'), 'detail' => __('Not set up')];
        }

        $last = Cache::get('backup:remote-last');
        $at = $last ? Carbon::parse($last) : null;

        return [
            'ok' => $at !== null && $at->gt(now()->subHours(26)),
            'label' => __('Copy off the server'),
            'detail' => $at ? __('Last copy :time', ['time' => $at->diffForHumans()]) : __('No copy yet'),
        ];
    }

    /**
     * @return array{ok: bool, label: string, detail: string}
     */
    private static function restoreTest(): array
    {
        $last = Cache::get(self::VERIFY_KEY);

        if (! is_array($last)) {
            return ['ok' => true, 'label' => __('Restore test'), 'detail' => __('Runs every Sunday night')];
        }

        $at = Carbon::parse($last['at']);

        return [
            'ok' => (bool) $last['ok'] && $at->gt(now()->subDays(9)),
            'label' => __('Restore test'),
            'detail' => ($last['ok'] ? '✓ ' : '✗ ').$at->diffForHumans().' · '.($last['summary'] ?? ''),
        ];
    }

    public static function latestBackup(): ?string
    {
        $files = File::glob(rtrim((string) config('backup.path'), '/').'/e11lifeos-*.zip');

        return $files === [] ? null : collect($files)->sort()->last();
    }
}
