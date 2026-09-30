<?php

namespace App\Models;

use App\Models\Concerns\HasShareTokens;
use Database\Factories\ShareGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * A group of people from any household (friends, family elsewhere, a club) that single contents
 * such as trips, pages or cost splits can be shared with. Joining works with an invite link, for a
 * single person or a whole household (then everyone in it, now and later, is a member).
 *
 * @property int $id
 * @property string $name
 * @property ?string $icon
 * @property ?int $created_by
 * @property ?string $invite_token
 * @property ?Carbon $invite_expires_at
 * @property-read SupportCollection<int, User> $users
 * @property-read SupportCollection<int, Household> $households
 */
#[Fillable(['name', 'icon', 'created_by', 'invite_token', 'invite_expires_at'])]
#[Hidden(['invite_token', 'invite_token_encrypted'])]
class ShareGroup extends Model
{
    /** @use HasFactory<ShareGroupFactory> */
    use HasFactory, HasShareTokens;

    public const ROLE_OWNER = 'owner';

    public const ROLE_MEMBER = 'member';

    protected function casts(): array
    {
        return [
            'invite_expires_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function shareTokenAttributes(): array
    {
        return ['invite_token'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ShareGroupMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ShareGroupMember::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'share_group_members')->withPivot('role')->withTimestamps();
    }

    /**
     * @return BelongsToMany<Household, $this>
     */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'share_group_households')->withPivot('added_by')->withTimestamps();
    }

    /**
     * @return HasMany<Share, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(Share::class);
    }

    /**
     * @param  Builder<ShareGroup>  $query
     */
    public function scopeOfMember(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereHas('members', fn (Builder $members) => $members->where('user_id', $user->id))
            ->orWhereHas('households', fn (Builder $households) => $households->whereIn('households.id', HouseholdMember::query()->select('household_id')->where('user_id', $user->id))));
    }

    /**
     * Member in person or through their household.
     */
    public function hasMember(User $user): bool
    {
        return static::query()->whereKey($this->id)->ofMember($user)->exists();
    }

    public function isPersonalMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    /**
     * Takes a household out of the group; what its people shared there ends unless they are still
     * members in person.
     */
    public function removeHousehold(Household $household): void
    {
        $this->households()->detach($household->id);
        $this->pruneShares();
        $this->deleteIfEmpty();
    }

    /**
     * Shares of people who are no longer members end.
     */
    private function pruneShares(): void
    {
        foreach ($this->shares()->with('sharer')->get() as $share) {
            if ($share->sharer === null || ! $this->hasMember($share->sharer)) {
                $share->delete();
            }
        }
    }

    private function deleteIfEmpty(): bool
    {
        if ($this->members()->exists() || $this->households()->exists()) {
            return false;
        }

        $this->delete();

        return true;
    }

    public function isOwner(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->where('role', self::ROLE_OWNER)->exists();
    }

    /**
     * Takes a member out of the group together with everything they shared in it (unless they stay
     * a member through their household). When the last owner leaves, the longest member becomes
     * owner; an empty group is deleted.
     */
    public function removeMember(User $user): void
    {
        $this->members()->where('user_id', $user->id)->delete();
        $this->pruneShares();

        if ($this->deleteIfEmpty() || $this->members()->where('role', self::ROLE_OWNER)->exists()) {
            return;
        }

        $next = $this->members()->orderBy('id')->first();

        if ($next) {
            $next->update(['role' => self::ROLE_OWNER]);

            return;
        }

        // Only households are left: the owner of the first one takes over.
        $ownerId = HouseholdMember::query()->whereIn('household_id', $this->households()->pluck('households.id'))->where('role', 'owner')->orderBy('id')->value('user_id');

        if ($ownerId) {
            $this->members()->create(['user_id' => $ownerId, 'role' => self::ROLE_OWNER]);
        }
    }

    public function hasValidInvite(): bool
    {
        return $this->invite_token !== null && ($this->invite_expires_at === null || $this->invite_expires_at->isFuture());
    }
}
