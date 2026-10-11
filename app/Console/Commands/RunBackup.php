<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupEncryption;
use App\Support\Backup\WebDavTarget;
use App\Support\DatabaseDump;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

#[Signature('backup:run')]
#[Description('Back up the database and uploaded files into one ZIP (daily via the scheduler; older backups are removed)')]
class RunBackup extends Command
{
    public function handle(): int
    {
        $directory = (string) config('backup.path');
        $keep = max(1, (int) config('backup.keep'));
        File::ensureDirectoryExists($directory);

        $name = 'e11lifeos-'.now()->format('Y-m-d-His').'.zip';
        $zipPath = $directory.DIRECTORY_SEPARATOR.$name;
        $dumpPath = tempnam(sys_get_temp_dir(), 'e11-db-');

        try {
            $tables = DatabaseDump::toFile($dumpPath);

            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Cannot create {$zipPath}.");
            }

            $zip->addFile($dumpPath, 'database.sql');
            $files = $this->addDirectory($zip, storage_path('app'), 'files', realpath($directory) ?: $directory);
            // What the backup must contain – the weekly restore test (backup:verify) checks against it.
            $zip->addFromString('manifest.json', (string) json_encode([
                'app' => config('app.name'),
                'created_at' => now()->toIso8601String(),
                'driver' => DB::connection()->getDriverName(),
                'tables' => $tables,
                'files' => $files,
            ], JSON_PRETTY_PRINT));
            $zip->close();
        } catch (Throwable $exception) {
            File::delete($zipPath);
            report($exception);
            $this->error('Backup failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            File::delete($dumpPath);
        }

        $removed = $this->removeOldBackups($directory, $keep);
        $this->info("Backup {$name} created ({$files} files, ".round(filesize($zipPath) / 1048576, 1)." MB); {$removed} old backup(s) removed.");

        return $this->copyOffServer($zipPath, $name) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Encrypts the backup and uploads it to the WebDAV folder, if one is set up.
     */
    private function copyOffServer(string $zipPath, string $name): bool
    {
        $target = WebDavTarget::fromConfig();

        if (! $target) {
            return true;
        }

        if (blank(config('backup.encryption_password'))) {
            $this->warn('Off-server copy skipped: set an encryption password first.');

            return true;
        }

        $encrypted = $zipPath.'.enc';

        try {
            BackupEncryption::encrypt($zipPath, $encrypted, (string) config('backup.encryption_password'));
            $target->upload($encrypted, $name.'.enc');
            $pruned = $target->prune(max(1, (int) config('backup.remote_keep')));
            Cache::store('monitoring')->forever('backup:remote-last', now()->toIso8601String());
            $this->info("Encrypted copy uploaded; {$pruned} old copy/copies removed there.");

            return true;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Off-server copy failed: '.$exception->getMessage());

            return false;
        } finally {
            File::delete($encrypted);
        }
    }

    /**
     * Adds all files below $path (except the backup folder itself); returns how many.
     */
    private function addDirectory(ZipArchive $zip, string $path, string $prefix, string $exclude): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $count = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $real = $file->getRealPath();

            if (! $file->isFile() || $real === false || str_starts_with($real, $exclude.DIRECTORY_SEPARATOR) || $file->getFilename() === '.gitignore') {
                continue;
            }

            $zip->addFile($real, $prefix.'/'.ltrim(str_replace('\\', '/', substr($real, strlen(realpath($path)))), '/'));
            $count++;
        }

        return $count;
    }

    private function removeOldBackups(string $directory, int $keep): int
    {
        $backups = collect(File::glob($directory.DIRECTORY_SEPARATOR.'e11lifeos-*.zip'))->sort()->values();
        $old = $backups->slice(0, max(0, $backups->count() - $keep));
        $old->each(fn (string $file) => File::delete($file));

        return $old->count();
    }
}
