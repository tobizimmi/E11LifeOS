<?php

use App\Jobs\RunAdminCommand;
use App\Support\AppSettings;
use App\Support\BrainAi;
use App\Support\WebPush;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    /**
     * Plain values; secret fields start empty (empty = keep the saved value).
     *
     * @var array<string, string>
     */
    public array $form = [];

    /**
     * Secret fields to remove on save.
     *
     * @var array<string, bool>
     */
    public array $clear = [];

    public string $testRecipient = '';

    public function mount(): void
    {
        $this->authorize('admin');
        $this->fillForm();
        $this->testRecipient = Auth::user()->email;
    }

    private function fillForm(): void
    {
        $stored = AppSettings::stored();
        $this->form = collect(AppSettings::FIELDS)
            ->map(fn (array $field, string $key) => $field['secret'] ? '' : ($stored[$key] ?? ''))
            ->all();
        $this->clear = [];
    }

    public function save(): void
    {
        $this->authorize('admin');

        $this->validate([
            'form.mail_port' => ['nullable', 'integer', 'between:1,65535'],
            'form.mail_scheme' => ['nullable', 'in:smtp,smtps'],
            'form.mail_from_address' => ['nullable', 'email'],
            'form.vapid_subject' => ['nullable', 'regex:/^(mailto:|https:\/\/)/'],
            'form.instagram_limit' => ['nullable', 'integer', 'between:1,100'],
            'form.backup_keep' => ['nullable', 'integer', 'between:1,365'],
            'form.legacy_db_port' => ['nullable', 'integer', 'between:1,65535'],
            'form.rezepteapp_db_port' => ['nullable', 'integer', 'between:1,65535'],
            'form.*' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['form.mail_from_address' => __('Sender address'), 'form.vapid_subject' => __('Contact')]);

        $values = [];

        foreach (AppSettings::FIELDS as $key => $field) {
            $value = (string) ($this->form[$key] ?? '');

            if ($field['secret']) {
                // Secrets are never shown again: empty keeps the saved value unless "remove" is ticked.
                $values[$key] = ($this->clear[$key] ?? false) ? '' : ($value === '' ? null : $value);
            } else {
                $values[$key] = $value;
            }
        }

        AppSettings::save($values);
        $this->fillForm();
        $this->dispatch('toast', message: __('Settings saved.'));
    }

    /**
     * Creates the key pair for web push (only when none exists, changing it breaks every subscription).
     */
    public function generateVapidKeys(): void
    {
        $this->authorize('admin');

        if (filled(config('services.webpush.public_key'))) {
            $this->dispatch('toast', message: __('Push keys already exist.'));

            return;
        }

        $keys = WebPush::generateKeys();
        AppSettings::save([
            'vapid_public_key' => $keys['publicKey'],
            'vapid_private_key' => $keys['privateKey'],
            'vapid_subject' => AppSettings::stored()['vapid_subject'] ?? 'mailto:'.Auth::user()->email,
        ]);
        $this->fillForm();
        $this->dispatch('toast', message: __('Push keys created.'));
    }

    public function sendTestMail(): void
    {
        $this->authorize('admin');
        $this->validate(['testRecipient' => ['required', 'email']]);

        try {
            Mail::raw(__('This is a test message from :app. Sending e-mails works.', ['app' => config('app.name')]), function ($message) {
                $message->to($this->testRecipient)->subject(__('Test e-mail from :app', ['app' => config('app.name')]));
            });
            $this->dispatch('toast', message: config('mail.default') === 'log' ? __('Mail sending is switched off (log). Enter a mail server first.') : __('Test e-mail sent to :email.', ['email' => $this->testRecipient]));
        } catch (\Throwable $exception) {
            $this->addError('testRecipient', __('Sending failed: :message', ['message' => mb_substr($exception->getMessage(), 0, 300)]));
        }
    }

    /**
     * Starts an import in the background (queue worker started by the scheduler every minute).
     */
    public function startImport(string $name): void
    {
        $this->authorize('admin');

        $command = match ($name) {
            'portfolio' => 'portfolio:import-legacy',
            'recipes' => 'recipes:import-rezepteapp',
            default => abort(404),
        };

        Cache::forever(RunAdminCommand::cacheKey($name), ['status' => 'queued', 'at' => now()->toIso8601String(), 'output' => '']);
        RunAdminCommand::dispatch($name, $command, ['email' => Auth::user()->email]);
        $this->dispatch('toast', message: __('Import started. It runs in the background within the next minute.'));
    }

    public function with(): array
    {
        $stored = AppSettings::stored();
        $heartbeat = Cache::get('scheduler:heartbeat');

        return [
            'stored' => $stored,
            // Values in effect without a saved one (.env or built-in default), shown as placeholder.
            'current' => collect(AppSettings::FIELDS)->filter(fn (array $field, string $key) => ! isset($stored[$key]) && filled(AppSettings::current($key)))->map(fn (array $field, string $key) => (string) AppSettings::current($key))->all(),
            'heartbeat' => $heartbeat ? Carbon::parse($heartbeat) : null,
            'mailer' => config('mail.default'),
            'imports' => ['portfolio' => Cache::get(RunAdminCommand::cacheKey('portfolio')), 'recipes' => Cache::get(RunAdminCommand::cacheKey('recipes'))],
            'failedJobs' => rescue(fn () => DB::table('failed_jobs')->count(), 0, false),
            'status' => [
                __('AI (Claude)') => BrainAi::isConfigured(),
                __('Sending e-mails') => config('mail.default') !== 'log' && config('mail.default') !== 'array',
                __('Web push') => filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key')),
                __('Bank sync (FinTS)') => filled(config('services.fints.product_id')),
                __('Instagram analysis') => filled(config('services.instagram.apify_key')) && BrainAi::isConfigured(),
            ],
        ];
    }
}; ?>

@php
    $groups = [
        'ai' => ['title' => __('AI (Claude)'), 'text' => __('For recipe import, reading contracts and bank statements, tag suggestions, “Ask your Second Brain” and the Instagram analysis. Key from console.anthropic.com → API Keys.')],
        'mail' => ['title' => __('E-mail'), 'text' => __('For daily reminders, “Forgot password” and sending invoices, quotes and reminders. Use the SMTP data of your mailbox (e.g. created in Plesk under “Mail”).')],
        'push' => ['title' => __('Web push'), 'text' => __('Key pair for push notifications. Create it once with the button and never change it afterwards, otherwise every device has to switch notifications on again.')],
        'portfolio' => ['title' => __('Portfolio'), 'text' => __('Keys for bank sync, Instagram analysis and optional data sources.')],
        'backup' => ['title' => __('Backups'), 'text' => __('Nightly ZIP backup of the database and uploaded files.')],
        'import_portfolio' => ['title' => __('Import: old portfolio manager'), 'text' => __('Access to the MySQL database of the old portfolio manager. Only needed for the one-time import; remove the values afterwards.')],
        'import_recipes' => ['title' => __('Import: RezepteApp2'), 'text' => __('Access to the MySQL database of RezepteApp2. Only needed for the one-time import; remove the values afterwards.')],
    ];
    $labels = [
        'anthropic_api_key' => [__('API key'), 'sk-ant-…'],
        'anthropic_model' => [__('Model (optional)'), 'claude-opus-5'],
        'mail_host' => [__('SMTP server'), 'zimmimail.de'],
        'mail_port' => [__('Port'), '587'],
        'mail_scheme' => [__('Encryption'), ''],
        'mail_username' => [__('User name'), 'lifeos@zimmimail.de'],
        'mail_password' => [__('Password'), ''],
        'mail_from_address' => [__('Sender address'), 'lifeos@zimmimail.de'],
        'mail_from_name' => [__('Sender name'), 'E11LifeOS'],
        'vapid_public_key' => [__('Public key'), ''],
        'vapid_private_key' => [__('Private key'), ''],
        'vapid_subject' => [__('Contact'), 'mailto:du@example.com'],
        'fints_product_id' => [__('FinTS product ID'), ''],
        'apify_api_key' => [__('Apify API token (Instagram)'), 'apify_api_…'],
        'instagram_profile' => [__('Instagram account'), 'heikothieme.club'],
        'instagram_limit' => [__('Posts per fetch'), '20'],
        'instagram_push_key' => [__('Push key for /api/instagram/push (optional)'), ''],
        'twelve_data_key' => [__('Twelve Data key (optional)'), ''],
        'openfigi_key' => [__('OpenFIGI key (optional)'), ''],
        'pushover_app_token' => [__('Pushover app token (optional)'), ''],
        'backup_path' => [__('Folder (empty = storage/backups)'), '/var/www/vhosts/zimmimail.de/backups/e11lifeos'],
        'backup_keep' => [__('Keep backups'), '14'],
        'legacy_db_host' => [__('Host'), '127.0.0.1'],
        'legacy_db_port' => [__('Port'), '3306'],
        'legacy_db_database' => [__('Database'), 'portfolio_manager'],
        'legacy_db_username' => [__('User name'), ''],
        'legacy_db_password' => [__('Password'), ''],
        'rezepteapp_db_host' => [__('Host'), '127.0.0.1'],
        'rezepteapp_db_port' => [__('Port'), '3306'],
        'rezepteapp_db_database' => [__('Database'), 'rezepteapp2'],
        'rezepteapp_db_username' => [__('User name'), ''],
        'rezepteapp_db_password' => [__('Password'), ''],
    ];
    $importNames = ['import_portfolio' => 'portfolio', 'import_recipes' => 'recipes'];
    $importStatus = ['queued' => __('waiting for the scheduler'), 'running' => __('running'), 'done' => __('finished'), 'failed' => __('failed')];
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">⚙️ {{ __('Server settings') }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Only visible to administrators. Values are stored encrypted and take precedence over the .env file.') }}</p>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Status') }}</h3>
                <ul class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                    <li class="flex items-center gap-2">
                        @if ($heartbeat && $heartbeat->gt(now()->subMinutes(5)))
                            <span class="text-emerald-600">✓</span> {{ __('Scheduler (cron) runs') }} <span class="text-xs text-gray-500">({{ $heartbeat->diffForHumans() }})</span>
                        @else
                            <span class="text-orange-600">✗</span> <span>{{ __('Scheduler (cron) does not run') }} <span class="block text-xs text-gray-500">{{ __('Reminders, prices, news, backups and imports need the cron job “php artisan schedule:run” every minute.') }}</span></span>
                        @endif
                    </li>
                    @foreach ($status as $label => $ready)
                        <li class="flex items-center gap-2"><span @class(['text-emerald-600' => $ready, 'text-gray-400' => ! $ready])>{{ $ready ? '✓' : '–' }}</span> {{ $label }}</li>
                    @endforeach
                </ul>
                @if ($failedJobs > 0)
                    <p class="mt-3 text-xs text-orange-700 dark:text-orange-400">{{ trans_choice(':count background job failed.|:count background jobs failed.', $failedJobs) }}</p>
                @endif
            </section>

            <form wire:submit="save" class="space-y-6">
                @foreach ($groups as $group => $meta)
                    <section wire:key="group-{{ $group }}" class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $meta['title'] }}</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $meta['text'] }}</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            @foreach (AppSettings::FIELDS as $key => $field)
                                @continue($field['group'] !== $group)
                                <div wire:key="field-{{ $key }}">
                                    <x-input-label :for="'setting-'.$key" :value="$labels[$key][0]" />
                                    @if ($field['type'] === 'select')
                                        <select id="setting-{{ $key }}" wire:model="form.{{ $key }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                            <option value="">{{ __('STARTTLS (port 587)') }}</option>
                                            <option value="smtps">{{ __('SSL/TLS (port 465)') }}</option>
                                        </select>
                                    @elseif ($field['secret'])
                                        <x-text-input :id="'setting-'.$key" type="password" autocomplete="new-password" wire:model="form.{{ $key }}" class="mt-1 block w-full" :placeholder="isset($stored[$key]) ? '•••••••• '.__('(saved)') : (isset($current[$key]) ? '•••••••• '.__('(from .env)') : $labels[$key][1])" />
                                        @if (isset($stored[$key]))
                                            <label class="mt-1 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400"><input type="checkbox" wire:model="clear.{{ $key }}" class="rounded border-gray-300 text-red-600" /> {{ __('remove') }}</label>
                                        @endif
                                    @else
                                        <x-text-input :id="'setting-'.$key" :type="$field['type'] === 'number' ? 'number' : ($field['type'] === 'email' ? 'email' : 'text')" wire:model="form.{{ $key }}" class="mt-1 block w-full" :placeholder="isset($current[$key]) ? __('currently: :value', ['value' => $current[$key]]) : $labels[$key][1]" />
                                    @endif
                                    <x-input-error :messages="$errors->get('form.'.$key)" class="mt-1" />
                                </div>
                            @endforeach
                        </div>

                        @if ($group === 'mail')
                            <div class="mt-4 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                                <div class="min-w-0 flex-1">
                                    <x-input-label for="test-recipient" :value="__('Send a test e-mail to')" />
                                    <x-text-input id="test-recipient" type="email" wire:model="testRecipient" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('testRecipient')" class="mt-1" />
                                </div>
                                <x-secondary-button type="button" wire:click="sendTestMail">{{ __('Send test e-mail') }}</x-secondary-button>
                            </div>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Save first, then test.') }} {{ __('Current mailer: :mailer', ['mailer' => $mailer]) }}</p>
                        @elseif ($group === 'push' && blank(config('services.webpush.public_key')))
                            <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                                <x-secondary-button type="button" wire:click="generateVapidKeys">🔑 {{ __('Create push keys') }}</x-secondary-button>
                            </div>
                        @elseif (in_array($group, ['import_portfolio', 'import_recipes'], true))
                            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 text-sm dark:border-gray-700">
                                <x-secondary-button type="button" wire:click="startImport('{{ $importNames[$group] }}')" wire:confirm="{{ __('Start the import into your account (:email)? It can safely run again.', ['email' => auth()->user()->email]) }}">⤵ {{ __('Start import') }}</x-secondary-button>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Save the access data first. The import runs in the background.') }}</span>
                            </div>
                            @if ($imports[$importNames[$group]])
                                <div class="mt-3 rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-900/50">
                                    <p class="font-medium text-gray-700 dark:text-gray-200">{{ $importStatus[$imports[$importNames[$group]]['status']] ?? $imports[$importNames[$group]]['status'] }} · {{ Carbon::parse($imports[$importNames[$group]]['at'])->diffForHumans() }}</p>
                                    @if ($imports[$importNames[$group]]['output'] !== '')
                                        <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap text-gray-600 dark:text-gray-400">{{ $imports[$importNames[$group]]['output'] }}</pre>
                                    @endif
                                </div>
                            @endif
                        @endif
                    </section>
                @endforeach

                <div class="sticky bottom-4 flex justify-end">
                    <x-primary-button>{{ __('Save') }}</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</div>
