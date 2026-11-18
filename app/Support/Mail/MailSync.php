<?php

namespace App\Support\Mail;

use App\Models\MailAccount;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pulls new mails of a {@see MailAccount} from its IMAP folders into the local store, so they can be
 * read and reused in the tool. Mails are never deleted from the server; only new UIDs are fetched,
 * and a changed UIDVALIDITY drops the cached folder and resyncs it from scratch. Each message gets a
 * thread key from its References/In-Reply-To headers so replies can be grouped into conversations.
 */
class MailSync
{
    /** Newest mails fetched per folder and run. */
    public const MAX_PER_RUN = 50;

    /** Folders that are synced (ones the server does not have are skipped). */
    public const FOLDERS = ['INBOX', 'Sent', 'Archive'];

    /** Mails larger than this are listed but their body is not stored. */
    private const MAX_BYTES = 30 * 1024 * 1024;

    public function __construct(private Mailbox $mailbox) {}

    /**
     * Syncs all known folders of an account and returns how many new mails were stored.
     */
    public function syncAccount(MailAccount $account, int $limit = self::MAX_PER_RUN): int
    {
        $this->mailbox->open($account->imapConfig(), false);
        $total = 0;

        try {
            foreach (self::FOLDERS as $folder) {
                try {
                    $total += $this->syncFolder($account, $folder, $limit);
                } catch (Throwable) {
                    // The folder does not exist on this server – skip it.
                }
            }

            $account->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

            return $total;
        } finally {
            $this->mailbox->logout();
        }
    }

    /**
     * Syncs a single folder (opens its own connection). Kept for direct use and tests.
     */
    public function sync(MailAccount $account, string $folder = 'INBOX', int $limit = self::MAX_PER_RUN): int
    {
        $this->mailbox->open($account->imapConfig(), false);

        try {
            $stored = $this->syncFolder($account, $folder, $limit);
            $account->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

            return $stored;
        } finally {
            $this->mailbox->logout();
        }
    }

    private function syncFolder(MailAccount $account, string $folder, int $limit): int
    {
        $state = $this->mailbox->selectFolder($folder);
        $validities = $account->folder_uidvalidity ?? [];

        if (isset($validities[$folder]) && $validities[$folder] !== $state['uidvalidity']) {
            $account->messages()->where('folder', $folder)->delete();
        }

        $maxStored = (int) $account->messages()->where('folder', $folder)->max('uid');
        $new = array_slice(array_values(array_filter($this->mailbox->uidSearchAll(), fn (int $uid) => $uid > $maxStored)), -$limit);

        $stored = 0;

        foreach ($new as $uid) {
            $fetched = $this->mailbox->uidFetch($uid);

            if ($fetched['raw'] === '' || $fetched['size'] > self::MAX_BYTES) {
                continue;
            }

            $this->store($account, $folder, $uid, $fetched);
            $stored++;
        }

        $validities[$folder] = $state['uidvalidity'];
        $account->forceFill(['folder_uidvalidity' => $validities])->save();

        return $stored;
    }

    /**
     * @param  array{seen: bool, size: int, raw: string}  $fetched
     */
    private function store(MailAccount $account, string $folder, int $uid, array $fetched): void
    {
        $message = MimeMessage::parse($fetched['raw']);
        $messageId = mb_substr(trim($message->headers['message-id'] ?? '', " <>\t"), 0, 990) ?: null;

        $inReplyTo = self::ids($message->headers['in-reply-to'] ?? '');
        $references = self::ids($message->headers['references'] ?? '');
        $root = $references[0] ?? ($inReplyTo[0] ?? $messageId);

        $attachments = array_map(
            fn (array $attachment): array => ['name' => mb_substr($attachment['name'], 0, 255), 'mime' => $attachment['mime'], 'size' => strlen($attachment['content'])],
            $message->attachments,
        );

        $account->messages()->updateOrCreate(
            ['folder' => $folder, 'uid' => $uid],
            [
                'message_id' => $messageId,
                'in_reply_to' => $inReplyTo[0] ?? null,
                'references_ids' => mb_substr(implode(' ', $references), 0, 2000) ?: null,
                'thread_key' => $root !== null ? substr(sha1($root), 0, 40) : null,
                'from_address' => mb_substr($message->from, 0, 255),
                'from_name' => mb_substr($message->fromName, 0, 255),
                'to_addresses' => mb_substr(implode(', ', $message->recipients), 0, 2000),
                'subject' => mb_substr($message->subject, 0, 998),
                'body_text' => mb_substr($message->plainText(), 0, 100000),
                'body_html' => mb_substr($message->html, 0, 500000),
                'date' => self::date($message),
                'size' => $fetched['size'],
                'is_read' => $fetched['seen'],
                'has_attachments' => $attachments !== [],
                'attachments_meta' => $attachments !== [] ? $attachments : null,
            ],
        );
    }

    /**
     * Message-ids from a header value ("<a@x> <b@y>").
     *
     * @return list<string>
     */
    private static function ids(string $raw): array
    {
        preg_match_all('/<([^<>\s]+)>/', $raw, $matches);

        return array_values(array_map(fn (string $id) => mb_substr($id, 0, 990), $matches[1]));
    }

    private static function date(MimeMessage $message): ?Carbon
    {
        $raw = trim((string) ($message->headers['date'] ?? ''));

        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
