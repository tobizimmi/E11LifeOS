<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Bearer token of a signed-in device (e.g. the mobile app). Only the SHA-256 hash is stored.
 */
#[Fillable(['user_id', 'name', 'token', 'last_used_at'])]
#[Hidden(['token'])]
class ApiToken extends Model
{
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Creates a token for the device and returns the plain text value (shown only once).
     */
    public static function issue(User $user, string $deviceName): string
    {
        $plain = Str::random(64);

        self::create(['user_id' => $user->id, 'name' => Str::limit($deviceName, 100, ''), 'token' => hash('sha256', $plain)]);

        return $plain;
    }

    public static function findByPlain(string $plain): ?self
    {
        return self::with('user')->firstWhere('token', hash('sha256', $plain));
    }
}
