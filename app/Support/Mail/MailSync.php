<?php

namespace App\Support\Mail;

use App\Models\MailAccount;
use Illuminate\Support\Carbon;

/**
 * Pulls new mails of a {@see MailAccount} from its IMAP folder into the local store, so they can be
 * read and reused in the tool. Mails are never deleted from the server; only new UIDs are fetched,
 * and a changed UIDVALIDITY drops the cached folder and resyncs from scratch.
 */
class MailSync
{
    /** Newest mails fetched per account and run. */
    public const MAX_PER_RUN = 50;

    /** Mails larger than this are listed but their body is not stored. */
    private const MAX_BYTES = 30 * 1024 * 1024;

    public function __construct(private Mailbox $mailbox) {}

    /**
     * Syncs one folder of an account and returns how many new mails were stored.
     */
    public function sync(MailAccount $account, string $folder = 'INBOX', int $limit = self::MAX_PER_RUN): int
    {
        $this->mailbox->open($account->imapConfig(), false);

        try {
            $state = $this->mailbox->selectFolder($folder);

            if ($account->uid_validity !== null && $account->uid_validity !== $state['uidvalidity']) {
                $account->messages()->where('folder', $folder)->delete();
                $account->uid_validity = null;
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

            $account->forceFill(['uid_validity' => $state['uidvalidity'], 'last_synced_at' => now(), 'last_error' => null])->save();

            return $stored;
        } finally {
            $this->mailbox->logout();
        }
    }

    /**
     * @param  array{seen: bool, size: int, raw: string}  $fetched
     */
    private function store(MailAccount $account, string $folder, int $uid, array $fetched): void
    {
        $message = MimeMessage::parse($fetched['raw']);
        $messageId = mb_substr(trim($message->headers['message-id'] ?? '', " <>\t"), 0, 990) ?: null;

        $attachments = array_map(
            fn (array $attachment): array => ['name' => mb_substr($attachment['name'], 0, 255), 'mime' => $attachment['mime'], 'size' => strlen($attachment['content'])],
            $message->attachments,
        );

        $account->messages()->updateOrCreate(
            ['folder' => $folder, 'uid' => $uid],
            [
                'message_id' => $messageId,
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
