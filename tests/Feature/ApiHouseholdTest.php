<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Addons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API: household administration.
 */
class ApiHouseholdTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_the_owner_manages_modules_members_and_invites(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $headers = $this->headersFor($owner);

        $data = $this->getJson('/api/v1/household', $headers)->assertOk()->json('data');
        $this->assertTrue($data['is_owner']);
        $this->assertCount(2, $data['members']);

        $before = Addons::switchedOn($household, Addons::RECIPES);
        $this->postJson('/api/v1/household/addons/'.Addons::RECIPES.'/toggle', [], $headers)->assertOk();
        $this->assertNotSame($before, Addons::switchedOn($household->fresh(), Addons::RECIPES));
        $this->postJson('/api/v1/household/addons/nope/toggle', [], $headers)->assertNotFound();

        $member = $household->members()->where('user_id', $partner->id)->first();
        $this->postJson('/api/v1/household/addons/'.Addons::RECIPES.'/toggle', [], $headers)->assertOk();
        $this->postJson("/api/v1/household/members/{$member->id}/addons/".Addons::RECIPES.'/toggle', [], $headers)->assertOk();
        $this->assertFalse($member->fresh()->mayUse(Addons::RECIPES));
        $this->postJson("/api/v1/household/members/{$member->id}/smart-home-control/toggle", [], $headers)->assertOk();
        $ownerMember = $household->members()->where('user_id', $owner->id)->first();
        $this->postJson("/api/v1/household/members/{$ownerMember->id}/addons/".Addons::RECIPES.'/toggle', [], $headers)->assertStatus(422);

        $invite = $this->postJson('/api/v1/household/invite', [], $headers)->assertCreated()->json('data');
        $this->assertStringContainsString($invite['code'], $invite['link']);
    }

    public function test_members_may_look_but_not_change(): void
    {
        [, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $headers = $this->headersFor($partner);

        $data = $this->getJson('/api/v1/household', $headers)->assertOk()->json('data');
        $this->assertFalse($data['is_owner']);
        $this->assertSame([], $data['invites']);
        $this->postJson('/api/v1/household/invite', [], $headers)->assertForbidden();
        $this->postJson('/api/v1/household/addons/'.Addons::RECIPES.'/toggle', [], $headers)->assertForbidden();
    }
}
