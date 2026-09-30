<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of a member's security log (sign-ins, failed attempts, changes, sensitive access).
 */
#[Fillable(['user_id', 'event', 'ip_address', 'device', 'device_hash', 'details', 'created_at'])]
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['details' => 'encrypted', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
