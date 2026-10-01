<?php

namespace App\Models;

use Database\Factories\InboundMailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mail sent to the household's address and what the intake did with it (only sender, subject
 * and result are kept; the content went into the inbox entry, task, shopping list or trip).
 *
 * @property int $household_id
 * @property ?int $user_id
 * @property ?int $trip_id
 * @property ?int $item_id
 * @property string $from_address
 * @property ?string $subject
 * @property string $status inbox, task, shopping, booking, rejected
 * @property ?array<int, array<string, string>> $drafts booking drafts not taken over yet
 * @property int $attachments
 * @property ?string $note
 */
#[Fillable(['household_id', 'user_id', 'trip_id', 'item_id', 'message_id', 'from_address', 'subject', 'status', 'drafts', 'attachments', 'note'])]
class InboundMail extends Model
{
    /** @use HasFactory<InboundMailFactory> */
    use HasFactory;

    public const STATUSES = ['inbox', 'task', 'shopping', 'booking', 'rejected'];

    protected function casts(): array
    {
        return [
            'from_address' => 'encrypted',
            'subject' => 'encrypted',
            'drafts' => 'encrypted:array',
            'note' => 'encrypted',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'task' => '✅ '.__('Task'),
            'shopping' => '🛒 '.__('Shopping list'),
            'booking' => '🎫 '.__('Booking'),
            'rejected' => '⛔ '.__('Not processed'),
            default => '📥 '.__('Inbox'),
        };
    }
}
