<?php

namespace App\Models;

use Database\Factories\MailDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved, unsent draft of a mail the member is composing for one of their {@see MailAccount}s.
 * Recipients, subject and body are kept encrypted like the rest of the mail content.
 *
 * @property int $id
 * @property int $mail_account_id
 * @property int $user_id
 * @property ?string $to_addresses
 * @property ?string $cc
 * @property ?string $bcc
 * @property ?string $subject
 * @property ?string $body
 * @property ?int $reply_to_id
 */
#[Fillable(['mail_account_id', 'user_id', 'to_addresses', 'cc', 'bcc', 'subject', 'body', 'reply_to_id'])]
class MailDraft extends Model
{
    /** @use HasFactory<MailDraftFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'to_addresses' => 'encrypted',
            'cc' => 'encrypted',
            'bcc' => 'encrypted',
            'subject' => 'encrypted',
            'body' => 'encrypted',
            'reply_to_id' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    /**
     * The member's own drafts (through the account they belong to).
     *
     * @param  Builder<MailDraft>  $query
     */
    public function scopeForUser(Builder $query, User $user, int $householdId): void
    {
        $query->whereHas('account', fn (Builder $account) => $account->where('user_id', $user->id)->where('household_id', $householdId));
    }

    public function previewLabel(): string
    {
        return $this->subject ?: ($this->to_addresses ?: __('(No subject)'));
    }
}
