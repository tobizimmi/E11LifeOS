<?php

namespace Tests\Concerns;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;

trait CreatesHouseholdMembers
{
    private function memberWithHousehold(string $role = 'owner'): array
    {
        $household = Household::create(['name' => 'Familie Muster']);
        $user = User::factory()->create();

        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        return [$user, $household];
    }

    private function addMemberTo(Household $household, string $role = 'member'): User
    {
        $user = User::factory()->create();

        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        return $user;
    }
}
