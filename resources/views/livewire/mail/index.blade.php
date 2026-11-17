<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\ShoppingList;
use App\Support\Mail\Mailbox;
use App\Support\Mail\MailSync;
use App\Support\QuickCapture;
use App\Support\ShoppingLists;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public ?int $selectedAccountId = null;

    public ?int $openId = null;

    public string $search = '';

    public string $flash = '';

    // ── Account form ─────────────────────────────────────────────────
    public bool $showAccountForm = false;

    public ?int $editingAccountId = null;

    public string $aLabel = '';

    public string $aProvider = MailAccount::PROVIDER_GENERIC;

    public string $aImapHost = '';

    public int $aImapPort = 993;

    public string $aImapEncryption = 'ssl';

    public string $aSmtpHost = '';

    public ?int $aSmtpPort = 465;

    public string $aSmtpEncryption = 'ssl';

    public bool $aValidateCert = true;

    public string $aUsername = '';

    public string $aPassword = '';

    public string $testMessage = '';

    public ?bool $testOk = null;

    /**
     * @return Collection<int, MailAccount>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return MailAccount::query()->forUser($this->currentUser(), (int) $this->householdId)->orderBy('label')->get();
    }

    #[Computed]
    public function selectedAccount(): ?MailAccount
    {
        return $this->selectedAccountId ? $this->accounts->firstWhere('id', $this->selectedAccountId) : null;
    }

    /**
     * @return Collection<int, MailMessage>
     */
    #[Computed]
    public function messages(): Collection
    {
        if (! $this->selectedAccount) {
            return collect();
        }

        $messages = MailMessage::query()
            ->where('mail_account_id', $this->selectedAccount->id)
            ->orderByDesc('date')->orderByDesc('uid')
            ->limit(300)->get();

        $search = trim(mb_strtolower($this->search));

        if ($search !== '') {
            // Subject and sender are encrypted at rest, so filtering happens after decryption.
            $messages = $messages->filter(fn (MailMessage $message) => str_contains(mb_strtolower((string) $message->subject.' '.$message->from_address.' '.$message->from_name), $search))->values();
        }

        return $messages;
    }

    #[Computed]
    public function openMessage(): ?MailMessage
    {
        if (! $this->openId || ! $this->selectedAccount) {
            return null;
        }

        return MailMessage::query()->where('mail_account_id', $this->selectedAccount->id)->find($this->openId);
    }

    /**
     * @return array<int, int> account id => unread count
     */
    #[Computed]
    public function unreadCounts(): array
    {
        return MailMessage::query()
            ->whereIn('mail_account_id', $this->accounts->pluck('id'))
            ->where('is_read', false)
            ->selectRaw('mail_account_id, count(*) as total')->groupBy('mail_account_id')
            ->pluck('total', 'mail_account_id')->all();
    }

    public function selectAccount(int $id): void
    {
        $this->selectedAccountId = $this->accounts->firstWhere('id', $id)?->id;
        $this->openId = null;
        $this->search = '';
    }

    public function open(int $id): void
    {
        $message = MailMessage::query()->where('mail_account_id', $this->selectedAccount?->id)->find($id);

        if (! $message) {
            return;
        }

        $this->openId = $message->id;

        if (! $message->is_read) {
            $message->forceFill(['is_read' => true])->save();
        }
    }

    public function close(): void
    {
        $this->openId = null;
    }

    // ── Account management ───────────────────────────────────────────
    public function newAccount(): void
    {
        $this->reset(['editingAccountId', 'aLabel', 'aImapHost', 'aSmtpHost', 'aUsername', 'aPassword', 'testMessage', 'testOk']);
        $this->aProvider = MailAccount::PROVIDER_GENERIC;
        $this->aImapPort = 993;
        $this->aImapEncryption = 'ssl';
        $this->aSmtpPort = 465;
        $this->aSmtpEncryption = 'ssl';
        $this->aValidateCert = true;
        $this->showAccountForm = true;
    }

    /**
     * Fills the form with the usual Proton Bridge settings (Bridge runs on a reachable host and
     * exposes IMAP on 1143 / SMTP on 1025 with STARTTLS and a Bridge-generated password).
     */
    public function applyProtonPreset(): void
    {
        $this->aProvider = MailAccount::PROVIDER_PROTON_BRIDGE;
        $this->aImapPort = 1143;
        $this->aImapEncryption = 'tls';
        $this->aSmtpPort = 1025;
        $this->aSmtpEncryption = 'tls';
        $this->aValidateCert = false;

        if ($this->aLabel === '') {
            $this->aLabel = 'Proton';
        }
    }

    public function editAccount(int $id): void
    {
        $account = $this->accounts->firstWhere('id', $id);

        if (! $account) {
            return;
        }

        $this->editingAccountId = $account->id;
        $this->aLabel = $account->label;
        $this->aProvider = $account->provider;
        $this->aImapHost = $account->imap_host;
        $this->aImapPort = $account->imap_port;
        $this->aImapEncryption = $account->imap_encryption;
        $this->aSmtpHost = (string) $account->smtp_host;
        $this->aSmtpPort = $account->smtp_port;
        $this->aSmtpEncryption = (string) ($account->smtp_encryption ?: 'ssl');
        $this->aValidateCert = $account->validate_cert;
        $this->aUsername = $account->username;
        $this->aPassword = '';
        $this->testMessage = '';
        $this->testOk = null;
        $this->showAccountForm = true;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountData(): array
    {
        return [
            'label' => trim($this->aLabel),
            'provider' => in_array($this->aProvider, MailAccount::PROVIDERS, true) ? $this->aProvider : MailAccount::PROVIDER_GENERIC,
            'imap_host' => trim($this->aImapHost),
            'imap_port' => $this->aImapPort,
            'imap_encryption' => $this->aImapEncryption,
            'smtp_host' => trim($this->aSmtpHost) ?: null,
            'smtp_port' => $this->aSmtpPort,
            'smtp_encryption' => trim($this->aSmtpHost) !== '' ? $this->aSmtpEncryption : null,
            'validate_cert' => $this->aValidateCert,
            'username' => trim($this->aUsername),
        ];
    }

    private function validateAccount(): void
    {
        $this->validate([
            'aLabel' => ['required', 'string', 'max:80'],
            'aProvider' => ['required', 'in:'.implode(',', MailAccount::PROVIDERS)],
            'aImapHost' => ['required', 'string', 'max:255'],
            'aImapPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'aImapEncryption' => ['required', 'in:'.implode(',', MailAccount::ENCRYPTIONS)],
            'aSmtpPort' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'aUsername' => ['required', 'string', 'max:255'],
            'aPassword' => [$this->editingAccountId ? 'nullable' : 'required', 'string', 'max:255'],
        ]);
    }

    public function saveAccount(): void
    {
        $this->validateAccount();
        $data = $this->accountData();

        if ($this->editingAccountId) {
            $account = $this->accounts->firstWhere('id', $this->editingAccountId);

            if (! $account) {
                return;
            }

            if ($this->aPassword !== '') {
                $data['password'] = $this->aPassword;
            }

            $account->forceFill($data)->save();
        } else {
            $data['password'] = $this->aPassword;
            $account = MailAccount::create($data + ['user_id' => $this->currentUser()->id, 'household_id' => (int) $this->householdId]);
            $this->selectedAccountId = $account->id;
        }

        unset($this->accounts);
        $this->showAccountForm = false;
        $this->flash = __('Mail account saved.');
    }

    public function deleteAccount(int $id): void
    {
        $account = $this->accounts->firstWhere('id', $id);
        $account?->delete();
        unset($this->accounts);

        if ($this->selectedAccountId === $id) {
            $this->selectedAccountId = null;
            $this->openId = null;
        }

        $this->flash = __('Mail account removed.');
    }

    public function testConnection(): void
    {
        $this->validateAccount();
        $data = $this->accountData();
        $password = $this->aPassword;

        if ($password === '' && $this->editingAccountId) {
            $password = (string) $this->accounts->firstWhere('id', $this->editingAccountId)?->password;
        }

        $mailbox = app(Mailbox::class);

        try {
            $mailbox->open([
                'host' => $data['imap_host'], 'port' => $data['imap_port'], 'encryption' => $data['imap_encryption'],
                'validate_cert' => $data['validate_cert'], 'username' => $data['username'], 'password' => $password,
            ], false);
            $state = $mailbox->selectFolder('INBOX');
            $mailbox->logout();
            $this->testOk = true;
            $this->testMessage = __(':count mails in the inbox.', ['count' => $state['exists']]);
        } catch (\Throwable $exception) {
            $this->testOk = false;
            $this->testMessage = $exception->getMessage();
        }
    }

    public function syncNow(): void
    {
        $account = $this->selectedAccount;

        if (! $account) {
            return;
        }

        try {
            $new = app(MailSync::class)->sync($account);
            unset($this->messages, $this->unreadCounts);
            $this->flash = trans_choice(':count new mail loaded.|:count new mails loaded.', $new, ['count' => $new]);
        } catch (\Throwable $exception) {
            $account->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 300)])->save();
            $this->flash = __('Sync failed: :error', ['error' => $exception->getMessage()]);
        }
    }

    // ── Reuse a mail inside the tool ─────────────────────────────────
    private function markProcessed(MailMessage $message, string $as): void
    {
        $message->forceFill(['processed_as' => $as])->save();
        unset($this->messages);
    }

    public function toTask(int $id): void
    {
        $message = $this->openMessageOwned($id);

        if (! $message) {
            return;
        }

        $task = QuickCapture::task($this->currentUser(), (int) $this->householdId, (string) ($message->subject ?: __('Mail without subject')));

        if ($task && $message->body_text) {
            $task->forceFill(['content' => ['body' => $message->body_text]])->saveQuietly();
        }

        $this->markProcessed($message, 'task');
        $this->flash = __('Added as a task.');
    }

    public function toInbox(int $id): void
    {
        $message = $this->openMessageOwned($id);

        if (! $message) {
            return;
        }

        $item = QuickCapture::inbox($this->currentUser(), (int) $this->householdId, (string) ($message->subject ?: __('Mail without subject')));

        if ($item && $message->body_text) {
            $item->forceFill(['content' => ['body' => $message->body_text]])->saveQuietly();
        }

        $this->markProcessed($message, 'inbox');
        $this->flash = __('Saved to the inbox.');
    }

    public function toShoppingList(int $id): void
    {
        $message = $this->openMessageOwned($id);

        if (! $message) {
            return;
        }

        $list = ShoppingList::defaultFor((int) $this->householdId);
        $lines = collect(preg_split('/\R/u', (string) $message->body_text) ?: [])
            ->map(fn (string $line) => trim((string) preg_replace('/^[\s\-*•·–]+/u', '', $line)))
            ->filter(fn (string $line) => $line !== '' && mb_strlen($line) <= 80)
            ->unique()->take(40);

        foreach ($lines as $line) {
            ShoppingLists::add($list, $line, null, $this->currentUser()->id, ['note' => __('By mail')]);
        }

        $this->markProcessed($message, 'shopping');
        $this->flash = trans_choice(':count item added.|:count items added.', $lines->count(), ['count' => $lines->count()]);
    }

    private function openMessageOwned(int $id): ?MailMessage
    {
        return MailMessage::query()->where('mail_account_id', $this->selectedAccount?->id)->find($id);
    }
}; ?>

<div class="mx-auto max-w-5xl px-4 py-6">
    <div class="mb-4 flex items-center justify-between gap-3">
        <h1 class="flex items-center gap-2 text-xl font-semibold text-gray-900 dark:text-gray-100">
            <x-icon name="envelope" class="h-6 w-6" /> {{ __('Mail') }}
        </h1>
        <button type="button" wire:click="newAccount" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">
            + {{ __('Add mail account') }}
        </button>
    </div>

    @if ($flash)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200" wire:key="flash">{{ $flash }}</div>
    @endif

    @if ($this->accounts->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
            <p class="text-gray-600 dark:text-gray-300">{{ __('No mail account yet. Add your IMAP account or connect Proton via the Proton Bridge.') }}</p>
        </div>
    @else
        {{-- Account chips --}}
        <div class="mb-4 flex flex-wrap gap-2">
            @foreach ($this->accounts as $account)
                <button type="button" wire:click="selectAccount({{ $account->id }})"
                    class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm {{ $this->selectedAccountId === $account->id ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200' : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-200' }}">
                    <span>{{ $account->isProton() ? '🛡️' : '📧' }}</span>
                    <span class="truncate max-w-[12rem]">{{ $account->label }}</span>
                    @if (($this->unreadCounts[$account->id] ?? 0) > 0)
                        <span class="rounded-full bg-indigo-600 px-1.5 text-xs text-white">{{ $this->unreadCounts[$account->id] }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        @if ($this->selectedAccount)
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <button type="button" wire:click="syncNow" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                    <span wire:loading.remove wire:target="syncNow">⟳ {{ __('Sync now') }}</span>
                    <span wire:loading wire:target="syncNow">{{ __('Syncing …') }}</span>
                </button>
                <button type="button" wire:click="editAccount({{ $this->selectedAccount->id }})" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">{{ __('Settings') }}</button>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search mail …') }}" class="flex-1 min-w-[10rem] rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" />
                @if ($this->selectedAccount->last_synced_at)
                    <span class="text-xs text-gray-400">{{ __('Last sync') }}: {{ $this->selectedAccount->last_synced_at->diffForHumans() }}</span>
                @endif
            </div>

            @if ($this->selectedAccount->last_error)
                <div class="mb-3 rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">{{ __('Last error') }}: {{ $this->selectedAccount->last_error }}</div>
            @endif

            {{-- Reader --}}
            @if ($this->openMessage)
                @php($msg = $this->openMessage)
                <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                    <button type="button" wire:click="close" class="mb-3 text-sm text-indigo-600 dark:text-indigo-300">← {{ __('Back to list') }}</button>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $msg->subject ?: __('Mail without subject') }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $msg->fromLabel() }} @if($msg->date) · {{ $msg->date->isoFormat('LLL') }} @endif</p>

                    @if ($msg->has_attachments && $msg->attachments_meta)
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($msg->attachments_meta as $attachment)
                                <span class="inline-flex items-center gap-1 rounded-lg bg-gray-100 px-2 py-1 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">📎 {{ $attachment['name'] }}</span>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-4 whitespace-pre-wrap break-words text-sm text-gray-800 dark:text-gray-200">{{ $msg->body_text ?: __('(No text content.)') }}</div>

                    <div class="mt-5 flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                        <span class="self-center text-xs font-medium uppercase text-gray-400">{{ __('Use in the tool') }}:</span>
                        <button type="button" wire:click="toTask({{ $msg->id }})" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">✅ {{ __('Task') }}</button>
                        <button type="button" wire:click="toShoppingList({{ $msg->id }})" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">🛒 {{ __('Shopping list') }}</button>
                        <button type="button" wire:click="toInbox({{ $msg->id }})" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">📥 {{ __('Inbox') }}</button>
                        @if ($msg->processed_as)
                            <span class="self-center text-xs text-emerald-600 dark:text-emerald-300">✓ {{ __('Already taken over') }}</span>
                        @endif
                    </div>
                </div>
            @else
                {{-- List --}}
                @if ($this->messages->isEmpty())
                    <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-gray-500 dark:border-gray-700">{{ __('No mails yet – use “Sync now”.') }}</div>
                @else
                    <div class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-700">
                        @foreach ($this->messages as $message)
                            <button type="button" wire:key="m{{ $message->id }}" wire:click="open({{ $message->id }})"
                                class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-gray-800 {{ $message->is_read ? 'bg-white dark:bg-gray-900' : 'bg-indigo-50/40 dark:bg-indigo-900/10' }}">
                                @unless ($message->is_read)<span class="h-2 w-2 shrink-0 rounded-full bg-indigo-500"></span>@else<span class="h-2 w-2 shrink-0"></span>@endunless
                                <span class="w-40 shrink-0 truncate text-sm {{ $message->is_read ? 'text-gray-600 dark:text-gray-400' : 'font-semibold text-gray-900 dark:text-gray-100' }}">{{ $message->fromLabel() }}</span>
                                <span class="flex-1 truncate text-sm {{ $message->is_read ? 'text-gray-600 dark:text-gray-400' : 'font-medium text-gray-900 dark:text-gray-100' }}">{{ $message->subject ?: __('Mail without subject') }}</span>
                                @if ($message->has_attachments)<span class="text-xs text-gray-400">📎</span>@endif
                                @if ($message->processed_as)<span class="text-xs text-emerald-500">✓</span>@endif
                                <span class="w-24 shrink-0 text-right text-xs text-gray-400">{{ $message->date?->isoFormat('DD.MM.') }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            @endif
        @endif
    @endif

    {{-- Account form dialog --}}
    @if ($showAccountForm)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" wire:key="account-form">
            <div class="mt-8 w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $editingAccountId ? __('Mail account') : __('Add mail account') }}</h2>
                    <button type="button" wire:click="$set('showAccountForm', false)" class="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                <div class="mb-3 flex flex-wrap gap-2">
                    <button type="button" wire:click="applyProtonPreset" class="rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-sm text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">🛡️ {{ __('Proton (Bridge) preset') }}</button>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Name') }}</label>
                        <input type="text" wire:model="aLabel" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" placeholder="Proton privat" />
                        @error('aLabel')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('IMAP server') }}</label>
                            <input type="text" wire:model="aImapHost" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" placeholder="127.0.0.1" />
                            @error('aImapHost')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Port') }}</label>
                                <input type="number" wire:model="aImapPort" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Security') }}</label>
                                <select wire:model="aImapEncryption" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800">
                                    <option value="ssl">SSL</option>
                                    <option value="tls">STARTTLS</option>
                                    <option value="none">{{ __('None') }}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('User name') }}</label>
                            <input type="text" wire:model="aUsername" autocomplete="off" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" placeholder="name@proton.me" />
                            @error('aUsername')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Password') }} @if($editingAccountId)<span class="text-xs text-gray-400">({{ __('leave empty to keep') }})</span>@endif</label>
                            <input type="password" wire:model="aPassword" autocomplete="new-password" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" placeholder="••••••••" />
                            @error('aPassword')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <details class="text-sm text-gray-600 dark:text-gray-300">
                        <summary class="cursor-pointer">{{ __('SMTP (for sending – later)') }}</summary>
                        <div class="mt-2 grid grid-cols-2 gap-3">
                            <input type="text" wire:model="aSmtpHost" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" placeholder="{{ __('SMTP server') }}" />
                            <div class="grid grid-cols-2 gap-2">
                                <input type="number" wire:model="aSmtpPort" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" placeholder="{{ __('Port') }}" />
                                <select wire:model="aSmtpEncryption" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800">
                                    <option value="ssl">SSL</option>
                                    <option value="tls">STARTTLS</option>
                                    <option value="none">{{ __('None') }}</option>
                                </select>
                            </div>
                        </div>
                    </details>

                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" wire:model="aValidateCert" class="rounded" /> {{ __('Validate TLS certificate (off for Proton Bridge)') }}
                    </label>

                    @if ($testMessage)
                        <div class="rounded-lg px-3 py-2 text-sm {{ $testOk ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' : 'bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-200' }}">
                            {{ $testOk ? '✓ ' : '✕ ' }}{{ $testMessage }}
                        </div>
                    @endif
                </div>

                <div class="mt-5 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex gap-2">
                        <button type="button" wire:click="testConnection" wire:loading.attr="disabled" class="rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
                            <span wire:loading.remove wire:target="testConnection">{{ __('Test connection') }}</span>
                            <span wire:loading wire:target="testConnection">{{ __('Testing …') }}</span>
                        </button>
                        @if ($editingAccountId)
                            <button type="button" wire:click="deleteAccount({{ $editingAccountId }})" wire:confirm="{{ __('Remove this mail account and its synced mails?') }}" class="rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">{{ __('Remove') }}</button>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <button type="button" wire:click="$set('showAccountForm', false)" class="rounded-lg px-3 py-2 text-sm text-gray-500">{{ __('Cancel') }}</button>
                        <button type="button" wire:click="saveAccount" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">{{ __('Save') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
