<?php

namespace App\Console\Commands;

use App\Models\HouseholdInvite;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('app:install {--invite-days=3 : Days until the first invite code expires}')]
#[Description('Set up or update an installation: check .env and database, migrate, create push keys and the first invite code (safe to run again)')]
class InstallApp extends Command
{
    public function handle(): int
    {
        if (blank(config('app.key'))) {
            $this->error(__('APP_KEY is missing in .env. Add it once and never change it afterwards.'));

            return self::FAILURE;
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            $this->error(__('The database is not reachable: :message', ['message' => $exception->getMessage()]));

            return self::FAILURE;
        }

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $this->createPushKeys();
        $this->inviteFirstAccount();
        $this->printChecklist();

        return self::SUCCESS;
    }

    /**
     * Web push needs one key pair per installation; changing it later means every device has to allow notifications again.
     */
    private function createPushKeys(): void
    {
        if (filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'))) {
            return;
        }

        $keys = WebPush::generateKeys();
        AppSettings::save([
            'vapid_public_key' => $keys['publicKey'],
            'vapid_private_key' => $keys['privateKey'],
            'vapid_subject' => filled(config('services.webpush.subject')) ? null : 'mailto:'.config('mail.from.address'),
        ]);

        $this->info(__('Push keys created and saved in the server settings.'));
    }

    /**
     * Without any account yet, prints an invite code for the first registration (that account becomes administrator).
     */
    private function inviteFirstAccount(): void
    {
        if (User::query()->exists()) {
            if (! User::query()->where('is_admin', true)->exists()) {
                $this->warn(__('No administrator yet: php artisan user:admin your@email'));
            }

            return;
        }

        $invite = HouseholdInvite::create([
            'household_id' => null,
            'code' => HouseholdInvite::generateCode(),
            'created_by' => null,
            'expires_at' => now()->addDays(max(1, (int) $this->option('invite-days'))),
        ]);

        $this->newLine();
        $this->info(__('First account: register within :days days at', ['days' => max(1, (int) $this->option('invite-days'))]));
        $this->line('  '.route('register', ['code' => $invite->code]));
        $this->line('  '.__('Invite code: :code', ['code' => $invite->code]));
        $this->line('  '.__('This first account automatically becomes administrator.'));
        $this->newLine();
    }

    /**
     * Shows what is still open; nothing here stops the installation.
     */
    private function printChecklist(): void
    {
        $checks = [
            __('Debug mode off') => ! config('app.debug') || ! app()->isProduction(),
            __('Address uses https') => str_starts_with((string) config('app.url'), 'https://') || ! app()->isProduction(),
            __('E-mail sending') => config('mail.default') !== 'log' && config('mail.default') !== 'array',
            __('Error reports by e-mail') => filled(config('security.error_report_email')),
            __('Backup password (for the copy off site)') => filled(config('backup.encryption_password')),
            __('Backup copy off site') => filled(config('backup.remote_url')),
            __('AI key (optional)') => filled(config('services.anthropic.key')),
        ];

        $this->table([__('Check'), __('Status')], collect($checks)->map(fn (bool $isDone, string $label) => [$label, $isDone ? '✓' : __('open')])->values()->all());
        $this->line(__('Open points can be entered in the app under Server settings (profile menu).'));
        $this->warn(__('Keep APP_KEY and the backup password in your password manager – without them encrypted data and backups cannot be read.'));
    }
}
