<?php

namespace App\Models;

use Database\Factories\SupportMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One entry in the course of a ticket: a public reply, an internal note (administrators only) or
 * an event (status, priority, assignment … changed).
 *
 * @property list<array{path: string, name: string, size: int, mime: string}>|null $attachments
 */
#[Fillable(['support_ticket_id', 'user_id', 'kind', 'body', 'attachments'])]
class SupportMessage extends Model
{
    /** @use HasFactory<SupportMessageFactory> */
    use HasFactory;

    public const REPLY = 'reply';

    public const NOTE = 'note';

    public const EVENT = 'event';

    protected static function booted(): void
    {
        static::deleted(function (SupportMessage $message) {
            foreach ($message->attachments ?? [] as $file) {
                Storage::disk(SupportTicket::DISK)->delete($file['path']);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isPublic(): bool
    {
        return $this->kind === self::REPLY;
    }
}
