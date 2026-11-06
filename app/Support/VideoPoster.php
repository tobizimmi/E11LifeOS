<?php

namespace App\Support;

use App\Models\Attachment;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Extracts a poster frame (first visible frame) from an uploaded video with ffmpeg and stores it
 * next to the video on the secure disk, so previews and PDF reports can show a still image.
 *
 * Best-effort: if ffmpeg is not installed or the extraction fails, it returns null and the caller
 * simply keeps the video without a poster (the report then shows a labelled tile). The secure disk
 * is encrypted, so the bytes are round-tripped through a temporary plaintext file for ffmpeg.
 */
class VideoPoster
{
    public static function generate(Attachment $attachment): ?string
    {
        if (! $attachment->isVideo()) {
            return null;
        }

        $disk = Storage::disk(Attachment::DISK);
        $binary = (string) (config('services.ffmpeg.binary') ?: 'ffmpeg');
        $tmp = sys_get_temp_dir();
        $input = $tmp.'/'.Str::uuid().'.'.(pathinfo($attachment->path, PATHINFO_EXTENSION) ?: 'mp4');
        $output = $tmp.'/'.Str::uuid().'.jpg';

        try {
            if (! $disk->exists($attachment->path)) {
                return null;
            }

            file_put_contents($input, (string) $disk->get($attachment->path));

            // One frame near the start, scaled to a sensible width; -y overwrites, timeout guards hangs.
            $result = Process::timeout(30)->run([
                $binary, '-y', '-ss', '0', '-i', $input, '-frames:v', '1', '-vf', 'scale=640:-1', $output,
            ]);

            if (! $result->successful() || ! is_file($output) || filesize($output) === 0) {
                return null;
            }

            $posterPath = 'attachments/'.$attachment->household_id.'/'.Str::uuid().'-poster.jpg';
            $disk->put($posterPath, (string) file_get_contents($output));

            return $posterPath;
        } catch (\Throwable) {
            return null;
        } finally {
            if (is_file($input)) {
                @unlink($input);
            }

            if (is_file($output)) {
                @unlink($output);
            }
        }
    }
}
