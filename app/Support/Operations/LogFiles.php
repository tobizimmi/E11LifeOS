<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\File;

/**
 * Log files this process cannot write to (e.g. recreated by a log rotation or by a process running
 * as another user): every write then fails. Such a file is moved aside (the folder belongs to the
 * app) so the next write creates a fresh one. Web requests and console processes each have their
 * own file (laravel.log / laravel-cli.log, see config/logging.php) and only look at that one.
 */
class LogFiles
{
    /**
     * @return list<string> files that are not writable
     */
    public static function unwritable(): array
    {
        $base = pathinfo((string) config('logging.channels.single.path', storage_path('logs/laravel.log')), PATHINFO_FILENAME);
        $files = [...File::glob(storage_path('logs').'/'.$base.'.log'), ...File::glob(storage_path('logs').'/'.$base.'-[0-9]*.log')];

        return array_values(array_filter($files, fn (string $file) => ! is_writable($file)));
    }

    /**
     * Moves unwritable log files aside; returns the files that could not be moved.
     *
     * @return list<string>
     */
    public static function repair(): array
    {
        if (! is_writable(storage_path('logs'))) {
            return self::unwritable();
        }

        foreach (self::unwritable() as $file) {
            @rename($file, $file.'.unwritable-'.now()->format('YmdHis'));
        }

        return self::unwritable();
    }
}
