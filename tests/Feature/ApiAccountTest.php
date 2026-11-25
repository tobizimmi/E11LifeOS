<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Account settings and the start page cards for the app.
 */
class ApiAccountTest extends TestCase
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

    public function test_the_overview_never_exposes_secrets_and_preferences_can_be_saved(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $response = $this->getJson('/api/v1/account', $headers)->assertOk()
            ->assertJsonPath('data.profile.email', $user->email)
            ->assertJsonPath('data.devices.0.current', true)
            ->assertJsonPath('data.two_factor.enabled', false);
        $this->assertStringNotContainsString($user->password, (string) $response->getContent());
        $this->assertStringNotContainsString(ApiToken::first()->token, (string) $response->getContent());

        $this->putJson('/api/v1/account/preferences', ['reminder_emails' => true, 'digest' => ['channel' => 'email', 'day' => 5, 'hour' => 9], 'briefing' => ['channel' => 'push', 'hour' => 6]], $headers)->assertOk();
        $user->refresh();
        $this->assertSame([true, 'email', 5, 9, 'push', 6], [$user->reminder_emails, $user->weekly_digest, $user->weekly_digest_day, $user->weekly_digest_hour, $user->morning_briefing, $user->morning_briefing_hour]);
        $this->putJson('/api/v1/account/preferences', ['digest' => ['channel' => 'fax']], $headers)->assertStatus(422);
        $this->getJson('/api/v1/account/preview/digest', $headers)->assertOk()->assertJsonStructure(['data' => ['title', 'sections']]);

        $this->putJson('/api/v1/account/calendar-feed', ['action' => 'create', 'details' => false], $headers)->assertOk();
        $this->assertNotNull($user->fresh()->calendar_token);
        $this->putJson('/api/v1/account/calendar-feed', ['action' => 'disable'], $headers)->assertJsonPath('data.url', null);
    }

    public function test_password_and_email_changes_need_the_current_password(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->putJson('/api/v1/account/profile', ['name' => 'Neu', 'email' => $user->email], $headers)->assertOk();
        $this->putJson('/api/v1/account/profile', ['name' => 'Neu', 'email' => 'neu@example.org'], $headers)->assertStatus(422);
        $this->putJson('/api/v1/account/profile', ['name' => 'Neu', 'email' => 'neu@example.org', 'password' => 'password'], $headers)->assertOk();

        $this->putJson('/api/v1/account/password', ['current_password' => 'falsch', 'new_password' => 'Sehr-geheim-123!', 'new_password_confirmation' => 'Sehr-geheim-123!'], $headers)->assertStatus(422);
        $this->putJson('/api/v1/account/password', ['current_password' => 'password', 'new_password' => 'Sehr-geheim-123!', 'new_password_confirmation' => 'Sehr-geheim-123!'], $headers)->assertOk();
        $this->assertTrue(Hash::check('Sehr-geheim-123!', $user->fresh()->password));
    }

    public function test_two_factor_setup_devices_and_deleting_the_account(): void
    {
        [$user] = $this->memberWithHousehold();
        $other = $this->headersFor($user);
        $headers = $this->headersFor($user);

        $this->postJson('/api/v1/account/two-factor', [], $headers)->assertStatus(422);
        $secret = $this->postJson('/api/v1/account/two-factor', ['password' => 'password'], $headers)->assertOk()->json('data.secret');
        $this->postJson('/api/v1/account/two-factor/confirm', ['code' => '000000x'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/account/two-factor/confirm', ['code' => TwoFactor::code($secret, intdiv(time(), 30))], $headers)->assertOk()->assertJsonStructure(['data' => ['recovery_codes']]);
        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->postJson('/api/v1/account/two-factor/disable', ['password' => 'password'], $headers)->assertOk();
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $this->postJson('/api/v1/account/logout-others', ['password' => 'password'], $headers)->assertOk();
        $this->assertSame(1, ApiToken::where('user_id', $user->id)->count());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/account', $other)->assertUnauthorized();

        $this->postJson('/api/v1/account/delete', ['password' => 'falsch'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/account/delete', ['password' => 'password'], $headers)->assertOk();
        $this->assertNull(User::find($user->id));
    }

    public function test_foreign_devices_cannot_be_removed(): void
    {
        [$user] = $this->memberWithHousehold();
        [$stranger] = $this->memberWithHousehold();
        $this->headersFor($user);
        $device = ApiToken::where('user_id', $user->id)->firstOrFail();

        $this->deleteJson("/api/v1/account/devices/{$device->id}", [], $this->headersFor($stranger))->assertNotFound();
        $this->assertNotNull($device->fresh());
    }

    public function test_the_start_page_cards_can_be_chosen(): void
    {
        Http::fake();
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/dashboard', $headers)->assertOk()->assertJsonStructure(['data' => ['widgets' => [['key', 'label', 'visible']], 'cards']]);
        $this->putJson('/api/v1/dashboard', ['widgets' => ['inbox', 'pages']], $headers)->assertOk();
        $this->getJson('/api/v1/dashboard', $headers)->assertOk()
            ->assertJsonPath('data.cards.0.key', 'inbox')
            ->assertJsonPath('data.cards.1.key', 'pages')
            ->assertJsonCount(2, 'data.cards');
        $this->putJson('/api/v1/dashboard', ['widgets' => ['unsinn']], $headers)->assertStatus(422);
    }
}
