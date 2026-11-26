<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class ApiShortcutTokenTest extends TestCase
{
    use CreatesHouseholdMembers;
    use RefreshDatabase;

    public function test_a_shortcut_token_adds_things_the_old_and_the_new_way(): void
    {
        [$user] = $this->memberWithHousehold();
        $token = ApiToken::issue($user, 'Siri / Kurzbefehl', ApiToken::SCOPE_QUICK);
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/v1/quick/auto?token='.$token.'&text=Brot')->assertCreated();
        $this->postJson('/api/v1/shopping/quick', ['text' => 'Butter'], $headers)->assertCreated();
        $this->postJson('/api/v1/tasks', ['text' => 'Müll rausbringen'], $headers)->assertCreated();
        $this->postJson('/api/v1/capture', ['text' => 'Idee notieren'], $headers)->assertCreated();
    }

    public function test_a_shortcut_token_cannot_read_change_or_delete(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.ApiToken::issue($user, 'Siri / Kurzbefehl', ApiToken::SCOPE_QUICK)];

        foreach (['/api/v1/auth/me', '/api/v1/account', '/api/v1/dashboard', '/api/v1/tasks', '/api/v1/shopping', '/api/v1/mail/accounts'] as $url) {
            $this->getJson($url, $headers)->assertForbidden();
        }

        $this->putJson('/api/v1/account/preferences', [], $headers)->assertForbidden();
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $headers)->assertForbidden();
        $this->deleteJson('/api/v1/account/devices/'.ApiToken::where('user_id', $user->id)->value('id'), [], $headers)->assertForbidden();
        $this->assertSame(1, ApiToken::where('user_id', $user->id)->count());
    }

    public function test_the_app_and_the_web_page_issue_limited_shortcut_tokens(): void
    {
        [$user] = $this->memberWithHousehold();

        $this->postJson('/api/v1/account/siri-token', ['password' => 'password'], ['Authorization' => 'Bearer '.ApiToken::issue($user, 'App')])->assertCreated();
        $this->actingAs($user);
        Volt::test('profile.app-devices-form')->call('createSiriToken');

        $this->assertSame(2, ApiToken::where('user_id', $user->id)->where('scope', ApiToken::SCOPE_QUICK)->count());
    }

    public function test_the_migration_limits_shortcut_tokens_issued_before(): void
    {
        $user = User::factory()->create();
        $migration = require database_path('migrations/2026_11_26_090000_add_scope_to_api_tokens_table.php');
        $migration->down();
        foreach (['Siri / Kurzbefehl', 'Siri / Shortcut', 'iPhone von Alex'] as $name) {
            ApiToken::query()->insert(['user_id' => $user->id, 'name' => $name, 'token' => hash('sha256', $name), 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration->up();

        $this->assertSame(['Siri / Kurzbefehl', 'Siri / Shortcut'], ApiToken::where('scope', ApiToken::SCOPE_QUICK)->orderBy('name')->pluck('name')->all());
        $this->assertNull(ApiToken::where('name', 'iPhone von Alex')->value('scope'));
    }
}
