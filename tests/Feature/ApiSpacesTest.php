<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for spaces (workspaces for pages and tasks).
 */
class ApiSpacesTest extends TestCase
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

    public function test_spaces_hold_tasks(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/spaces', ['name' => 'Verein', 'icon' => '⚽'], $headers)->assertCreated()->assertJsonPath('data.name', 'Verein')->json('data.id');
        $this->postJson('/api/v1/spaces', ['name' => 'X', 'icon' => '🙈'], $headers)->assertStatus(422);

        $this->postJson("/api/v1/spaces/{$id}/tasks", ['text' => 'Trikots waschen'], $headers)->assertCreated();
        $other = $this->postJson('/api/v1/tasks', ['text' => 'Einzeln'], $headers)->json('data.id');
        $this->putJson("/api/v1/tasks/{$other}/space", ['space' => $id], $headers)->assertOk()->assertJsonPath('data.space', $id);

        $this->getJson('/api/v1/spaces', $headers)->assertOk()->assertJsonPath('data.spaces.0.tasks', 2);
        $this->getJson("/api/v1/spaces/{$id}", $headers)->assertOk()->assertJsonCount(2, 'data.tasks');
        $this->getJson("/api/v1/tasks/{$other}", $headers)->assertJsonPath('data.space', $id)->assertJsonPath('data.options.spaces.0.id', $id);

        $this->putJson("/api/v1/spaces/{$id}", ['name' => 'Sportverein', 'private' => true], $headers)->assertOk()->assertJsonPath('data.private', true);
        $this->putJson("/api/v1/tasks/{$other}/space", ['space' => null], $headers)->assertOk();
        $this->deleteJson("/api/v1/spaces/{$id}", [], $headers)->assertOk();
        $this->getJson('/api/v1/spaces', $headers)->assertJsonCount(0, 'data.spaces');
    }

    public function test_private_spaces_are_hidden_from_others(): void
    {
        [$user] = $this->memberWithHousehold();
        $stranger = $this->memberWithHousehold()[0];
        $id = $this->postJson('/api/v1/spaces', ['name' => 'Privat', 'private' => true], $this->headersFor($user))->json('data.id');

        $this->getJson("/api/v1/spaces/{$id}", $this->headersFor($stranger))->assertNotFound();
    }
}
