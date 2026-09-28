<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SharedListsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_the_shopping_list_can_be_shared_ticked_off_and_extended_without_login(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $milk = ShoppingListEntry::create(['household_id' => $household->id, 'label' => 'Milch']);
        [, $otherHousehold] = $this->memberWithHousehold();
        $foreign = ShoppingListEntry::create(['household_id' => $otherHousehold->id, 'label' => 'Fremd']);
        $this->actingAs($user);

        Volt::test('shopping-list.index')->call('createShareLink')->assertSee('ohne Anmeldung');
        $token = ShoppingList::where('household_id', $household->id)->sole()->share_token;
        $this->assertSame(40, strlen($token));

        auth()->logout();
        $this->get(route('shopping-list.shared', $token))->assertOk()->assertSee('Milch')->assertDontSee('Fremd');

        Volt::test('shopping-list.shared', ['token' => $token])
            ->call('toggle', $milk->id)
            ->set('label', 'Brot')->call('add')
            ->assertSee('Brot');
        $this->assertTrue($milk->fresh()->checked);
        $this->assertSame($household->id, ShoppingListEntry::where('label', 'Brot')->sole()->household_id);

        Volt::test('shopping-list.shared', ['token' => $token])->call('toggle', $foreign->id)->assertNotFound();

        $this->actingAs($user);
        Volt::test('shopping-list.index')->call('revokeShareLink');
        auth()->logout();
        $this->get(route('shopping-list.shared', $token))->assertNotFound();
        $this->get(route('shopping-list.shared', 'short'))->assertNotFound();
    }

    public function test_a_collection_can_be_shared_read_only_without_private_entries(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        Volt::test('collections.index')->set('name', 'Leseliste')->set('template', 'reading')->call('create');
        $collection = Collection::where('name', 'Leseliste')->sole();
        Item::create(['household_id' => $household->id, 'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'title' => 'Der Hobbit', 'visibility' => Item::VISIBILITY_SHARED]);
        Item::create(['household_id' => $household->id, 'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'title' => 'Mein Tagebuch', 'visibility' => Item::VISIBILITY_PRIVATE, 'created_by' => $user->id]);

        Volt::test('collections.show', ['collection' => $collection->id])->set('showSettings', true)->assertSee('Per Nur-Lesen-Link teilen')->call('createCollectionLink')->assertSee('nur lesen, ohne private Einträge');
        $token = $collection->fresh()->public_token;

        auth()->logout();
        $this->get(route('collections.public', $token))->assertOk()->assertSee('Leseliste')->assertSee('Der Hobbit')->assertDontSee('Mein Tagebuch');

        $this->actingAs($user);
        Volt::test('collections.show', ['collection' => $collection->id])->call('revokeCollectionLink');
        auth()->logout();
        $this->get(route('collections.public', $token))->assertNotFound();
    }

    public function test_the_task_list_cannot_be_shared(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $tasks = Collection::tasksFor($household->id);

        Volt::test('collections.show', ['collection' => $tasks->id])->call('createCollectionLink')->assertNotFound();
    }
}
