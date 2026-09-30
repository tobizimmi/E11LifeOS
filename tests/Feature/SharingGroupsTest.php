<?php

namespace Tests\Feature;

use App\Models\ExpenseGroup;
use App\Models\Item;
use App\Models\ShareGroup;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SharingGroupsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * Me with a partner in my household and a friend from another household in a group "Freunde".
     *
     * @return array{User, User, User, ShareGroup, int}
     */
    private function setUpGroup(): array
    {
        [$me, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        [$friend] = $this->memberWithHousehold();
        $group = ShareGroup::factory()->create(['name' => 'Freunde', 'created_by' => $me->id]);
        $group->members()->create(['user_id' => $friend->id, 'role' => ShareGroup::ROLE_MEMBER]);

        return [$me, $partner, $friend, $group, $household->id];
    }

    public function test_a_group_is_created_and_people_from_other_households_join_with_the_link(): void
    {
        [$me] = $this->memberWithHousehold();
        [$friend] = $this->memberWithHousehold();

        $this->actingAs($me);
        Volt::test('groups.index')->set('showForm', true)->set('form.name', 'Vereinsausflug')->call('create')->assertHasNoErrors();
        $group = ShareGroup::where('name', 'Vereinsausflug')->sole();
        $this->assertTrue($group->isOwner($me));

        Volt::test('groups.index')->call('createInvite', $group->id);
        $token = $group->fresh()->invite_token;
        $this->assertNotNull($token);
        $this->assertNotSame($token, $group->fresh()->getRawOriginal('invite_token'));

        $this->actingAs($friend)->get(route('groups.join', 'wrong-token'))->assertNotFound();
        Volt::test('groups.join', ['token' => $token])->assertSee('Vereinsausflug')->call('join');
        $this->assertTrue($group->hasMember($friend));

        $this->travel(8)->days();
        [$late] = $this->memberWithHousehold();
        $this->actingAs($late)->get(route('groups.join', $token))->assertNotFound();
    }

    public function test_a_private_trip_shared_with_a_group_is_visible_to_the_group_but_not_to_the_household(): void
    {
        [$me, $partner, $friend, $group, $householdId] = $this->setUpGroup();
        $trip = Trip::factory()->create(['household_id' => $householdId, 'created_by' => $me->id, 'title' => 'Skiurlaub', 'visibility' => Item::VISIBILITY_PRIVATE]);

        $this->actingAs($me);
        Volt::test('sharing.panel', ['type' => 'trip', 'id' => $trip->id])->assertSee('Wer sieht das?')->call('toggle', $group->id);
        $this->assertTrue($trip->isSharedWith($friend));

        $this->actingAs($friend);
        Volt::test('trips.index')->assertSee('Skiurlaub')->assertSee('Geteilt von '.$me->name);
        Volt::test('trips.show', ['trip' => $trip])->assertOk()->call('deleteTrip')->assertForbidden();
        Volt::test('sharing.panel', ['type' => 'trip', 'id' => $trip->id])->assertSee('über Freunde mit dir geteilt')->call('toggle', $group->id)->assertForbidden();

        $this->actingAs($partner);
        Volt::test('trips.index')->assertDontSee('Skiurlaub');

        $this->actingAs($me);
        Volt::test('trips.index')->assertSee('👥 Freunde');

        $this->actingAs($me);
        Volt::test('groups.index')->assertSee('Skiurlaub')->call('unshare', $trip->shares()->sole()->id);
        $this->actingAs($friend);
        Volt::test('trips.show', ['trip' => $trip])->assertNotFound();
    }

    public function test_group_members_can_add_expenses_to_a_shared_cost_split(): void
    {
        [$me, , $friend, $group, $householdId] = $this->setUpGroup();
        $split = ExpenseGroup::factory()->create(['household_id' => $householdId, 'created_by' => $me->id, 'title' => 'Hüttenwochenende']);
        $split->shares()->create(['share_group_id' => $group->id, 'shared_by' => $me->id]);

        $this->actingAs($friend);
        Volt::test('splits.index')->assertSee('Hüttenwochenende');
        Volt::test('splits.show', ['group' => $split])->assertOk()->call('deleteGroup')->assertForbidden();
        $this->assertModelExists($split);
    }

    public function test_a_shared_page_opens_in_the_shared_view_and_can_be_edited(): void
    {
        [$me, , $friend, $group, $householdId] = $this->setUpGroup();
        $page = Item::factory()->create(['household_id' => $householdId, 'created_by' => $me->id, 'type' => Item::TYPE_NOTE, 'title' => 'Packliste Hütte', 'content' => ['body' => 'Hüttenschlafsack']]);
        [$stranger] = $this->memberWithHousehold();

        $this->actingAs($friend)->get(route('groups.page', $page))->assertNotFound();
        $page->shares()->create(['share_group_id' => $group->id, 'shared_by' => $me->id]);

        Volt::test('groups.page', ['item' => $page])->assertSee('Hüttenschlafsack')
            ->call('edit')->set('body', 'Hüttenschlafsack, Stirnlampe')->call('save')->assertHasNoErrors();
        $this->assertSame('Hüttenschlafsack, Stirnlampe', $page->fresh()->body());

        $this->actingAs($stranger)->get(route('groups.page', $page))->assertNotFound();
        $this->actingAs($me)->get(route('groups.page', $page))->assertRedirect(route('pages.index', ['item' => $page->id]));
    }

    public function test_leaving_a_group_ends_what_you_shared_there_and_passes_on_the_admin_role(): void
    {
        [$me, , $friend, $group, $householdId] = $this->setUpGroup();
        $friendTrip = Trip::factory()->create(['household_id' => $friend->currentHousehold()->id, 'created_by' => $friend->id, 'title' => 'Radtour']);
        $friendTrip->shares()->create(['share_group_id' => $group->id, 'shared_by' => $friend->id]);

        $this->actingAs($me);
        Volt::test('groups.index')->assertSee('Radtour')->assertSee('Mit mir geteilt');

        $this->actingAs($friend);
        Volt::test('groups.index')->call('leave', $group->id);
        $this->assertFalse($friendTrip->isSharedWith($me));

        $group->members()->create(['user_id' => $friend->id, 'role' => ShareGroup::ROLE_MEMBER]);
        $this->actingAs($me);
        Volt::test('groups.index')->call('leave', $group->id);
        $this->assertTrue($group->fresh()->isOwner($friend));
    }
}
