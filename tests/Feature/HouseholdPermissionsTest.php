<?php

namespace Tests\Feature;

use App\Models\HouseholdMember;
use App\Support\Addons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class HouseholdPermissionsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_the_owner_switches_modules_off_for_single_members(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $membership = HouseholdMember::where('user_id', $partner->id)->sole();

        $this->actingAs($owner);
        Volt::test('household.show')->call('toggleMemberAddon', $membership->id, Addons::BUSINESS)->assertSee('Business');
        $this->assertSame(['business' => false], $membership->fresh()->permissions);

        // The partner no longer sees or opens the business area, everything else stays.
        $this->assertFalse($partner->fresh()->canUseAddon(Addons::BUSINESS));
        $this->assertTrue($partner->fresh()->canUseAddon(Addons::FINANCE));
        $this->actingAs($partner);
        $this->get(route('business.index'))->assertNotFound();
        $this->get(route('finance.index'))->assertOk();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('business.index'));

        // Switched on again.
        $this->actingAs($owner);
        Volt::test('household.show')->call('toggleMemberAddon', $membership->id, Addons::BUSINESS);
        $this->assertNull($membership->fresh()->permissions);
        $this->assertTrue($partner->fresh()->canUseAddon(Addons::BUSINESS));
    }

    public function test_only_owners_change_permissions_and_owners_cannot_be_restricted(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $ownerMembership = HouseholdMember::where('user_id', $owner->id)->sole();
        $partnerMembership = HouseholdMember::where('user_id', $partner->id)->sole();

        $this->actingAs($partner);
        Volt::test('household.show')->call('toggleMemberAddon', $partnerMembership->id, Addons::FINANCE)->assertForbidden();

        $this->actingAs($owner);
        Volt::test('household.show')->call('toggleMemberAddon', $ownerMembership->id, Addons::FINANCE)->assertStatus(422);
        $this->assertTrue($owner->canUseAddon(Addons::FINANCE));
    }

    public function test_livewire_requests_of_a_forbidden_module_are_rejected(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $this->actingAs($partner);
        $this->get(route('finance.index'))->assertOk();

        HouseholdMember::where('user_id', $partner->id)->update(['permissions' => json_encode(['finance' => false])]);

        $this->get(route('finance.index'))->assertNotFound();
        $household->setAddon(Addons::FINANCE, false);
        $this->actingAs($owner)->get(route('finance.index'))->assertNotFound();
    }
}
