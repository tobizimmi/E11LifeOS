<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Bearer token of a signed-in device (e.g. the mobile app). Only the SHA-256 hash is stored.
 */
#[Fillable(['user_id', 'name', 'scope', 'token', 'last_used_at'])]
#[Hidden(['token'])]
class ApiToken extends Model
{
    /** A Siri shortcut or automation: it travels in an address, so it may only add things. */
    public const SCOPE_QUICK = 'quick';

    /** The signed-in routes older shortcuts post to; newer ones use "quick/{type}" with the token in the address. */
    private const QUICK_ROUTES = ['api.v1.shopping.quick', 'api.v1.tasks.store', 'api.v1.capture'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
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
     * Creates a token for the device and returns the plain text value (shown only once).
     */
    public static function issue(User $user, string $deviceName, ?string $scope = null): string
    {
        $plain = Str::random(64);

        self::create(['user_id' => $user->id, 'name' => Str::limit($deviceName, 100, ''), 'scope' => $scope, 'token' => hash('sha256', $plain)]);

        return $plain;
    }

    public static function findByPlain(string $plain): ?self
    {
        return self::with('user')->firstWhere('token', hash('sha256', $plain));
    }

    /**
     * Whether the token may call this signed-in API route.
     */
    public function allowsRoute(?string $route): bool
    {
        return $this->scope === null || ($this->scope === self::SCOPE_QUICK && in_array($route, self::QUICK_ROUTES, true));
    }

    /**
     * When the token stops working without use; null if tokens never expire.
     */
    public function expiresAt(): ?Carbon
    {
        $days = (int) config('security.api_token_idle_days');

        return $days > 0 ? ($this->last_used_at ?? $this->created_at)->copy()->addDays($days) : null;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt()?->isPast() ?? false;
    }

    /**
     * Deletes the tokens that were not used for the configured number of days; returns how many.
     */
    public static function pruneExpired(): int
    {
        $days = (int) config('security.api_token_idle_days');

        if ($days <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($days);

        return self::where(fn ($query) => $query->where('last_used_at', '<', $cutoff)->orWhere(fn ($query) => $query->whereNull('last_used_at')->where('created_at', '<', $cutoff)))->delete();
    }
}
