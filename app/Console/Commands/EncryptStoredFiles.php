<?php

namespace App\Console\Commands;

use App\Support\Security\EncryptedFilesystemAdapter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('files:encrypt')]
#[Description('Encrypt documents, receipts and handover photos that were stored before encryption')]
class EncryptStoredFiles extends Command
{
    /** Folders of the "secure" disk. */
    public const FOLDERS = ['attachments', 'external', 'business-receipts', 'real-estate', 'handovers'];

    public function handle(): int
    {
        $raw = Storage::build(['driver' => 'local', 'root' => config('filesystems.disks.secure.root')]);
        $secure = Storage::disk('secure');
        $count = 0;

        foreach (self::FOLDERS as $folder) {
            foreach ($raw->allFiles($folder) as $path) {
                $contents = (string) $raw->get($path);

                if (! EncryptedFilesystemAdapter::isEncrypted($contents)) {
                    $secure->put($path, $contents);
                    $count++;
                }
            }
        }

        $this->info("Encrypted files: {$count}");

        return self::SUCCESS;
    }
}
