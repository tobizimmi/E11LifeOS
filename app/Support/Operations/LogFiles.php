<?php

namespace App\Support\Operations;

use Illuminate\Support\Facades\File;

/**
 * Log files the app cannot write to (e.g. recreated by a log rotation or a cron job running as
 * another user): every write then fails and scheduled commands abort. Such a file is moved aside
 * (the folder belongs to the app) so the next write creates a fresh one.
 */
class LogFiles
{
    /**
     * @return list<string> files that are not writable
     */
    public static function unwritable(): array
    {
        return array_values(array_filter(File::glob(storage_path('logs').'/*.log'), fn (string $file) => ! is_writable($file)));
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
