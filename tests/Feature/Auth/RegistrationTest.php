<?php

namespace Tests\Feature\Auth;

use App\Models\Household;
use App\Models\HouseholdInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.register');
    }

    public function test_a_new_household_invite_lets_a_user_register_and_creates_a_household(): void
    {
        $invite = HouseholdInvite::create([
            'household_id' => null,
            'code' => HouseholdInvite::generateCode(),
        ]);

        $component = Volt::test('pages.auth.register')
            ->set('invite_code', $invite->code)
            ->set('household_name', 'Familie Test')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();

        $user = User::where('email', 'test@example.com')->firstOrFail();
        $household = Household::where('name', 'Familie Test')->firstOrFail();

        $this->assertTrue($user->households->contains($household));
        $this->assertSame('owner', $user->householdMemberships()->first()->role);
        $this->assertNotNull($invite->fresh()->used_at);
    }

    public function test_a_household_scoped_invite_joins_the_user_to_that_household(): void
    {
        $household = Household::create(['name' => 'Bestehender Haushalt']);
        $invite = HouseholdInvite::create([
            'household_id' => $household->id,
            'code' => HouseholdInvite::generateCode(),
        ]);

        $component = Volt::test('pages.auth.register')
            ->set('invite_code', $invite->code)
            ->set('name', 'Partner User')
            ->set('email', 'partner@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'partner@example.com')->firstOrFail();

        $this->assertTrue($user->households->contains($household));
        $this->assertSame('member', $user->householdMemberships()->first()->role);
    }

    public function test_registration_fails_with_invalid_invite_code(): void
    {
        $component = Volt::test('pages.auth.register')
            ->set('invite_code', 'DOES-NOT-EXIST')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertHasErrors('invite_code');

        $this->assertGuest();
    }

    public function test_registration_fails_with_an_already_used_invite_code(): void
    {
        $invite = HouseholdInvite::create([
            'household_id' => null,
            'code' => HouseholdInvite::generateCode(),
            'used_at' => now(),
        ]);

        $component = Volt::test('pages.auth.register')
            ->set('invite_code', $invite->code)
            ->set('household_name', 'Zweiter Versuch')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertHasErrors('invite_code');

        $this->assertGuest();
    }
}
