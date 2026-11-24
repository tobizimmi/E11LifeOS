<?php

namespace App\Models;

use Database\Factories\MailMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A mail synced from a {@see MailAccount}'s IMAP folder. Headers and bodies are kept encrypted so
 * they can be read and reused inside the tool; attachments stay on the server and are fetched on
 * demand by their UID when the member takes one over.
 *
 * @property int $id
 * @property int $mail_account_id
 * @property string $folder
 * @property int $uid
 * @property ?string $message_id
 * @property ?string $in_reply_to
 * @property ?string $references_ids
 * @property ?string $thread_key
 * @property ?string $from_address
 * @property ?string $from_name
 * @property ?string $to_addresses
 * @property ?string $subject
 * @property ?string $body_text
 * @property ?string $body_html
 * @property ?Carbon $date
 * @property int $size
 * @property bool $is_read
 * @property bool $is_flagged
 * @property bool $has_attachments
 * @property ?array<int, array{name: string, mime: string, size: int}> $attachments_meta
 * @property ?string $processed_as
 */
#[Fillable(['mail_account_id', 'folder', 'uid', 'message_id', 'in_reply_to', 'references_ids', 'thread_key', 'from_address', 'from_name', 'to_addresses', 'subject', 'body_text', 'body_html', 'date', 'size', 'is_read', 'is_flagged', 'has_attachments', 'attachments_meta', 'processed_as'])]
class MailMessage extends Model
{
    /** @use HasFactory<MailMessageFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'uid' => 'integer',
            'from_address' => 'encrypted',
            'from_name' => 'encrypted',
            'to_addresses' => 'encrypted',
            'subject' => 'encrypted',
            'body_text' => 'encrypted',
            'body_html' => 'encrypted',
            'date' => 'datetime',
            'size' => 'integer',
            'is_read' => 'boolean',
            'is_flagged' => 'boolean',
            'has_attachments' => 'boolean',
            'attachments_meta' => 'encrypted:array',
        ];
    }

    /**
     * @return BelongsTo<MailAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function fromLabel(): string
    {
        return $this->from_name ?: ($this->from_address ?: __('Unknown sender'));
    }

    /**
     * The member's own messages (through the account they belong to).
     *
     * @param  Builder<MailMessage>  $query
     */
    public function scopeForUser(Builder $query, User $user, int $householdId): void
    {
        $query->whereHas('account', fn (Builder $account) => $account->where('user_id', $user->id)->where('household_id', $householdId));
    }
}
