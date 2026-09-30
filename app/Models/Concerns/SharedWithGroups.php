<?php

namespace App\Models\Concerns;

use App\Models\Item;
use App\Models\Share;
use App\Models\ShareGroup;
use App\Models\ShareGroupMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Contents that can be shared with groups beyond the household. Who sees a content:
 * the creator, the household (unless private) and the members of the groups it is shared with.
 */
trait SharedWithGroups
{
    public static function bootSharedWithGroups(): void
    {
        static::deleting(function (self $model): void {
            $model->shares()->delete();
        });
    }

    /**
     * @return MorphMany<Share, $this>
     */
    public function shares(): MorphMany
    {
        return $this->morphMany(Share::class, 'shareable');
    }

    /**
     * @return MorphToMany<ShareGroup, $this>
     */
    public function shareGroups(): MorphToMany
    {
        return $this->morphToMany(ShareGroup::class, 'shareable', 'shares')->withTimestamps();
    }

    /**
     * Contents shared with a group the user is a member of.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSharedWith(Builder $query, User $user): void
    {
        $query->whereIn($this->getQualifiedKeyName(), static::sharedIdsFor($user));
    }

    /**
     * Ids of the contents of this kind shared with the user's groups, as a subquery.
     *
     * @return Builder<Share>
     */
    public static function sharedIdsFor(User $user): Builder
    {
        return Share::query()
            ->select('shareable_id')
            ->where('shareable_type', (new self)->getMorphClass())
            ->whereIn('share_group_id', ShareGroupMember::query()->select('share_group_id')->where('user_id', $user->id));
    }

    public function isSharedWith(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->sharedWith($user)->exists();
    }

    /**
     * Only the creator decides with whom a content is shared.
     */
    public function canManageSharing(User $user): bool
    {
        return (int) $this->created_by === $user->id;
    }

    /**
     * Who can see the content, for the badge: private, household or groups (plus household).
     * For a viewer from another household it is "shared with you" by the creator.
     *
     * @return array{level: string, household: bool, groups: list<string>, sharer: ?string}
     */
    public function audience(?int $viewerHouseholdId = null): array
    {
        $groups = $this->shareGroups->pluck('name')->values()->all();
        $household = $this->visibility !== Item::VISIBILITY_PRIVATE;
        $foreign = $viewerHouseholdId !== null && (int) $this->household_id !== $viewerHouseholdId;

        return [
            'level' => $foreign ? 'foreign' : ($groups !== [] ? 'groups' : ($household ? 'household' : 'private')),
            'household' => $household,
            'groups' => $groups,
            'sharer' => $foreign ? $this->creator?->name : null,
        ];
    }
}
