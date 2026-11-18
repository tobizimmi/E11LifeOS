<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\PaperlessInstance;
use App\Models\ShoppingList;
use App\Support\Mail\Mailbox;
use App\Support\Mail\MailHtml;
use App\Support\Mail\MailSender;
use App\Support\Mail\MailSync;
use App\Support\Mail\MimeMessage;
use App\Support\Paperless\PaperlessUploader;
use App\Support\QuickCapture;
use App\Support\Security\VirusScanner;
use App\Support\ShoppingLists;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /** Attachment types that can be archived to Paperless (documents and photos). */
    public const PAPERLESS_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic'];

    public ?int $selectedAccountId = null;

    public ?int $openId = null;

    public string $folder = 'INBOX';

    public bool $showHtml = true;

    public bool $loadImages = false;

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

    // ── Compose / reply ──────────────────────────────────────────────
    public bool $showCompose = false;

    public string $composeTo = '';

    public string $composeCc = '';

    public string $composeSubject = '';

    public string $composeBody = '';

    /** The message being replied to/forwarded, for In-Reply-To/References headers. */
    public ?int $composeReplyToId = null;

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
     * The folders that hold synced mail for the account, with INBOX/Sent/Archive first.
     *
     * @return list<string>
     */
    #[Computed]
    public function folders(): array
    {
        if (! $this->selectedAccount) {
            return [];
        }

        $present = MailMessage::query()->where('mail_account_id', $this->selectedAccount->id)->distinct()->pluck('folder')->all();
        $order = MailSync::FOLDERS;
        usort($present, fn (string $a, string $b) => (array_search($a, $order, true) === false ? 99 : array_search($a, $order, true)) <=> (array_search($b, $order, true) === false ? 99 : array_search($b, $order, true)));

        return $present !== [] ? $present : ['INBOX'];
    }

    /**
     * The selected folder's mail, grouped into conversations (one row per thread, newest first).
     *
     * @return Collection<int, array{message: MailMessage, count: int}>
     */
    #[Computed]
    public function messages(): Collection
    {
        if (! $this->selectedAccount) {
            return collect();
        }

        $messages = MailMessage::query()
            ->where('mail_account_id', $this->selectedAccount->id)
            ->where('folder', $this->folder)
            ->orderByDesc('date')->orderByDesc('uid')
            ->limit(500)->get();

        $search = trim(mb_strtolower($this->search));

        if ($search !== '') {
            // Subject and sender are encrypted at rest, so filtering happens after decryption.
            $messages = $messages->filter(fn (MailMessage $message) => str_contains(mb_strtolower((string) $message->subject.' '.$message->from_address.' '.$message->from_name), $search));
        }

        return $messages
            ->groupBy(fn (MailMessage $message) => $message->thread_key ?: 'id:'.$message->id)
            ->map(fn (Collection $group) => ['message' => $group->firstOrFail(), 'count' => $group->count()])
            ->sortByDesc(fn (array $row) => $row['message']->date?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * The other mails in the open mail's conversation (across folders, oldest first).
     *
     * @return Collection<int, MailMessage>
     */
    #[Computed]
    public function threadSiblings(): Collection
    {
        $message = $this->openMessage;

        if (! $message || ! $message->thread_key) {
            return collect();
        }

        return MailMessage::query()
            ->where('mail_account_id', $message->mail_account_id)
            ->where('thread_key', $message->thread_key)
            ->where('id', '!=', $message->id)
            ->orderBy('date')->get();
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
     * The open mail's HTML body prepared for the sandboxed iframe, or null when it has no HTML part
     * or the member switched to the plain-text view.
     *
     * @return array{html: string, blocked: bool}|null
     */
    #[Computed]
    public function renderedHtml(): ?array
    {
        $message = $this->openMessage;

        if (! $this->showHtml || ! $message || ! $message->body_html) {
            return null;
        }

        return MailHtml::render((string) $message->body_html, $this->loadImages);
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

    /**
     * Whether a Paperless archive is connected for the member, so attachments can be archived.
     */
    #[Computed]
    public function paperlessConnected(): bool
    {
        return PaperlessInstance::defaultFor($this->currentUser()) !== null;
    }

    public function selectAccount(int $id): void
    {
        $this->selectedAccountId = $this->accounts->firstWhere('id', $id)?->id;
        $this->openId = null;
        $this->search = '';
        $this->folder = 'INBOX';
    }

    public function openFolder(string $folder): void
    {
        if (in_array($folder, $this->folders, true)) {
            $this->folder = $folder;
            $this->openId = null;
            $this->search = '';
        }
    }

    public function open(int $id): void
    {
        $message = MailMessage::query()->where('mail_account_id', $this->selectedAccount?->id)->find($id);

        if (! $message) {
            return;
        }

        $this->openId = $message->id;
        $this->showHtml = true;
        $this->loadImages = false;

        if (! $message->is_read) {
            $message->forceFill(['is_read' => true])->save();
        }
    }

    public function loadRemoteImages(): void
    {
        $this->loadImages = true;
    }

    public function close(): void
    {
        $this->openId = null;
    }

    // ── Compose / reply / forward (SMTP) ─────────────────────────────
    public function compose(): void
    {
        $this->reset(['composeTo', 'composeCc', 'composeSubject', 'composeBody', 'composeReplyToId']);
        $this->showCompose = true;
    }

    public function reply(int $id): void
    {
        $message = $this->ownedMessage($id);

        if (! $message) {
            return;
        }

        $this->composeTo = (string) $message->from_address;
        $this->composeCc = '';
        $this->composeSubject = $this->prefixedSubject('Re', (string) $message->subject);
        $this->composeBody = "\n\n".$this->quote($message);
        $this->composeReplyToId = $message->id;
        $this->showCompose = true;
    }

    public function forward(int $id): void
    {
        $message = $this->ownedMessage($id);

        if (! $message) {
            return;
        }

        $this->composeTo = '';
        $this->composeCc = '';
        $this->composeSubject = $this->prefixedSubject('Fwd', (string) $message->subject);
        $this->composeBody = "\n\n".$this->quote($message);
        $this->composeReplyToId = null;
        $this->showCompose = true;
    }

    public function sendMail(MailSender $sender): void
    {
        $account = $this->selectedAccount;

        if (! $account) {
            return;
        }

        Validator::make([
            'composeTo' => $this->composeTo,
            'composeBody' => $this->composeBody,
        ], [
            'composeTo' => ['required', 'string', 'max:2000'],
            'composeBody' => ['nullable', 'string', 'max:100000'],
        ])->validate();

        $options = ['cc' => $this->composeCc];

        if ($this->composeReplyToId) {
            $original = $this->ownedMessage($this->composeReplyToId);

            if ($original?->message_id) {
                $options['in_reply_to'] = '<'.trim($original->message_id, '<>').'>';
                $options['references'] = $options['in_reply_to'];
            }
        }

        try {
            $sender->send($account, $this->composeTo, $this->composeSubject, $this->composeBody, $options);
        } catch (\Throwable $exception) {
            $this->flash = __('Sending failed: :error', ['error' => $exception->getMessage()]);

            return;
        }

        $this->showCompose = false;
        $this->reset(['composeTo', 'composeCc', 'composeSubject', 'composeBody', 'composeReplyToId']);
        $this->flash = __('Mail sent.');
    }

    private function ownedMessage(int $id): ?MailMessage
    {
        return MailMessage::query()->where('mail_account_id', $this->selectedAccount?->id)->find($id);
    }

    private function prefixedSubject(string $prefix, string $subject): string
    {
        $subject = trim($subject);

        return str_starts_with(mb_strtolower($subject), mb_strtolower($prefix).':') ? $subject : $prefix.': '.$subject;
    }

    private function quote(MailMessage $message): string
    {
        $header = __('On :date, :sender wrote:', [
            'date' => $message->date?->isoFormat('LLL') ?? '',
            'sender' => $message->fromLabel(),
        ]);
        $quoted = collect(preg_split('/\R/u', (string) $message->body_text) ?: [])
            ->map(fn (string $line) => '> '.$line)->implode("\n");

        return $header."\n".$quoted;
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

    /**
     * @return array<string, mixed>
     */
    private function accountRules(): array
    {
        return [
            'aLabel' => ['required', 'string', 'max:80'],
            'aProvider' => ['required', 'in:'.implode(',', MailAccount::PROVIDERS)],
            'aImapHost' => ['required', 'string', 'max:255'],
            'aImapPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'aImapEncryption' => ['required', 'in:'.implode(',', MailAccount::ENCRYPTIONS)],
            'aSmtpPort' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'aUsername' => ['required', 'string', 'max:255'],
            'aPassword' => [$this->editingAccountId ? 'nullable' : 'required', 'string', 'max:255'],
        ];
    }

    /**
     * Validates the account form. Uses the Validator facade (not $this->validate()) because the
     * latter's error-bag/session handling triggers a 419 on this component under test.
     */
    private function validatedAccount(): void
    {
        Validator::make([
            'aLabel' => $this->aLabel,
            'aProvider' => $this->aProvider,
            'aImapHost' => $this->aImapHost,
            'aImapPort' => $this->aImapPort,
            'aImapEncryption' => $this->aImapEncryption,
            'aSmtpPort' => $this->aSmtpPort,
            'aUsername' => $this->aUsername,
            'aPassword' => $this->aPassword,
        ], $this->accountRules())->validate();
    }

    public function saveAccount(): void
    {
        $this->validatedAccount();

        $data = $this->accountData();

        $account = $this->editingAccountId
            ? ($this->accounts->firstWhere('id', $this->editingAccountId) ?? new MailAccount)
            : new MailAccount(['user_id' => $this->currentUser()->id, 'household_id' => (int) $this->householdId]);

        if (! $this->editingAccountId || $this->aPassword !== '') {
            $data['password'] = $this->aPassword;
        }

        $account->fill($data)->save();

        $this->selectedAccountId = $account->id;
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
        $this->validatedAccount();
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
            $new = app(MailSync::class)->syncAccount($account);
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

    /**
     * Archives one of the open mail's attachments to Paperless. The attachment is not stored
     * locally, so it is re-fetched from the server by the mail's UID, virus-checked and then sent
     * (or queued when the archive is unreachable, via {@see PaperlessUploader}).
     */
    public function toPaperless(int $id, int $index): void
    {
        $message = $this->openMessageOwned($id);

        if (! $message) {
            return;
        }

        $meta = ($message->attachments_meta ?? [])[$index] ?? null;

        if (! $meta || ! in_array($meta['mime'], self::PAPERLESS_TYPES, true)) {
            $this->flash = __('This attachment cannot be archived.');

            return;
        }

        $instance = PaperlessInstance::defaultFor($this->currentUser());

        if (! $instance) {
            $this->flash = __('Connect Paperless first to archive attachments.');

            return;
        }

        $attachment = $this->fetchAttachment($message, $index, $meta);

        if ($attachment === null) {
            $this->flash = __('The attachment could not be fetched from the server.');

            return;
        }

        if (VirusScanner::isEnabled()) {
            $scan = app(VirusScanner::class)->scanString($attachment['content']);

            if ($scan['status'] === VirusScanner::INFECTED || ($scan['status'] === VirusScanner::UNAVAILABLE && config('security.clamav.fail_closed'))) {
                $this->flash = __(':file was not archived (virus check).', ['file' => $meta['name']]);

                return;
            }
        }

        $title = pathinfo($meta['name'], PATHINFO_FILENAME) ?: ((string) $message->subject ?: __('Mail attachment'));

        try {
            $queued = app(PaperlessUploader::class)->store(
                $instance,
                $this->currentUser(),
                (int) $this->householdId,
                $attachment['content'],
                mb_substr($meta['name'], 0, 255),
                mb_substr($title, 0, 255),
            );
        } catch (\Throwable $exception) {
            report($exception);
            $this->flash = __('Archiving failed: :error', ['error' => $exception->getMessage()]);

            return;
        }

        $this->flash = $queued
            ? __(':file is queued for Paperless (the archive is currently unreachable).', ['file' => $meta['name']])
            : __(':file was sent to Paperless.', ['file' => $meta['name']]);
    }

    /**
     * Re-fetches one attachment's raw content by the mail's UID (attachments stay on the server).
     * The attachment at the given index must still match the stored meta, otherwise null is returned.
     *
     * @param  array{name: string, mime: string, size: int}  $meta
     * @return array{name: string, mime: string, content: string}|null
     */
    private function fetchAttachment(MailMessage $message, int $index, array $meta): ?array
    {
        $account = $this->selectedAccount;

        if (! $account) {
            return null;
        }

        $mailbox = app(Mailbox::class);

        try {
            $mailbox->open($account->imapConfig(), false);
            $mailbox->selectFolder($message->folder);
            $raw = $mailbox->uidFetch($message->uid)['raw'];
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        } finally {
            rescue(fn () => $mailbox->logout(), report: false);
        }

        if ($raw === '') {
            return null;
        }

        $attachment = MimeMessage::parse($raw)->attachments[$index] ?? null;

        if (! $attachment || $attachment['mime'] !== $meta['mime'] || $attachment['content'] === '') {
            return null;
        }

        return $attachment;
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
                @if ($this->selectedAccount->smtp_host)
                    <button type="button" wire:click="compose" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">✉️ {{ __('New mail') }}</button>
                @endif
                <button type="button" wire:click="editAccount({{ $this->selectedAccount->id }})" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">{{ __('Settings') }}</button>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search mail …') }}" class="flex-1 min-w-[10rem] rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" />
                @if ($this->selectedAccount->last_synced_at)
                    <span class="text-xs text-gray-400">{{ __('Last sync') }}: {{ $this->selectedAccount->last_synced_at->diffForHumans() }}</span>
                @endif
            </div>

            @if (count($this->folders) > 1)
                <div class="mb-3 flex flex-wrap gap-1.5">
                    @foreach ($this->folders as $folderName)
                        <button type="button" wire:click="openFolder(@js($folderName))"
                            class="rounded-full px-3 py-1 text-xs {{ $folder === $folderName ? 'bg-gray-800 text-white dark:bg-gray-200 dark:text-gray-900' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' }}">
                            {{ __($folderName) }}
                        </button>
                    @endforeach
                </div>
            @endif

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
                            @foreach ($msg->attachments_meta as $index => $attachment)
                                <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2 py-1 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                    📎 {{ $attachment['name'] }}
                                    @if ($this->paperlessConnected && in_array($attachment['mime'], $this::PAPERLESS_TYPES, true))
                                        <button type="button" wire:click="toPaperless({{ $msg->id }}, {{ $index }})" wire:loading.attr="disabled" wire:target="toPaperless({{ $msg->id }}, {{ $index }})"
                                            class="rounded bg-indigo-600 px-1.5 py-0.5 text-[11px] font-medium text-white hover:bg-indigo-500 disabled:opacity-50" title="{{ __('Archive to Paperless') }}">
                                            → Paperless
                                        </button>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    @endif

                    @if ($msg->body_html)
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <div class="inline-flex overflow-hidden rounded-lg border border-gray-300 text-xs dark:border-gray-700">
                                <button type="button" wire:click="$set('showHtml', true)" class="px-2 py-1 {{ $showHtml ? 'bg-indigo-600 text-white' : 'text-gray-600 dark:text-gray-300' }}">HTML</button>
                                <button type="button" wire:click="$set('showHtml', false)" class="px-2 py-1 {{ $showHtml ? 'text-gray-600 dark:text-gray-300' : 'bg-indigo-600 text-white' }}">{{ __('Text') }}</button>
                            </div>
                            @if ($this->renderedHtml && $this->renderedHtml['blocked'])
                                <button type="button" wire:click="loadRemoteImages" class="rounded-lg border border-amber-300 bg-amber-50 px-2 py-1 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-900/40 dark:text-amber-200">🖼️ {{ __('Load external images') }}</button>
                            @endif
                        </div>
                    @endif

                    @if ($this->renderedHtml)
                        @php($srcdoc = '<!doctype html><html><head><meta charset="utf-8"><base target="_blank"></head><body style="margin:0;padding:8px;font-family:system-ui,sans-serif;color:#111;background:#fff;">'.$this->renderedHtml['html'].'</body></html>')
                        <iframe wire:key="html-{{ $msg->id }}-{{ $loadImages ? '1' : '0' }}" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"
                            srcdoc="{{ $srcdoc }}"
                            class="mt-4 h-[60vh] w-full rounded-lg border border-gray-200 bg-white dark:border-gray-700"></iframe>
                    @else
                        <div class="mt-4 whitespace-pre-wrap break-words text-sm text-gray-800 dark:text-gray-200">{{ $msg->body_text ?: __('(No text content.)') }}</div>
                    @endif

                    @if ($this->selectedAccount->smtp_host)
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" wire:click="reply({{ $msg->id }})" class="rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-sm text-indigo-700 dark:border-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">↩️ {{ __('Reply') }}</button>
                            <button type="button" wire:click="forward({{ $msg->id }})" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">↪️ {{ __('Forward') }}</button>
                        </div>
                    @endif

                    <div class="mt-5 flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                        <span class="self-center text-xs font-medium uppercase text-gray-400">{{ __('Use in the tool') }}:</span>
                        <button type="button" wire:click="toTask({{ $msg->id }})" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">✅ {{ __('Task') }}</button>
                        <button type="button" wire:click="toShoppingList({{ $msg->id }})" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">🛒 {{ __('Shopping list') }}</button>
                        <button type="button" wire:click="toInbox({{ $msg->id }})" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">📥 {{ __('Inbox') }}</button>
                        @if ($msg->processed_as)
                            <span class="self-center text-xs text-emerald-600 dark:text-emerald-300">✓ {{ __('Already taken over') }}</span>
                        @endif
                    </div>

                    @if ($this->threadSiblings->isNotEmpty())
                        <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800">
                            <h3 class="mb-2 text-xs font-medium uppercase text-gray-400">{{ __('Conversation') }} ({{ $this->threadSiblings->count() + 1 }})</h3>
                            <div class="space-y-1">
                                @foreach ($this->threadSiblings as $sibling)
                                    <button type="button" wire:key="sib{{ $sibling->id }}" wire:click="open({{ $sibling->id }})" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-800">
                                        <span class="w-36 shrink-0 truncate text-gray-600 dark:text-gray-300">{{ $sibling->fromLabel() }}</span>
                                        <span class="flex-1 truncate text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::limit((string) $sibling->body_text, 60) }}</span>
                                        <span class="shrink-0 text-xs text-gray-400">{{ $sibling->date?->isoFormat('DD.MM.') }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @else
                {{-- List --}}
                @if ($this->messages->isEmpty())
                    <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-gray-500 dark:border-gray-700">{{ __('No mails yet – use “Sync now”.') }}</div>
                @else
                    <div class="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-700">
                        @foreach ($this->messages as $row)
                            @php($message = $row['message'])
                            <button type="button" wire:key="m{{ $message->id }}" wire:click="open({{ $message->id }})"
                                class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-gray-800 {{ $message->is_read ? 'bg-white dark:bg-gray-900' : 'bg-indigo-50/40 dark:bg-indigo-900/10' }}">
                                @unless ($message->is_read)<span class="h-2 w-2 shrink-0 rounded-full bg-indigo-500"></span>@else<span class="h-2 w-2 shrink-0"></span>@endunless
                                <span class="w-40 shrink-0 truncate text-sm {{ $message->is_read ? 'text-gray-600 dark:text-gray-400' : 'font-semibold text-gray-900 dark:text-gray-100' }}">{{ $message->fromLabel() }}</span>
                                <span class="flex-1 truncate text-sm {{ $message->is_read ? 'text-gray-600 dark:text-gray-400' : 'font-medium text-gray-900 dark:text-gray-100' }}">{{ $message->subject ?: __('Mail without subject') }}</span>
                                @if ($row['count'] > 1)<span class="shrink-0 rounded-full bg-gray-200 px-1.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $row['count'] }}</span>@endif
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

    {{-- Compose dialog --}}
    @if ($showCompose)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4" wire:key="compose">
            <div class="mt-8 w-full max-w-xl rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">✉️ {{ __('New mail') }}</h2>
                    <button type="button" wire:click="$set('showCompose', false)" class="text-gray-400 hover:text-gray-600">✕</button>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('To') }}</label>
                        <input type="text" wire:model="composeTo" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" placeholder="name@example.com, …" />
                        @error('composeTo')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Cc') }}</label>
                        <input type="text" wire:model="composeCc" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" placeholder="{{ __('optional') }}" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Subject') }}</label>
                        <input type="text" wire:model="composeSubject" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('Message') }}</label>
                        <textarea wire:model="composeBody" rows="10" class="mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800"></textarea>
                        @error('composeBody')<span class="text-xs text-red-600">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" wire:click="$set('showCompose', false)" class="rounded-lg px-3 py-2 text-sm text-gray-500">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="sendMail" wire:loading.attr="disabled" wire:target="sendMail" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">
                        <span wire:loading.remove wire:target="sendMail">{{ __('Send') }}</span>
                        <span wire:loading wire:target="sendMail">{{ __('Sending …') }}</span>
                    </button>
                </div>
            </div>
        </div>
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
