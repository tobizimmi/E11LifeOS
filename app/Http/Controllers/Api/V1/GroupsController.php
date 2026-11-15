<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Share;
use App\Models\ShareGroup;
use App\Support\Sharing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mobile API for groups beyond the household (friends, family elsewhere, a club) and the overview of
 * what is shared with whom.
 */
class GroupsController extends ApiController
{
    public function index(): JsonResponse
    {
        $user = $this->user();
        $groups = ShareGroup::query()->ofMember($user)->with(['members.user', 'households'])->orderBy('name')->get();
        $shares = Share::query()->whereIn('share_group_id', $groups->pluck('id'))->with(['shareable', 'group', 'sharer'])->latest()->get();
        $existing = collect(Sharing::existing($shares));
        $describe = fn (Share $share) => ['id' => $share->id, 'title' => Sharing::titleOf($share->shareable), 'group' => $share->group->name, 'by' => $share->sharer->name] + Sharing::describe($share->shareable);

        return $this->ok([
            'groups' => array_map(fn (ShareGroup $group) => $this->groupData($group), $groups->all()),
            'my_shares' => $existing->where('shared_by', $user->id)->map($describe)->values()->all(),
            'shared_with_me' => $existing->where('shared_by', '!=', $user->id)->map($describe)->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'icon' => ['nullable', 'string', 'max:16']]);
        $group = DB::transaction(function () use ($data) {
            $group = ShareGroup::create(['name' => $data['name'], 'icon' => ($data['icon'] ?? null) ?: '👥', 'created_by' => $this->user()->id]);
            $group->members()->create(['user_id' => $this->user()->id, 'role' => ShareGroup::ROLE_OWNER]);

            return $group;
        });

        return $this->ok(['id' => $group->id], 201);
    }

    public function createInvite(int $group): JsonResponse
    {
        $item = $this->owned($group);
        $item->update(['invite_token' => Str::random(40), 'invite_expires_at' => now()->addDays(7)]);

        return $this->ok(['url' => route('groups.join', $item->invite_token), 'until' => $item->invite_expires_at?->toDateString()], 201);
    }

    public function revokeInvite(int $group): JsonResponse
    {
        $this->owned($group)->update(['invite_token' => null, 'invite_expires_at' => null]);

        return $this->ok();
    }

    public function removeMember(int $group, int $member): JsonResponse
    {
        $item = $this->owned($group);
        abort_if($member === $this->user()->id, 422);
        $row = $item->members()->where('user_id', $member)->firstOrFail();
        $item->removeMember($row->user);

        return $this->ok();
    }

    public function leave(int $group): JsonResponse
    {
        $this->member($group)->removeMember($this->user());

        return $this->ok();
    }

    public function destroy(int $group): JsonResponse
    {
        $this->owned($group)->delete();

        return $this->ok();
    }

    public function unshare(int $share): JsonResponse
    {
        Share::query()->where('shared_by', $this->user()->id)->findOrFail($share)->delete();

        return $this->ok();
    }

    /**
     * @return array<string, mixed>
     */
    private function groupData(ShareGroup $group): array
    {
        $owner = $group->isOwner($this->user());
        $members = [];

        foreach ($group->members as $member) {
            $members[] = ['user_id' => $member->user_id, 'name' => $member->user->name, 'owner' => $member->role === ShareGroup::ROLE_OWNER];
        }

        $households = [];

        foreach ($group->households as $household) {
            $households[] = ['id' => $household->id, 'name' => $household->name];
        }

        return [
            'id' => $group->id,
            'name' => $group->name,
            'icon' => $group->icon ?: '👥',
            'owner' => $owner,
            'invite' => $owner && $group->invite_token !== null ? ['url' => route('groups.join', $group->invite_token), 'until' => $group->invite_expires_at?->toDateString()] : null,
            'members' => $members,
            'households' => $households,
        ];
    }

    private function member(int $id): ShareGroup
    {
        return ShareGroup::query()->ofMember($this->user())->findOrFail($id);
    }

    private function owned(int $id): ShareGroup
    {
        $group = $this->member($id);
        abort_unless($group->isOwner($this->user()), 403);

        return $group;
    }
}
