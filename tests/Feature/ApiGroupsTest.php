<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for groups beyond the household.
 */
class ApiGroupsTest extends TestCase
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

    public function test_group_with_invite_link(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/groups', ['name' => 'Freunde', 'icon' => '🎉'], $headers)->assertCreated()->json('data.id');
        $url = $this->postJson("/api/v1/groups/{$id}/invite", [], $headers)->assertCreated()->json('data.url');
        $this->assertStringContainsString('/groups/join/', $url);

        $this->getJson('/api/v1/groups', $headers)->assertOk()->assertJsonPath('data.groups.0.name', 'Freunde')->assertJsonPath('data.groups.0.owner', true)->assertJsonPath('data.groups.0.invite.url', $url)->assertJsonCount(1, 'data.groups.0.members');
        $this->deleteJson("/api/v1/groups/{$id}/invite", [], $headers)->assertOk();
        $this->getJson('/api/v1/groups', $headers)->assertJsonPath('data.groups.0.invite', null);
        $this->deleteJson("/api/v1/groups/{$id}", [], $headers)->assertOk();
        $this->getJson('/api/v1/groups', $headers)->assertJsonCount(0, 'data.groups');
    }

    public function test_only_members_see_a_group_and_only_owners_change_it(): void
    {
        [$user] = $this->memberWithHousehold();
        $stranger = $this->memberWithHousehold()[0];
        $id = $this->postJson('/api/v1/groups', ['name' => 'Geheim'], $this->headersFor($user))->json('data.id');

        $this->getJson('/api/v1/groups', $this->headersFor($stranger))->assertJsonCount(0, 'data.groups');
        $this->deleteJson("/api/v1/groups/{$id}", [], $this->headersFor($stranger))->assertNotFound();
    }
}
