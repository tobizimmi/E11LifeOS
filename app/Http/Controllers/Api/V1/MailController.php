<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\MailAccount;
use App\Models\MailMessage;
use App\Models\ShoppingList;
use App\Support\Mail\Mailbox;
use App\Support\Mail\MailSync;
use App\Support\QuickCapture;
use App\Support\ShoppingLists;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Mail for the app: the member's private IMAP accounts, the synced mails (read-only) and taking a
 * mail over as a task, an inbox entry or shopping list items.
 */
class MailController extends ApiController
{
    public function accounts(): JsonResponse
    {
        $accounts = $this->accountQuery()->orderBy('label')->get();
        $unread = MailMessage::query()->whereIn('mail_account_id', $accounts->pluck('id'))->where('is_read', false)
            ->selectRaw('mail_account_id, count(*) as total')->groupBy('mail_account_id')->pluck('total', 'mail_account_id');

        return $this->ok($accounts->map(fn (MailAccount $account): array => $this->accountData($account) + ['unread' => (int) ($unread[$account->id] ?? 0)])->values()->all());
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
            $new = app(MailSync::class)->sync($model);

            return $this->ok(['new' => $new]);
        } catch (Throwable $exception) {
            $model->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 300)])->save();

            return response()->json(['success' => false, 'message' => __('Sync failed: :error', ['error' => $exception->getMessage()])], 502);
        }
    }

    public function messages(Request $request, int $account): JsonResponse
    {
        $model = $this->account($account);
        $messages = MailMessage::query()->where('mail_account_id', $model->id)->orderByDesc('date')->orderByDesc('uid')->limit(300)->get();
        $search = trim(mb_strtolower((string) $request->query('q', '')));

        if ($search !== '') {
            // Subject and sender are encrypted at rest, so filtering happens after decryption.
            $messages = $messages->filter(fn (MailMessage $message): bool => str_contains(mb_strtolower((string) $message->subject.' '.$message->from_address.' '.$message->from_name), $search))->values();
        }

        return $this->ok($messages->map(fn (MailMessage $message): array => [
            'id' => $message->id,
            'from' => $message->fromLabel(),
            'subject' => $message->subject ?: null,
            'preview' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $message->body_text)), 0, 120),
            'date' => $message->date?->toIso8601String(),
            'is_read' => (bool) $message->is_read,
            'has_attachments' => (bool) $message->has_attachments,
            'processed_as' => $message->processed_as,
        ])->values()->all());
    }

    /**
     * Opens a mail (marks it read).
     */
    public function show(int $message): JsonResponse
    {
        $model = $this->message($message);

        if (! $model->is_read) {
            $model->forceFill(['is_read' => true])->save();
        }

        return $this->ok([
            'id' => $model->id,
            'from' => $model->fromLabel(),
            'from_address' => $model->from_address,
            'to' => $model->to_addresses,
            'subject' => $model->subject ?: null,
            'date' => $model->date?->toIso8601String(),
            'body' => (string) $model->body_text,
            'attachments' => collect($model->attachments_meta ?? [])->map(fn (array $attachment): array => ['name' => $attachment['name'], 'size' => $attachment['size']])->values()->all(),
            'processed_as' => $model->processed_as,
        ]);
    }

    public function toTask(int $message): JsonResponse
    {
        $model = $this->message($message);
        $household = $this->user()->currentHousehold() ?? abort(404);
        $task = QuickCapture::task($this->user(), $household->id, (string) ($model->subject ?: __('Mail without subject')));

        if ($task && $model->body_text) {
            $task->forceFill(['content' => ['body' => $model->body_text]])->saveQuietly();
        }

        $model->forceFill(['processed_as' => 'task'])->save();

        return $this->ok(['processed_as' => 'task']);
    }

    public function toInbox(int $message): JsonResponse
    {
        $model = $this->message($message);
        $household = $this->user()->currentHousehold() ?? abort(404);
        $item = QuickCapture::inbox($this->user(), $household->id, (string) ($model->subject ?: __('Mail without subject')));

        if ($item && $model->body_text) {
            $item->forceFill(['content' => ['body' => $model->body_text]])->saveQuietly();
        }

        $model->forceFill(['processed_as' => 'inbox'])->save();

        return $this->ok(['processed_as' => 'inbox']);
    }

    public function toShopping(int $message): JsonResponse
    {
        $model = $this->message($message);
        $household = $this->user()->currentHousehold() ?? abort(404);
        $list = ShoppingList::defaultFor($household->id);
        $lines = collect(preg_split('/\R/u', (string) $model->body_text) ?: [])
            ->map(fn (string $line): string => trim((string) preg_replace('/^[\s\-*•·–]+/u', '', $line)))
            ->filter(fn (string $line): bool => $line !== '' && mb_strlen($line) <= 80)
            ->unique()->take(40);

        foreach ($lines as $line) {
            ShoppingLists::add($list, $line, null, $this->user()->id, ['note' => __('By mail')]);
        }

        $model->forceFill(['processed_as' => 'shopping'])->save();

        return $this->ok(['processed_as' => 'shopping', 'added' => $lines->count()]);
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
            'username' => $account->username,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'last_error' => $account->last_error,
        ];
    }

    /**
     * @return Builder<MailAccount>
     */
    private function accountQuery(): Builder
    {
        $household = $this->user()->currentHousehold() ?? abort(404);

        return MailAccount::query()->forUser($this->user(), $household->id);
    }

    private function account(int $id): MailAccount
    {
        return $this->accountQuery()->findOrFail($id);
    }

    private function message(int $id): MailMessage
    {
        $household = $this->user()->currentHousehold() ?? abort(404);

        return MailMessage::query()->forUser($this->user(), $household->id)->findOrFail($id);
    }
}
