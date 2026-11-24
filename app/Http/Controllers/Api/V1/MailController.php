<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MailAccount;
use App\Models\MailDraft;
use App\Models\MailMessage;
use App\Models\PaperlessInstance;
use App\Models\ShoppingList;
use App\Support\Mail\Mailbox;
use App\Support\Mail\MailHtml;
use App\Support\Mail\MailSender;
use App\Support\Mail\MailSwipeActions;
use App\Support\Mail\MailSync;
use App\Support\Mail\MimeMessage;
use App\Support\Paperless\PaperlessUploader;
use App\Support\QuickCapture;
use App\Support\Security\VirusScanner;
use App\Support\ShoppingLists;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Mail for the app: the member's private IMAP accounts, folders and conversations, reading (HTML or
 * text), marking, archiving, sending, drafts, attachments and taking a mail over as a task, note,
 * inbox entry or shopping list items.
 */
class MailController extends ApiController
{
    /** Attachment types that can be archived to Paperless (documents and photos). */
    private const PAPERLESS_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic'];

    private const ARCHIVE_FOLDER = 'Archive';

    private const TRASH_FOLDER = 'Trash';

    // ── Accounts ─────────────────────────────────────────────────────
    public function accounts(): JsonResponse
    {
        $accounts = $this->accountQuery()->orderBy('label')->get();
        $unread = MailMessage::query()->whereIn('mail_account_id', $accounts->pluck('id'))->where('folder', 'INBOX')->where('is_read', false)
            ->selectRaw('mail_account_id, count(*) as total')->groupBy('mail_account_id')->pluck('total', 'mail_account_id');

        return $this->ok([
            'accounts' => $accounts->map(fn (MailAccount $account): array => $this->accountData($account) + ['unread' => (int) ($unread[$account->id] ?? 0)])->values()->all(),
            'swipe' => $this->swipeData(),
            'paperless' => PaperlessInstance::defaultFor($this->user()) !== null,
        ]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        $household = $this->user()->currentHousehold() ?? abort(404);
        $data = $this->validated($request, null);
        $account = new MailAccount(['user_id' => $this->user()->id, 'household_id' => $household->id]);
        $account->fill($data + ['password' => (string) $request->input('password')])->save();

        return $this->ok($this->accountData($account), 201);
    }

    public function updateAccount(Request $request, int $account): JsonResponse
    {
        $model = $this->account($account);
        $data = $this->validated($request, $model);

        if ($request->filled('password')) {
            $data['password'] = (string) $request->input('password');
        }

        $model->fill($data)->save();

        return $this->ok($this->accountData($model));
    }

    public function destroyAccount(int $account): JsonResponse
    {
        $this->account($account)->delete();

        return $this->ok();
    }

    /**
     * Tries the connection with the entered data (and the saved password when none is given).
     */
    public function test(Request $request): JsonResponse
    {
        $existing = $request->filled('id') ? $this->account((int) $request->input('id')) : null;
        $data = $this->validated($request, $existing);
        $password = $request->filled('password') ? (string) $request->input('password') : (string) $existing?->password;

        try {
            $mailbox = app(Mailbox::class);
            $mailbox->open([
                'host' => $data['imap_host'], 'port' => $data['imap_port'], 'encryption' => $data['imap_encryption'],
                'validate_cert' => $data['validate_cert'], 'username' => $data['username'], 'password' => $password,
            ], false);
            $state = $mailbox->selectFolder('INBOX');
            $mailbox->logout();

            return $this->ok(['ok' => true, 'message' => __(':count mails in the inbox.', ['count' => $state['exists']])]);
        } catch (Throwable $exception) {
            return $this->ok(['ok' => false, 'message' => $exception->getMessage()]);
        }
    }

    public function sync(int $account): JsonResponse
    {
        $model = $this->account($account);

        try {
            return $this->ok(['new' => app(MailSync::class)->syncAccount($model)]);
        } catch (Throwable $exception) {
            $model->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 300)])->save();

            return response()->json(['success' => false, 'message' => __('Sync failed: :error', ['error' => $exception->getMessage()])], 502);
        }
    }

    // ── Reading ──────────────────────────────────────────────────────
    /**
     * One row per conversation of the folder (newest first); a search spans every folder.
     */
    public function messages(Request $request, int $account): JsonResponse
    {
        $model = $this->account($account);
        $folder = (string) $request->query('folder', 'INBOX');
        $search = trim(mb_strtolower((string) $request->query('q', '')));

        $present = MailMessage::query()->where('mail_account_id', $model->id)->distinct()->pluck('folder')->all();
        $order = MailSync::FOLDERS;
        usort($present, fn (string $a, string $b): int => (array_search($a, $order, true) === false ? 99 : array_search($a, $order, true)) <=> (array_search($b, $order, true) === false ? 99 : array_search($b, $order, true)));

        $query = MailMessage::query()->where('mail_account_id', $model->id);

        if ($search === '') {
            $query->where('folder', $folder);
        }

        $messages = $query->orderByDesc('date')->orderByDesc('uid')->limit(500)->get();

        if ($search !== '') {
            // Subject and sender are encrypted at rest, so filtering happens after decryption.
            $messages = $messages->filter(fn (MailMessage $message): bool => str_contains(mb_strtolower((string) $message->subject.' '.$message->from_address.' '.$message->from_name), $search));
        }

        $threads = $messages
            ->groupBy(fn (MailMessage $message): string => $message->thread_key ?: 'id:'.$message->id)
            ->map(fn ($group): array => ['message' => $group->firstOrFail(), 'count' => $group->count(), 'unread' => $group->where('is_read', false)->count()])
            ->sortByDesc(fn (array $row): int => $row['message']->date?->getTimestamp() ?? 0)
            ->values();

        return $this->ok([
            'folders' => $present !== [] ? $present : ['INBOX'],
            'threads' => $threads->map(fn (array $row): array => [
                'id' => $row['message']->id,
                'folder' => $row['message']->folder,
                'from' => $row['message']->fromLabel(),
                'subject' => $row['message']->subject ?: null,
                'preview' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $row['message']->body_text)), 0, 120),
                'date' => $row['message']->date?->toIso8601String(),
                'is_read' => $row['unread'] === 0,
                'is_flagged' => (bool) $row['message']->is_flagged,
                'has_attachments' => (bool) $row['message']->has_attachments,
                'processed_as' => $row['message']->processed_as,
                'count' => $row['count'],
            ])->all(),
        ]);
    }

    /**
     * Opens a mail (marks it read). The HTML body is cleaned for display; remote content stays blocked
     * unless `images=1` is passed.
     */
    public function show(Request $request, int $message): JsonResponse
    {
        $model = $this->message($message);

        if (! $model->is_read) {
            $model->forceFill(['is_read' => true])->save();
        }

        $html = $model->body_html ? MailHtml::render((string) $model->body_html, $request->boolean('images')) : null;
        $siblings = $model->thread_key
            ? MailMessage::query()->where('mail_account_id', $model->mail_account_id)->where('thread_key', $model->thread_key)->where('id', '!=', $model->id)->orderBy('date')->get()
            : collect();

        return $this->ok([
            'id' => $model->id,
            'account_id' => $model->mail_account_id,
            'folder' => $model->folder,
            'from' => $model->fromLabel(),
            'from_address' => $model->from_address,
            'to' => $model->to_addresses,
            'subject' => $model->subject ?: null,
            'date' => $model->date?->toIso8601String(),
            'body' => (string) $model->body_text,
            'html' => $html['html'] ?? null,
            'images_blocked' => (bool) ($html['blocked'] ?? false),
            'is_read' => true,
            'is_flagged' => (bool) $model->is_flagged,
            'attachments' => collect($model->attachments_meta ?? [])->map(fn (array $attachment, int $index): array => [
                'index' => $index,
                'name' => $attachment['name'],
                'mime' => $attachment['mime'],
                'size' => $attachment['size'],
                'archivable' => in_array($attachment['mime'], self::PAPERLESS_TYPES, true),
            ])->values()->all(),
            'thread' => $siblings->map(fn (MailMessage $sibling): array => ['id' => $sibling->id, 'from' => $sibling->fromLabel(), 'subject' => $sibling->subject ?: null, 'folder' => $sibling->folder, 'date' => $sibling->date?->toIso8601String()])->values()->all(),
            'processed_as' => $model->processed_as,
        ]);
    }

    // ── Marking and moving (on the IMAP server) ──────────────────────
    public function toggleRead(int $message): JsonResponse
    {
        $model = $this->message($message);
        $target = ! $model->is_read;
        $this->onServer($model, fn (Mailbox $mailbox) => $mailbox->uidStore($model->uid, '\\Seen', $target));
        $model->forceFill(['is_read' => $target])->save();

        return $this->ok(['is_read' => $target]);
    }

    public function toggleFlag(int $message): JsonResponse
    {
        $model = $this->message($message);
        $target = ! $model->is_flagged;
        $this->onServer($model, fn (Mailbox $mailbox) => $mailbox->uidStore($model->uid, '\\Flagged', $target));
        $model->forceFill(['is_flagged' => $target])->save();

        return $this->ok(['is_flagged' => $target]);
    }

    public function archive(int $message): JsonResponse
    {
        return $this->moveAway($message, self::ARCHIVE_FOLDER);
    }

    public function trash(int $message): JsonResponse
    {
        return $this->moveAway($message, self::TRASH_FOLDER);
    }

    // ── Taking a mail over ───────────────────────────────────────────
    public function toTask(int $message): JsonResponse
    {
        $model = $this->message($message);
        $task = QuickCapture::task($this->user(), $this->householdId(), (string) ($model->subject ?: __('Mail without subject')));

        if ($task && $model->body_text) {
            $task->forceFill(['content' => ['body' => $model->body_text]])->saveQuietly();
        }

        return $this->processed($model, 'task');
    }

    public function toInbox(int $message): JsonResponse
    {
        $model = $this->message($message);
        $item = QuickCapture::inbox($this->user(), $this->householdId(), (string) ($model->subject ?: __('Mail without subject')));

        if ($item && $model->body_text) {
            $item->forceFill(['content' => ['body' => $model->body_text]])->saveQuietly();
        }

        return $this->processed($model, 'inbox');
    }

    public function toNote(int $message): JsonResponse
    {
        $model = $this->message($message);
        $text = trim((string) ($model->subject ?: __('Mail without subject')));
        $note = QuickCapture::note($this->user(), $this->householdId(), $text);

        if ($note && $model->body_text) {
            $note->forceFill(['content' => ['body' => $model->body_text]])->saveQuietly();
        }

        return $this->processed($model, 'note');
    }

    public function toShopping(int $message): JsonResponse
    {
        $model = $this->message($message);
        $list = ShoppingList::defaultFor($this->householdId());
        $lines = collect(preg_split('/\R/u', (string) $model->body_text) ?: [])
            ->map(fn (string $line): string => trim((string) preg_replace('/^[\s\-*•·–]+/u', '', $line)))
            ->filter(fn (string $line): bool => $line !== '' && mb_strlen($line) <= 80)
            ->unique()->take(40);

        foreach ($lines as $line) {
            ShoppingLists::add($list, $line, null, $this->user()->id, ['note' => __('By mail')]);
        }

        return $this->processed($model, 'shopping', ['added' => $lines->count()]);
    }

    // ── Attachments ──────────────────────────────────────────────────
    public function attachment(int $message, int $index): Response
    {
        $model = $this->message($message);
        $meta = ($model->attachments_meta ?? [])[$index] ?? abort(404);
        $attachment = $this->fetchAttachment($model, $index, $meta) ?? abort(502, __('The attachment could not be fetched from the server.'));

        return response($attachment['content'], 200, [
            'Content-Type' => $meta['mime'] ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.str_replace(['"', "\r", "\n"], '', mb_substr($meta['name'], 0, 255) ?: 'attachment').'"',
        ]);
    }

    public function toPaperless(int $message, int $index): JsonResponse
    {
        $model = $this->message($message);
        $meta = ($model->attachments_meta ?? [])[$index] ?? null;

        if (! $meta || ! in_array($meta['mime'], self::PAPERLESS_TYPES, true)) {
            throw ValidationException::withMessages(['attachment' => __('This attachment cannot be archived.')]);
        }

        $instance = PaperlessInstance::defaultFor($this->user()) ?? throw ValidationException::withMessages(['attachment' => __('Connect Paperless first to archive attachments.')]);
        $attachment = $this->fetchAttachment($model, $index, $meta) ?? abort(502, __('The attachment could not be fetched from the server.'));

        if (VirusScanner::isEnabled()) {
            $scan = app(VirusScanner::class)->scanString($attachment['content']);

            if ($scan['status'] === VirusScanner::INFECTED || ($scan['status'] === VirusScanner::UNAVAILABLE && config('security.clamav.fail_closed'))) {
                throw ValidationException::withMessages(['attachment' => __(':file was not archived (virus check).', ['file' => $meta['name']])]);
            }
        }

        $title = pathinfo($meta['name'], PATHINFO_FILENAME) ?: ((string) $model->subject ?: __('Mail attachment'));
        $queued = app(PaperlessUploader::class)->store($instance, $this->user(), $this->householdId(), $attachment['content'], mb_substr($meta['name'], 0, 255), mb_substr($title, 0, 255));

        return $this->ok(['queued' => (bool) $queued]);
    }

    // ── Writing ──────────────────────────────────────────────────────
    /**
     * Sends a mail (multipart: up to 5 attachments, 10 MB each); `reply_to_id` threads the answer and
     * `draft_id` removes the draft afterwards.
     */
    public function send(Request $request, int $account, MailSender $sender): JsonResponse
    {
        $model = $this->account($account);
        $data = $request->validate([
            'to' => ['required', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'bcc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['nullable', 'string', 'max:998'],
            'body' => ['nullable', 'string', 'max:100000'],
            'reply_to_id' => ['nullable', 'integer'],
            'draft_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $options = ['cc' => (string) ($data['cc'] ?? ''), 'bcc' => (string) ($data['bcc'] ?? ''), 'attachments' => []];

        foreach ($request->file('attachments', []) as $file) {
            $options['attachments'][] = ['name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime' => $file->getMimeType() ?: 'application/octet-stream', 'content' => (string) file_get_contents($file->getRealPath())];
        }

        if (! empty($data['reply_to_id'])) {
            $original = MailMessage::query()->where('mail_account_id', $model->id)->find($data['reply_to_id']);

            if ($original?->message_id) {
                $options['in_reply_to'] = '<'.trim($original->message_id, '<>').'>';
                $options['references'] = $options['in_reply_to'];
            }
        }

        try {
            $sender->send($model, $data['to'], (string) ($data['subject'] ?? ''), (string) ($data['body'] ?? ''), $options);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['to' => __('Sending failed: :error', ['error' => $exception->getMessage()])]);
        }

        if (! empty($data['draft_id'])) {
            $this->draftQuery()->whereKey($data['draft_id'])->delete();
        }

        return $this->ok();
    }

    public function drafts(int $account): JsonResponse
    {
        $model = $this->account($account);

        return $this->ok(MailDraft::query()->where('mail_account_id', $model->id)->latest('updated_at')->limit(50)->get()->map(fn (MailDraft $draft): array => $this->draftData($draft))->all());
    }

    public function saveDraft(Request $request, int $account): JsonResponse
    {
        $model = $this->account($account);
        $input = $request->validate([
            'id' => ['nullable', 'integer'],
            'to' => ['nullable', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'bcc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['nullable', 'string', 'max:998'],
            'body' => ['nullable', 'string', 'max:100000'],
            'reply_to_id' => ['nullable', 'integer'],
        ]);
        $data = [
            'to_addresses' => ($input['to'] ?? '') ?: null,
            'cc' => ($input['cc'] ?? '') ?: null,
            'bcc' => ($input['bcc'] ?? '') ?: null,
            'subject' => ($input['subject'] ?? '') ?: null,
            'body' => ($input['body'] ?? '') ?: null,
            'reply_to_id' => $input['reply_to_id'] ?? null,
        ];

        $draft = ! empty($input['id']) ? $this->draftQuery()->where('mail_account_id', $model->id)->find($input['id']) : null;

        if ($draft) {
            $draft->update($data);
        } else {
            $draft = MailDraft::create($data + ['mail_account_id' => $model->id, 'user_id' => $this->user()->id]);
        }

        return $this->ok($this->draftData($draft), 201);
    }

    public function destroyDraft(int $draft): JsonResponse
    {
        $this->draftQuery()->whereKey($draft)->delete();

        return $this->ok();
    }

    // ── Swipe actions ────────────────────────────────────────────────
    public function saveSwipe(Request $request): JsonResponse
    {
        $data = $request->validate(['right' => ['sometimes', 'string'], 'left' => ['sometimes', 'string']]);

        foreach (['right', 'left'] as $side) {
            if (isset($data[$side])) {
                abort_unless(MailSwipeActions::exists($data[$side]), 422);
                $this->user()->update(['mail_swipe_'.$side => $data[$side]]);
            }
        }

        return $this->ok($this->swipeData());
    }

    // ── Helpers ──────────────────────────────────────────────────────
    /**
     * @return array<string, mixed>
     */
    private function swipeData(): array
    {
        $user = $this->user();

        return [
            'right' => $user->mail_swipe_right ?? MailSwipeActions::DEFAULT_RIGHT,
            'left' => $user->mail_swipe_left ?? MailSwipeActions::DEFAULT_LEFT,
            'actions' => collect(MailSwipeActions::all())->map(fn (array $action, string $key): array => ['key' => $key, 'label' => $action['label']])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function processed(MailMessage $message, string $as, array $extra = []): JsonResponse
    {
        $message->forceFill(['processed_as' => $as])->save();

        return $this->ok(['processed_as' => $as] + $extra);
    }

    /**
     * Moves a mail to another server folder and drops the local copy (the next sync re-adds it under the
     * new folder). Does nothing when it already is in the target folder.
     */
    private function moveAway(int $id, string $folder): JsonResponse
    {
        $model = $this->message($id);

        if ($model->folder !== $folder) {
            $this->onServer($model, fn (Mailbox $mailbox) => $mailbox->uidMove($model->uid, $folder));
            $model->delete();
        }

        return $this->ok();
    }

    /**
     * Runs an action against the mail's folder on the account's IMAP server; a failure becomes a 502.
     */
    private function onServer(MailMessage $message, Closure $operation): void
    {
        $mailbox = app(Mailbox::class);

        try {
            $mailbox->open($message->account->imapConfig(), false);
            $mailbox->selectFolder($message->folder);
            $operation($mailbox);
        } catch (Throwable $exception) {
            report($exception);
            abort(502, __('The action could not be completed on the server: :error', ['error' => $exception->getMessage()]));
        } finally {
            rescue(fn () => $mailbox->logout(), report: false);
        }
    }

    /**
     * Re-fetches one attachment's raw content by the mail's UID (attachments stay on the server).
     *
     * @param  array{name: string, mime: string, size: int}  $meta
     * @return array{name: string, mime: string, content: string}|null
     */
    private function fetchAttachment(MailMessage $message, int $index, array $meta): ?array
    {
        $mailbox = app(Mailbox::class);

        try {
            $mailbox->open($message->account->imapConfig(), false);
            $mailbox->selectFolder($message->folder);
            $raw = $mailbox->uidFetch($message->uid)['raw'];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            rescue(fn () => $mailbox->logout(), report: false);
        }

        if ($raw === '') {
            return null;
        }

        $attachment = MimeMessage::parse($raw)->attachments[$index] ?? null;

        return $attachment && $attachment['mime'] === $meta['mime'] && $attachment['content'] !== '' ? $attachment : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?MailAccount $existing): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'provider' => ['required', 'in:'.implode(',', MailAccount::PROVIDERS)],
            'imap_host' => ['required', 'string', 'max:255'],
            'imap_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'imap_encryption' => ['required', 'in:'.implode(',', MailAccount::ENCRYPTIONS)],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_encryption' => ['nullable', 'in:'.implode(',', MailAccount::ENCRYPTIONS)],
            'validate_cert' => ['required', 'boolean'],
            'notify_new_mail' => ['sometimes', 'boolean'],
            'signature' => ['nullable', 'string', 'max:2000'],
            'username' => ['required', 'string', 'max:255'],
            'password' => [$existing ? 'nullable' : 'required', 'string', 'max:255'],
        ]);

        if (! $existing && blank($data['password'] ?? null)) {
            throw ValidationException::withMessages(['password' => __('The password field is required.')]);
        }

        unset($data['password']);
        $smtp = trim((string) ($data['smtp_host'] ?? ''));
        $data['smtp_host'] = $smtp !== '' ? $smtp : null;
        $data['smtp_encryption'] = $smtp !== '' ? ($data['smtp_encryption'] ?? 'ssl') : null;
        $data['signature'] = trim((string) ($data['signature'] ?? '')) ?: null;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountData(MailAccount $account): array
    {
        return [
            'id' => $account->id,
            'label' => $account->label,
            'provider' => $account->provider,
            'imap_host' => $account->imap_host,
            'imap_port' => $account->imap_port,
            'imap_encryption' => $account->imap_encryption,
            'smtp_host' => $account->smtp_host,
            'smtp_port' => $account->smtp_port,
            'smtp_encryption' => $account->smtp_encryption,
            'validate_cert' => $account->validate_cert,
            'notify_new_mail' => (bool) $account->notify_new_mail,
            'signature' => $account->signature,
            'username' => $account->username,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'last_error' => $account->last_error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function draftData(MailDraft $draft): array
    {
        return ['id' => $draft->id, 'to' => $draft->to_addresses, 'cc' => $draft->cc, 'bcc' => $draft->bcc, 'subject' => $draft->subject, 'body' => $draft->body, 'reply_to_id' => $draft->reply_to_id, 'label' => $draft->previewLabel(), 'updated_at' => $draft->updated_at?->toIso8601String()];
    }

    /**
     * @return Builder<MailDraft>
     */
    private function draftQuery(): Builder
    {
        return MailDraft::query()->forUser($this->user(), $this->householdId());
    }

    /**
     * @return Builder<MailAccount>
     */
    private function accountQuery(): Builder
    {
        return MailAccount::query()->forUser($this->user(), $this->householdId());
    }

    private function account(int $id): MailAccount
    {
        return $this->accountQuery()->findOrFail($id);
    }

    private function message(int $id): MailMessage
    {
        return MailMessage::query()->forUser($this->user(), $this->householdId())->with('account')->findOrFail($id);
    }

    private function householdId(): int
    {
        return ($this->user()->currentHousehold() ?? abort(404))->id;
    }
}
