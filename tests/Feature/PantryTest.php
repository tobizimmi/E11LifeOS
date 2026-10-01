<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Household;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\ShoppingListEntry;
use App\Models\User;
use App\Support\CollectionTemplates;
use App\Support\Pantry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PantryTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array{0: User, 1: Household, 2: Collection, 3: callable}
     */
    private function pantry(): array
    {
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));
        [$user, $household] = $this->memberWithHousehold();
        $collection = Collection::factory()->create(['household_id' => $household->id, 'name' => 'Vorräte']);
        CollectionTemplates::apply($collection, 'pantry');
        $field = fn (string $key) => $collection->properties()->where('system_key', $key)->sole()->id;
        $add = fn (string $title, ?float $quantity, string $unit = '', ?float $minimum = null, ?string $bestBefore = null) => Item::create([
            'household_id' => $household->id, 'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'visibility' => Item::VISIBILITY_SHARED,
            'title' => $title, 'created_by' => $user->id,
            'properties' => array_filter([$field('quantity') => $quantity, $field('unit') => $unit ?: null, $field('minimum') => $minimum, $field('best_before') => $bestBefore], fn ($value) => $value !== null),
        ]);

        return [$user, $household, $collection, $add];
    }

    public function test_the_pantry_template_is_linked_and_cooking_takes_the_ingredients_out(): void
    {
        [$user, $household, $collection, $add] = $this->pantry();
        $this->assertSame(Pantry::SYSTEM_KEY, $collection->fresh()->system_key);
        $flour = $add('Mehl', 1, 'kg', 0.5);
        $milk = $add('Milch', 2, 'l');
        $eggs = $add('Eier', 6);
        $salt = $add('Salz', null);
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Pfannkuchen', 'servings' => 2, 'ingredients' => [
            ['amount' => '300', 'unit' => 'g', 'name' => 'Weizenmehl Type 405'],
            ['amount' => '500', 'unit' => 'ml', 'name' => 'Milch'],
            ['amount' => '2', 'unit' => '', 'name' => 'Eier'],
            ['amount' => 'etwas', 'unit' => '', 'name' => 'Salz'],
            ['amount' => '1', 'unit' => 'Bund', 'name' => 'Schnittlauch'],
        ]]);

        $this->actingAs($user);
        $page = Volt::test('recipes.show', ['recipe' => $recipe->id])->set('servings', 4)->call('markCooked')
            ->assertSee('Aus dem Vorrat abziehen?')->assertSee('knapp');
        $this->assertSame(['Mehl' => [1.0, 0.4, true], 'Milch' => [2.0, 1.0, false], 'Eier' => [6.0, 2.0, false]],
            collect($page->get('pantryUsage'))->mapWithKeys(fn ($use) => [$use['name'] => [$use['before'], $use['after'], $use['low']]])->all());

        $page->call('takeFromPantry', true)->assertSet('pantryUsage', []);
        $quantity = fn (Item $item) => $item->fresh()->propertyValue($collection->properties()->where('system_key', 'quantity')->sole());
        $this->assertEquals([0.4, 1, 2], [$quantity($flour), $quantity($milk), $quantity($eggs)]);
        $this->assertSame(1, $recipe->fresh()->cooked_count);
        $this->assertSame(['Mehl'], ShoppingListEntry::pluck('label')->all());
        $this->assertNull($quantity($salt));
    }

    public function test_ingredients_in_the_pantry_are_not_ticked_for_the_shopping_list(): void
    {
        [$user, $household, , $add] = $this->pantry();
        $add('Mehl', 1, 'kg');
        $add('Zwiebeln', 1);
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Zwiebelkuchen', 'servings' => 1, 'ingredients' => [
            ['amount' => '250', 'unit' => 'g', 'name' => 'Mehl'],
            ['amount' => '3', 'unit' => '', 'name' => 'Zwiebel'],
            ['amount' => '200', 'unit' => 'g', 'name' => 'Speck'],
        ]]);

        $this->actingAs($user);
        Volt::test('recipes.show', ['recipe' => $recipe->id])->call('chooseIngredients')
            ->assertSet('inPantry', [0])->assertSet('selectedIngredients', [1, 2])->assertSee('im Vorrat');
    }

    public function test_expiring_items_lead_to_matching_recipes(): void
    {
        [$user, $household, , $add] = $this->pantry();
        $add('Spinat', 1, '', null, '2026-10-02');
        $add('Reis', 1, 'kg', null, '2027-05-01');
        $add('Joghurt', 0, '', null, '2026-09-30');
        Recipe::factory()->for($household)->create(['title' => 'Spinat-Lasagne', 'ingredients' => [['amount' => '500', 'unit' => 'g', 'name' => 'Spinat']]]);
        Recipe::factory()->for($household)->create(['title' => 'Milchreis', 'ingredients' => [['amount' => '200', 'unit' => 'g', 'name' => 'Reis']]]);

        $this->actingAs($user);
        Volt::test('recipes.index')->assertSee('Läuft bald ab')->assertDontSee('Joghurt (')
            ->call('useExpiring')->assertSet('pantry', 'Spinat')->assertSee('Spinat-Lasagne')->assertDontSee('Milchreis')
            ->call('fillFromPantry')->assertSet('pantry', 'Reis, Spinat');
    }
}
