<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class HouseholdShowTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_household_page_renders_with_members(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        $this->actingAs($user)
            ->get('/household')
            ->assertOk()
            ->assertSee($household->name)
            ->assertSee($user->name);
    }

    public function test_member_can_generate_an_invite_code(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        $this->actingAs($user);

        $component = Volt::test('household.show')
            ->call('generateInvite');

        $this->assertDatabaseHas('household_invites', [
            'household_id' => $household->id,
            'created_by' => $user->id,
        ]);

        $this->assertNotNull($component->get('newInviteCode'));
    }

    public function test_guests_cannot_view_the_household_page(): void
    {
        $this->get('/household')->assertRedirect('/login');
    }
}
