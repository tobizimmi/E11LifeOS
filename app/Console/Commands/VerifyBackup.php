<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupVerifier;
use App\Support\Operations\AdminAlert;
use App\Support\Operations\SystemHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('backup:verify')]
#[Description('Restore test of the latest backup: archive, dump, row counts and files (weekly via the scheduler)')]
class VerifyBackup extends Command
{
    public function handle(): int
    {
        $latest = SystemHealth::latestBackup();

        if (! $latest) {
            $this->error('No backup found.');

            return self::FAILURE;
        }

        $result = BackupVerifier::verify($latest);
        $summary = __(':tables tables, :rows rows, :files files', ['tables' => $result['tables'], 'rows' => $result['rows'], 'files' => $result['files']])
            .($result['restored'] ? ' · '.__('loaded into a test database') : '');

        Cache::forever(SystemHealth::VERIFY_KEY, [
            'at' => now()->toIso8601String(),
            'ok' => $result['ok'],
            'file' => basename($latest),
            'summary' => $summary,
            'problems' => array_slice($result['problems'], 0, 10),
        ]);

        if (! $result['ok']) {
            AdminAlert::send('restore-test', __('Restore test failed'), basename($latest).":\n".implode("\n", array_slice($result['problems'], 0, 10)), 60 * 24);
            $this->error('Restore test failed: '.implode(' | ', $result['problems']));

            return self::FAILURE;
        }

        $this->info('Restore test passed: '.basename($latest).' – '.$summary);

        return self::SUCCESS;
    }
}
