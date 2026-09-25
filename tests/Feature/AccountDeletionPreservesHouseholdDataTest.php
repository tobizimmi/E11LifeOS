<?php

namespace Tests\Feature;

use App\Models\HouseholdMember;
use App\Models\Item;
use App\Models\ShoppingListEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class AccountDeletionPreservesHouseholdDataTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_deleting_a_users_account_keeps_the_notes_and_todos_they_created(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        $note = Item::create([
            'household_id' => $household->id,
            'type' => Item::TYPE_NOTE,
            'title' => 'Shared note',
            'created_by' => $user->id,
        ]);

        $entry = ShoppingListEntry::create([
            'household_id' => $household->id,
            'label' => 'Milch',
            'created_by' => $user->id,
        ]);

        $user->delete();

        $this->assertDatabaseHas('items', ['id' => $note->id, 'created_by' => null]);
        $this->assertDatabaseHas('shopping_list_entries', ['id' => $entry->id, 'created_by' => null]);
        $this->assertDatabaseMissing('household_members', ['household_id' => $household->id, 'user_id' => $user->id]);
    }

    public function test_deleting_one_members_account_does_not_remove_other_members(): void
    {
        [$owner, $household] = $this->memberWithHousehold('owner');
        $partner = User::factory()->create();
        HouseholdMember::create([
            'household_id' => $household->id,
            'user_id' => $partner->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        $owner->delete();

        $this->assertDatabaseHas('household_members', ['household_id' => $household->id, 'user_id' => $partner->id]);
    }

    public function test_deleting_a_users_account_removes_their_private_items(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);

        $private = Item::factory()->note()->for($household)->privateTo($user)->create();
        $partnersPrivate = Item::factory()->note()->for($household)->privateTo($partner)->create();

        $user->delete();

        $this->assertDatabaseMissing('items', ['id' => $private->id]);
        $this->assertDatabaseHas('items', ['id' => $partnersPrivate->id]);
    }
}
