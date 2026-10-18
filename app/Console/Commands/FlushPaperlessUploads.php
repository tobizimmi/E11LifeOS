<?php

namespace App\Console\Commands;

use App\Support\Paperless\PaperlessUploader;
use Illuminate\Console\Command;

/**
 * Retries scanned/uploaded documents that could not reach paperless when they were created.
 */
class FlushPaperlessUploads extends Command
{
    protected $signature = 'paperless:flush-uploads';

    protected $description = 'Retry queued paperless uploads (scans stored while the instance was unreachable)';

    public function handle(PaperlessUploader $uploader): int
    {
        $sent = $uploader->flush();
        $this->info($sent === 0 ? 'Nothing to send.' : $sent.' document(s) sent to paperless.');

        return self::SUCCESS;
    }
}
