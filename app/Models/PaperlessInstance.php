<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A connection to a paperless-ngx instance. It belongs to the member who added it and is either
 * personal or shared with the household – then everyone in the household works with its token.
 */
#[Fillable(['household_id', 'user_id', 'name', 'url', 'token', 'shared'])]
#[Hidden(['token'])]
class PaperlessInstance extends Model
{
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'shared' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user's own instances plus those shared in their current household.
     *
     * @param  Builder<PaperlessInstance>  $query
     */
    public function scopeAvailableTo(Builder $query, User $user): void
    {
        $householdId = $user->currentHousehold()?->id;

        $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('user_id', $user->id)->where('household_id', $householdId))
            ->orWhere(fn (Builder $query) => $query->where('household_id', $householdId)->where('shared', true)));
    }

    /**
     * The instance used when nothing else is chosen: an own one first, then a shared one.
     */
    public static function defaultFor(User $user): ?self
    {
        return self::query()->availableTo($user)->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])->orderBy('id')->first();
    }

    public function isAvailableTo(User $user): bool
    {
        return self::query()->availableTo($user)->whereKey($this->id)->exists();
    }

    public function baseUrl(): string
    {
        return rtrim($this->url, '/');
    }
}
