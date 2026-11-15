<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\HouseholdMember;
use App\Support\Addons;
use Illuminate\Http\JsonResponse;

/**
 * The household for the app: members, which modules are switched on (for everyone and per member)
 * and invite codes. Only the owner changes things.
 */
class HouseholdController extends ApiController
{
    public function show(): JsonResponse
    {
        return $this->ok($this->data($this->household()));
    }

    public function invite(): JsonResponse
    {
        $household = $this->household();
        $this->owner($household);
        $invite = HouseholdInvite::create(['household_id' => $household->id, 'code' => HouseholdInvite::generateCode(), 'created_by' => $this->user()->id]);

        return $this->ok(['code' => $invite->code, 'link' => route('register', ['code' => $invite->code])], 201);
    }

    public function toggleAddon(string $addon): JsonResponse
    {
        $household = $this->household();
        abort_unless(array_key_exists($addon, Addons::all()), 404);
        $this->owner($household);
        $household->setAddon($addon, ! Addons::switchedOn($household, $addon));

        return $this->ok($this->data($household->refresh()));
    }

    public function toggleMemberAddon(int $member, string $addon): JsonResponse
    {
        $household = $this->household();
        abort_unless(array_key_exists($addon, Addons::all()), 404);
        $this->owner($household);
        $target = $this->member($household, $member);
        $permissions = $target->permissions ?? [];

        if ($target->mayUse($addon)) {
            $permissions[$addon] = false;
        } else {
            unset($permissions[$addon]);
        }

        $target->update(['permissions' => $permissions ?: null]);

        return $this->ok($this->data($household->refresh()));
    }

    public function toggleMemberControl(int $member): JsonResponse
    {
        $household = $this->household();
        $this->owner($household);
        $target = $this->member($household, $member);
        $permissions = $target->permissions ?? [];

        if (($permissions['smart_home_control'] ?? true) !== false) {
            $permissions['smart_home_control'] = false;
        } else {
            unset($permissions['smart_home_control']);
        }

        $target->update(['permissions' => $permissions ?: null]);

        return $this->ok($this->data($household->refresh()));
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function owner(Household $household): void
    {
        abort_unless($household->members()->where('user_id', $this->user()->id)->first()?->isOwner(), 403, __('Only the owner can change this.'));
    }

    private function member(Household $household, int $id): HouseholdMember
    {
        $member = $household->members()->whereKey($id)->firstOrFail();
        abort_if($member->isOwner(), 422, __('The owner always uses everything.'));

        return $member;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Household $household): array
    {
        $members = $household->members()->with('user')->orderBy('role')->get();
        $isOwner = (bool) $members->first(fn (HouseholdMember $m) => $m->user_id === $this->user()->id)?->isOwner();
        $addons = collect(Addons::all())->map(fn (array $addon, string $key) => ['key' => $key, 'label' => $addon['label'], 'icon' => $addon['icon'], 'on' => $household->hasAddon($key)])->values();

        return [
            'name' => $household->name,
            'is_owner' => $isOwner,
            'addons' => $addons->all(),
            'members' => $members->map(fn (HouseholdMember $m) => [
                'id' => $m->id,
                'name' => $m->user?->name,
                'owner' => $m->isOwner(),
                'me' => $m->user_id === $this->user()->id,
                'modules' => $addons->where('on', true)->map(fn (array $a) => ['key' => $a['key'], 'label' => $a['label'], 'icon' => $a['icon'], 'allowed' => $m->mayUse($a['key'])])->values()->all(),
                'smart_home_control' => ($m->permissions['smart_home_control'] ?? true) !== false,
            ])->all(),
            'invites' => $isOwner ? HouseholdInvite::where('household_id', $household->id)->latest()->limit(10)->get()->map(fn (HouseholdInvite $i) => ['code' => $i->code, 'valid' => $i->isValid(), 'used' => $i->used_at !== null])->all() : [],
        ];
    }
}
