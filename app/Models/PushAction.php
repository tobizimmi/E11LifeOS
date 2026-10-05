<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A button in a push notification ("Yes, switch off"): opened by a secret link, answered once,
 * expires after a few hours. The payload says what happens (e.g. continue an automation).
 *
 * @property array{kind: string, pending_id?: int, choice?: string} $payload
 * @property Carbon $expires_at
 * @property Carbon|null $answered_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'token_hash', 'label', 'payload', 'expires_at', 'answered_at'])]
class PushAction extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Creates the button and returns it with the link (the token is only stored hashed).
     *
     * @param  array{kind: string, pending_id?: int, choice?: string}  $payload
     * @return array{action: self, url: string}
     */
    public static function issue(User $user, string $label, array $payload, Carbon $expiresAt): array
    {
        $token = Str::random(40);
        $action = self::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'label' => mb_substr($label, 0, 255), 'payload' => $payload, 'expires_at' => $expiresAt]);

        return ['action' => $action, 'url' => route('push-actions.show', $token)];
    }

    public static function findByToken(string $token): ?self
    {
        return self::where('token_hash', hash('sha256', $token))->first();
    }

    public function isOpen(): bool
    {
        return $this->answered_at === null && $this->expires_at->isFuture();
    }
}
