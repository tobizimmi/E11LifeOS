<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\ShareGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Joining a group with its invite link (alone or, as household owner, with the whole household) and
 * taking a household out of a group.
 */
class GroupJoinController extends ApiController
{
    public function show(string $token): JsonResponse
    {
        $group = $this->group($token)->load('members.user', 'households');
        $household = $this->joinableHousehold($group);

        return $this->ok([
            'name' => $group->name,
            'icon' => $group->icon ?: '👥',
            'members' => $group->members->map(fn ($member): string => $member->user->name)->values()->all(),
            'households' => $group->households->pluck('name')->values()->all(),
            'is_member' => $group->hasMember($this->user()),
            'household' => $household ? ['name' => $household->name, 'size' => HouseholdMember::where('household_id', $household->id)->count()] : null,
        ]);
    }

    public function join(Request $request, string $token): JsonResponse
    {
        $group = $this->group($token);
        $data = $request->validate(['with_household' => ['sometimes', 'boolean']]);

        if ($data['with_household'] ?? false) {
            $household = $this->joinableHousehold($group) ?? abort(403);
            $group->households()->attach($household->id, ['added_by' => $this->user()->id]);
        } else {
            $group->members()->firstOrCreate(['user_id' => $this->user()->id], ['role' => ShareGroup::ROLE_MEMBER]);
        }

        return $this->ok(['id' => $group->id, 'name' => $group->name]);
    }

    public function removeHousehold(int $group, int $household): JsonResponse
    {
        $item = ShareGroup::query()->ofMember($this->user())->findOrFail($group);
        $target = $item->households()->whereKey($household)->firstOrFail();
        $owns = HouseholdMember::query()->where('household_id', $target->id)->where('user_id', $this->user()->id)->where('role', 'owner')->exists();
        abort_unless($item->isOwner($this->user()) || $owns, 403);
        $item->removeHousehold($target);

        return $this->ok();
    }

    private function group(string $token): ShareGroup
    {
        return ShareGroup::query()->whereShareToken('invite_token', $token, 'invite_expires_at')->first() ?? abort(404);
    }

    /**
     * The own household, when the user owns it and it is not in the group yet.
     */
    private function joinableHousehold(ShareGroup $group): ?Household
    {
        $household = $this->user()->currentHousehold();
        $isOwner = $household && HouseholdMember::query()->where('household_id', $household->id)->where('user_id', $this->user()->id)->where('role', 'owner')->exists();

        return $isOwner && ! $group->households()->whereKey($household->id)->exists() ? $household : null;
    }
}
