<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Models\Passkey;
use Tests\TestCase;

class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_options_require_user_verification_and_the_second_step_only_offers_own_passkeys(): void
    {
        $options = $this->getJson(route('passkeys.options'))->assertOk()->json();

        $this->assertSame('required', $options['userVerification']);
        $this->assertGreaterThanOrEqual(43, strlen($options['challenge']));
        $this->assertArrayNotHasKey('allowCredentials', array_filter($options));

        $user = User::factory()->create();
        Passkey::factory()->create(['authenticatable_id' => $user->id]);
        Passkey::factory()->create();

        $options = $this->withSession(['login.id' => $user->id, 'login.expires' => now()->addMinutes(5)->timestamp])
            ->getJson(route('passkeys.options'))->json();
        $this->assertCount(1, $options['allowCredentials']);
    }

    public function test_a_verified_passkey_signs_in_without_password(): void
    {
        $passkey = Passkey::factory()->create();
        $this->mock(FindPasskeyToAuthenticateAction::class, fn (MockInterface $mock) => $mock->shouldReceive('execute')->once()->andReturn($passkey));

        $this->withSession(['passkey-authentication-options' => '{}'])
            ->postJson(route('passkeys.login'), ['credential' => '{}'])
            ->assertOk()->assertJsonPath('redirect', url('/dashboard'));

        $this->assertAuthenticatedAs($passkey->authenticatable);
        $this->assertDatabaseHas('security_events', ['user_id' => $passkey->authenticatable_id, 'event' => 'login_passkey']);
    }

    public function test_an_unknown_passkey_or_another_members_passkey_is_rejected(): void
    {
        $this->mock(FindPasskeyToAuthenticateAction::class, fn (MockInterface $mock) => $mock->shouldReceive('execute')->andReturn(null));
        $this->withSession(['passkey-authentication-options' => '{}'])->postJson(route('passkeys.login'), ['credential' => '{}'])
            ->assertStatus(422)->assertJsonPath('message', 'Dieser Passkey konnte nicht geprüft werden. Bitte erneut versuchen oder mit Passwort anmelden.');
        $this->assertGuest();

        $pending = User::factory()->create();
        $foreign = Passkey::factory()->create();
        $this->mock(FindPasskeyToAuthenticateAction::class, fn (MockInterface $mock) => $mock->shouldReceive('execute')->andReturn($foreign));
        $this->withSession(['passkey-authentication-options' => '{}', 'login.id' => $pending->id, 'login.expires' => now()->addMinutes(5)->timestamp])
            ->postJson(route('passkeys.login'), ['credential' => '{}'])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_with_a_passkey_the_password_alone_is_not_enough(): void
    {
        $user = User::factory()->create();
        Passkey::factory()->create(['authenticatable_id' => $user->id]);

        Volt::test('pages.auth.login')->set('form.email', $user->email)->set('form.password', 'password')->call('login')
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        $this->withSession(['login.id' => $user->id, 'login.expires' => now()->addMinutes(5)->timestamp])
            ->get(route('two-factor.challenge'))->assertOk()->assertSee('Mit Passkey bestätigen')->assertSee('Bestätige die Anmeldung mit deinem Passkey.');
    }

    public function test_passkeys_are_added_with_the_password_and_removed_in_the_profile(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('profile.passkeys-form')->set('name', 'iPhone')->set('password', 'falsch')->call('prepare')->assertHasErrors(['password']);

        $options = Volt::test('profile.passkeys-form')->set('name', 'iPhone')->set('password', 'password')->call('prepare')->assertHasNoErrors()->effects['returns'][0];
        $this->assertSame('required', json_decode($options, true)['authenticatorSelection']['userVerification']);

        $this->mock(StorePasskeyAction::class, fn (MockInterface $mock) => $mock->shouldReceive('execute')->once()
            ->andReturnUsing(fn ($authenticatable) => Passkey::factory()->create(['authenticatable_id' => $authenticatable->id, 'name' => 'iPhone'])));
        Volt::test('profile.passkeys-form')->set('name', 'iPhone')->call('store', '{}')->assertHasNoErrors()->assertSee('iPhone');
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => 'passkey_added']);

        Volt::test('profile.passkeys-form')->call('remove', $user->passkeys()->sole()->id);
        $this->assertSame(0, $user->passkeys()->count());
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => 'passkey_removed']);
    }
}
