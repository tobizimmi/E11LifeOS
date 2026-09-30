<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupEncryption;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('backup:decrypt {file : The .zip.enc file} {target? : Where to write the ZIP (default: next to it)}')]
#[Description('Decrypt an off-server backup copy (asks for the encryption password)')]
class DecryptBackup extends Command
{
    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $target = (string) ($this->argument('target') ?: preg_replace('/\.enc$/', '', $file));

        if ($target === $file) {
            $target .= '.zip';
        }

        $password = (string) $this->secret('Encryption password');

        try {
            BackupEncryption::decrypt($file, $target, $password);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Decrypted to {$target}.");

        return self::SUCCESS;
    }
}
