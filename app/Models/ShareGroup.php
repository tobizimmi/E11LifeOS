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
 * such as trips, pages or cost splits can be shared with. Joining works with an invite link.
 *
 * @property int $id
 * @property string $name
 * @property ?string $icon
 * @property ?int $created_by
 * @property ?string $invite_token
 * @property ?Carbon $invite_expires_at
 * @property-read SupportCollection<int, User> $users
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
        $query->whereHas('members', fn (Builder $members) => $members->where('user_id', $user->id));
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    public function isOwner(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->where('role', self::ROLE_OWNER)->exists();
    }

    /**
     * Takes a member out of the group together with everything they shared in it. When the last
     * owner leaves, the longest member becomes owner; an empty group is deleted.
     */
    public function removeMember(User $user): void
    {
        $this->shares()->where('shared_by', $user->id)->delete();
        $this->members()->where('user_id', $user->id)->delete();

        if (! $this->members()->exists()) {
            $this->delete();

            return;
        }

        if (! $this->members()->where('role', self::ROLE_OWNER)->exists()) {
            $this->members()->orderBy('id')->first()?->update(['role' => self::ROLE_OWNER]);
        }
    }

    public function hasValidInvite(): bool
    {
        return $this->invite_token !== null && ($this->invite_expires_at === null || $this->invite_expires_at->isFuture());
    }
}
