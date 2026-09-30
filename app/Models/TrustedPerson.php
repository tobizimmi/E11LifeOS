<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Someone who may open the emergency folder: they request access through their personal link and
 * get it after the waiting period, unless the owner declines in between.
 */
#[Fillable(['user_id', 'name', 'email', 'token_hash', 'wait_days', 'invited_at', 'requested_at', 'granted_at', 'last_viewed_at'])]
class TrustedPerson extends Model
{
    protected $table = 'trusted_people';

    protected function casts(): array
    {
        return [
            'wait_days' => 'integer',
            'invited_at' => 'datetime',
            'requested_at' => 'datetime',
            'granted_at' => 'datetime',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A new secret link; only its hash is stored, so the link must be sent right away.
     */
    public function issueToken(): string
    {
        $token = Str::random(48);
        $this->forceFill(['token_hash' => self::hashToken($token)]);

        return $token;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return strlen($token) >= 32 ? self::where('token_hash', self::hashToken($token))->first() : null;
    }

    public function hasAccess(): bool
    {
        return $this->granted_at !== null;
    }

    public function isWaiting(): bool
    {
        return $this->requested_at !== null && $this->granted_at === null;
    }

    public function grantsOn(): ?Carbon
    {
        return $this->requested_at?->copy()->addDays($this->wait_days);
    }
}
