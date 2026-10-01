<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Models\ShoppingStore;
use App\Models\ShoppingWatch;
use App\Support\ShoppingLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class ShoppingListsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_several_lists_are_kept_apart_renamed_sorted_and_deleted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $component = Volt::test('shopping-list.index')->set('label', 'Milch')->call('add');
        $weekly = ShoppingList::where('household_id', $household->id)->sole();

        $component->call('newList')->set('listForm.name', 'Drogerie')->set('listForm.icon', '🧴')->call('saveList')
            ->assertHasNoErrors()
            ->set('label', 'Zahnpasta')->call('add')
            ->assertSee('Zahnpasta')->assertDontSee('Milch');
        $drugstore = ShoppingList::where('name', 'Drogerie')->sole();
        $this->assertSame(['Zahnpasta'], $drugstore->entries()->pluck('label')->all());

        $component->call('selectList', $weekly->id)->assertSee('Milch')->assertDontSee('Zahnpasta')
            ->call('sortLists', $drugstore->id, 0);
        $this->assertSame(['Drogerie', 'Einkaufsliste'], ShoppingList::orderBy('position')->pluck('name')->all());

        // Move an item to another list in the edit sheet.
        $milk = ShoppingListEntry::where('label', 'Milch')->sole();
        $component->call('startEdit', $milk->id)->set('edit.quantity', '2 l')->set('edit.list', (string) $drugstore->id)->call('saveEdit');
        $this->assertSame([$drugstore->id, '2 l'], [$milk->fresh()->shopping_list_id, $milk->fresh()->quantity]);

        $component->call('selectList', $drugstore->id)->call('deleteList', $drugstore->id);
        $this->assertSame(0, ShoppingListEntry::count());
        $component->call('deleteList', $weekly->id)->assertStatus(422);

        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger);
        Volt::test('shopping-list.index')->call('selectList', $weekly->id)->assertNotFound();
    }

    public function test_the_same_item_is_merged_and_bought_items_become_suggestions(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $list = ShoppingList::defaultFor($household->id);

        ShoppingLists::add($list, 'Milch', '1 l', $user->id);
        ShoppingLists::add($list, 'milch', '1,5 l', $user->id);
        ShoppingLists::add($list, 'Petersilie', '1 Bund', $user->id);
        ShoppingLists::add($list, 'Petersilie', '2', $user->id);
        $this->assertSame(['2,5 l', '1 Bund + 2'], ShoppingListEntry::orderBy('id')->pluck('quantity')->all());

        $this->actingAs($user);
        $component = Volt::test('shopping-list.index');
        $milk = ShoppingListEntry::where('label', 'Milch')->sole();
        $component->call('toggle', $milk->id)->call('clearDone');
        $this->assertModelMissing($milk);

        $this->assertSame([['label' => 'Milch', 'quantity' => '2,5 l', 'count' => 1]], ShoppingLists::frequent($household->id));
        $component->assertSee('Oft gekauft')->call('addFrequent', 'Milch', '2,5 l');
        $this->assertSame('2,5 l', ShoppingListEntry::where('label', 'Milch')->sole()->quantity);
        // Open on the list again, so no longer suggested.
        $this->assertSame([], ShoppingLists::frequent($household->id, $list->id));

        // Deleting can be undone.
        $parsley = ShoppingListEntry::where('label', 'Petersilie')->sole();
        $component->call('delete', $parsley->id)->dispatch('shopping-undo');
        $this->assertSame('1 Bund + 2', ShoppingListEntry::where('label', 'Petersilie')->sole()->quantity);
    }

    public function test_stores_define_the_walking_route_and_items_are_dragged_between_sections(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $component = Volt::test('shopping-list.index');
        foreach (['Apfel', 'Wasser', 'Brot', 'Käse'] as $label) {
            $component->set('label', $label)->call('add');
        }

        Volt::test('shopping-list.index')->assertSeeInOrder(['Obst & Gemüse', 'Apfel', 'Backwaren', 'Brot', 'Milchprodukte & Eier', 'Käse', 'Getränke', 'Wasser']);
        $component->set('storeForm.name', 'REWE Hauptstraße')->set('storeForm.chain', 'rewe')->call('addStore')->assertHasNoErrors();
        $store = ShoppingStore::sole();
        $this->assertSame($store->id, ShoppingList::sole()->store_id);

        // Drinks are at the entrance of this store.
        $component->call('sortAisle', $store->id, 'drinks', 0);
        Volt::test('shopping-list.index')->assertSeeInOrder(['Getränke', 'Wasser', 'Obst & Gemüse', 'Apfel']);
        $this->assertSame('drinks', $store->fresh()->aisleOrder()[0]);

        // Dragging "Brot" into the dairy section at the top – remembered for next time.
        $bread = ShoppingListEntry::where('label', 'Brot')->sole();
        $component->call('moveEntry', $bread->id, 'dairy', 0);
        $this->assertSame(['dairy', 1], [$bread->fresh()->aisle, $bread->fresh()->position]);
        $this->assertSame(2, ShoppingListEntry::where('label', 'Käse')->sole()->position);
        $component->call('moveEntry', $bread->id, 'nope', 0)->assertNotFound();
    }

    public function test_recipe_ingredients_go_onto_the_chosen_list_and_known_items_are_marked(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Pfannkuchen', 'servings' => 2, 'ingredients' => [
            ['amount' => '250', 'unit' => 'g', 'name' => 'Mehl'],
            ['amount' => '500', 'unit' => 'ml', 'name' => 'Milch'],
        ]]);
        $weekly = ShoppingList::defaultFor($household->id);
        $party = ShoppingList::create(['household_id' => $household->id, 'name' => 'Party', 'position' => 1]);
        ShoppingLists::add($party, 'Milch', '500 ml', $user->id);
        $this->actingAs($user);

        Volt::test('recipes.show', ['recipe' => $recipe->id])
            ->call('chooseIngredients')
            ->assertSet('shoppingListId', $weekly->id)
            ->assertSet('selectedIngredients', [0, 1])
            ->set('shoppingListId', $party->id)
            ->call('chooseIngredients')
            ->assertSee('schon auf der Liste')
            ->assertSet('selectedIngredients', [0])
            ->set('selectedIngredients', [0, 1])
            ->call('addToShoppingList');

        $this->assertSame(['Milch' => '1 l', 'Mehl' => '250 g'], $party->entries()->orderBy('id')->pluck('quantity', 'label')->all());
        $this->assertSame('Pfannkuchen', $party->entries()->where('label', 'Mehl')->value('note'));
        $this->assertSame(0, $weekly->entries()->count());
    }

    public function test_offers_for_followed_brands_are_shown_filtered_and_put_on_the_list(): void
    {
        Http::fake([
            'www.marktguru.de/*' => Http::response('<script type="application/json">{"config":{"apiKey":"k1","clientKey":"c1"}}</script>'),
            'api.marktguru.de/*' => Http::response(['totalResults' => 2, 'results' => [
                ['id' => 11, 'price' => 2.99, 'oldPrice' => 4.49, 'product' => ['name' => 'Nuss-Nougat-Creme'], 'brand' => ['name' => 'Nutella'], 'advertisers' => [['name' => 'REWE']], 'validityDates' => [['from' => '2026-09-28', 'to' => '2026-10-03']], 'quantity' => 450, 'unit' => ['shortName' => 'g']],
                ['id' => 12, 'price' => 3.19, 'product' => ['name' => 'Nutella'], 'advertisers' => [['name' => 'Kaufland']], 'validityDates' => [['to' => '2026-10-02']]],
            ]]),
        ]);
        $this->travelTo('2026-09-28 10:00');
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $page = Volt::test('shopping-list.offers')
            ->assertSee('Gib deine Postleitzahl ein')
            ->set('zip', '80331')->call('saveSettings')->assertHasNoErrors()
            ->set('watchTerm', 'Nutella')->call('addWatch')
            ->assertSee('Nuss-Nougat-Creme')->assertSee('2,99 €')->assertSee('−33 %')->assertSee('3,19 €')
            ->call('toggleChain', 'rewe')
            ->assertDontSee('3,19 €');
        $this->assertSame(['80331', ['rewe']], [$household->fresh()->offers_zip, $household->fresh()->offers_chains]);
        $this->assertSame(1, ShoppingWatch::count());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.marktguru.de') && $request->hasHeader('x-apikey', 'k1') && $request['zipCode'] === '80331');

        $page->call('addOffer', ['title' => 'Nuss-Nougat-Creme', 'brand' => 'Nutella', 'store' => 'REWE', 'price' => 2.99, 'unit' => '450 g', 'valid_to' => '2026-10-03']);
        $entry = ShoppingListEntry::sole();
        $this->assertSame(['Nutella Nuss-Nougat-Creme', '450 g', 'Angebot REWE bis 03.10.', 2.99], [$entry->label, $entry->quantity, $entry->note, $entry->price]);
    }

    public function test_the_offers_page_still_links_the_flyers_when_the_service_is_down(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        [$user, $household] = $this->memberWithHousehold();
        $household->update(['offers_zip' => '10115']);
        ShoppingWatch::create(['household_id' => $household->id, 'term' => 'Kaffee']);
        $this->actingAs($user);

        Volt::test('shopping-list.offers')->assertSee('gerade nicht erreichbar')->assertSee('https://www.rewe.de/angebote/', false);
    }

    /**
     * The layout renders the header slot outside of the Livewire component, so wire: directives there would do nothing.
     */
    public function test_interactive_controls_are_rendered_inside_the_component_not_in_the_header(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        foreach (['shopping-list.index' => 'shoppingMode', 'shopping-list.offers' => 'wire:model.live="listId"'] as $route => $control) {
            $html = $this->get(route($route))->assertOk()->getContent();
            $this->assertGreaterThan(strpos($html, '<main'), strpos($html, $control), $route);
        }

        Volt::test('shopping-list.index')->set('shoppingMode', true)->assertSet('shoppingMode', true)->assertSee('Einkaufsmodus beenden');
    }
}
