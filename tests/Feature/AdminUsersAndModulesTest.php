<?php

namespace Tests\Feature;

use App\Models\ModuleEntitlement;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Addons;
use App\Support\Admin\ModuleRights;
use App\Support\Modules\ModuleAccess;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class AdminUsersAndModulesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Ada Admin', 'password' => 'admin-password-1']);
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    public function test_administrators_see_every_account_with_households_and_the_list_of_admins(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $household->update(['name' => 'Familie Berg']);
        $member = $this->addMemberTo($household);
        $loner = User::factory()->create(['name' => 'Ohne Haushalt']);
        $admin = $this->admin();

        $this->actingAs($member)->get(route('admin.users'))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.users'))->assertOk()->assertSee($owner->email)->assertSee('Familie Berg');
        Volt::test('admin.users')
            ->assertSee($loner->name)->assertSee('Kein Haushalt')->assertSee('Inhaber/in')
            ->set('view', 'admins')->assertSee('Ada Admin')->assertDontSee($loner->name)
            ->set('view', 'all')->set('search', 'Ohne')->assertSee($loner->name)->assertDontSee($owner->email);
    }

    public function test_the_admin_right_changes_only_with_the_password_and_never_for_oneself(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->actingAs($admin);

        Volt::test('admin.users')->call('startAdminChange', $user->id)->set('adminPassword', 'wrong')->call('changeAdmin')->assertHasErrors('adminPassword');
        $this->assertFalse($user->fresh()->is_admin);

        Volt::test('admin.users')->call('startAdminChange', $user->id)->set('adminPassword', 'admin-password-1')->call('changeAdmin')->assertHasNoErrors();
        $this->assertTrue($user->fresh()->is_admin);
        $this->assertContains('admin_granted user #'.$user->id, SecurityEvent::where('user_id', $admin->id)->get()->pluck('details'));

        Volt::test('admin.users')->call('startAdminChange', $admin->id)->set('adminPassword', 'admin-password-1')->call('changeAdmin')->assertHasErrors('adminPassword');
        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_a_locked_area_disappears_for_the_household_or_a_single_account(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $member = $this->addMemberTo($household);
        $admin = $this->admin();

        $this->actingAs($owner)->get(route('trips.index'))->assertOk();

        // Locked for one account only.
        $this->actingAs($admin);
        Volt::test('admin.module-access', ['subjectType' => 'user', 'subjectId' => $member->id])->call('setAccess', 'trips', 'blocked');
        $this->actingAs($member)->get(route('trips.index'))->assertNotFound();
        $this->assertArrayNotHasKey('trips', Navigation::modules($member));
        $this->actingAs($owner)->get(route('trips.index'))->assertOk();

        // Locked for the household: a release of the account does not override it.
        ModuleRights::set($admin, 'household', $household->id, 'trips', 'blocked');
        ModuleRights::set($admin, 'user', $member->id, 'trips', 'granted');
        $this->actingAs($owner)->get(route('trips.index'))->assertNotFound();
        $this->actingAs($member)->get(route('trips.index'))->assertNotFound();

        // Addons too, and the owner sees why the switch is gone.
        ModuleRights::set($admin, 'household', $household->id, Addons::FINANCE, 'blocked');
        $this->assertFalse($owner->canUseAddon(Addons::FINANCE));
        $this->actingAs($owner)->get(route('finance.index'))->assertNotFound();
        $this->get(route('household.show'))->assertSee('Vom Administrator gesperrt');

        // Back to the default.
        ModuleRights::set($admin, 'household', $household->id, 'trips', 'default');
        $this->get(route('trips.index'))->assertOk();
        $this->assertSame(2, ModuleEntitlement::count());
    }

    public function test_licensed_areas_need_a_release_that_can_expire(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $member = $this->addMemberTo($household);
        $admin = $this->admin();

        $this->actingAs($admin);
        Volt::test('admin.modules')->call('setPlan', 'vehicles', 'licensed')->call('setPlan', Addons::PORTFOLIO, 'licensed')->assertSee('1 Haushalt ohne Freigabe');
        $this->assertSame(ModuleAccess::LICENSED, ModuleAccess::plan('vehicles'));

        $this->actingAs($owner)->get(route('vehicles.index'))->assertNotFound();
        $this->assertFalse($owner->canUseAddon(Addons::PORTFOLIO));

        // A purchased license for the household (later from the shop) opens it for every member.
        ModuleEntitlement::factory()->license()->create(['module' => 'vehicles', 'household_id' => $household->id]);
        $this->get(route('vehicles.index'))->assertOk();
        $this->actingAs($member)->get(route('vehicles.index'))->assertOk();

        // A release for one account only, until yesterday: expired.
        ModuleEntitlement::create(['module' => Addons::PORTFOLIO, 'user_id' => $owner->id, 'access' => 'granted', 'source' => 'trial', 'ends_at' => now()->subDay()]);
        $this->assertFalse($owner->fresh()->canUseAddon(Addons::PORTFOLIO));
        ModuleRights::set($admin, 'user', $owner->id, Addons::PORTFOLIO, 'granted', now()->addMonth());
        $this->assertTrue($owner->fresh()->canUseAddon(Addons::PORTFOLIO));
        $this->assertFalse($member->fresh()->canUseAddon(Addons::PORTFOLIO));

        // The administrator's entries replace each other; the license stays.
        $this->actingAs($admin);
        Volt::test('admin.module-access', ['subjectType' => 'household', 'subjectId' => $household->id])->assertSee('Lizenz')
            ->set('until.vehicles', now()->subDay()->toDateString())->call('setAccess', 'vehicles', 'blocked')->assertHasErrors('until.vehicles');
        $this->assertSame(1, ModuleEntitlement::where('module', 'vehicles')->count());

        Volt::test('admin.modules')->call('setPlan', 'vehicles', 'included')->call('setPlan', 'unknown', 'licensed')->assertStatus(422);
        $this->assertSame([Addons::PORTFOLIO => 'licensed'], ModuleAccess::plans());
    }
}
