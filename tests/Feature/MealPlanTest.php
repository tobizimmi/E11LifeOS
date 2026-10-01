<?php

namespace Tests\Feature;

use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\ShoppingListEntry;
use App\Support\ShoppingIngredients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class MealPlanTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so the week runs from Mon 2026-09-21 to Sun 2026-09-27.
        Carbon::setTestNow('2026-09-23 12:00:00');
    }

    public function test_recipes_and_free_text_can_be_planned_for_a_day(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Lasagne', 'servings' => 4]);
        $this->actingAs($user);

        Volt::test('recipes.plan')
            ->call('startAdding', '2026-09-27', 'lunch')
            ->set('recipeSearch', 'lasa')
            ->assertSee('Lasagne')
            ->call('addRecipe', $recipe->id)
            ->assertSet('addingDate', null)
            ->call('startAdding', '2026-09-24')
            ->set('customTitle', 'Essen gehen')
            ->call('addCustom');

        $entries = MealPlan::orderBy('id')->get();
        $this->assertSame(['2026-09-27', 'lunch', $recipe->id, 'Lasagne', 4], [$entries[0]->date->toDateString(), $entries[0]->meal_type, $entries[0]->recipe_id, $entries[0]->title, $entries[0]->servings]);
        $this->assertSame(['2026-09-24', 'dinner', null, 'Essen gehen'], [$entries[1]->date->toDateString(), $entries[1]->meal_type, $entries[1]->recipe_id, $entries[1]->title]);

        // Sunday is the last day of the week and must still be shown.
        Volt::test('recipes.plan')->assertSee('Lasagne')->assertSee('Essen gehen');
    }

    public function test_weeks_can_be_navigated_and_entries_moved_or_removed(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $entry = MealPlan::factory()->for($household)->create(['date' => '2026-09-29', 'title' => 'Pizza KW40']);
        $this->actingAs($user);

        Volt::test('recipes.plan')
            ->assertDontSee('Pizza KW40')
            ->call('changeWeek', 1)
            ->assertSet('week', '2026-09-28')
            ->assertSee('Pizza KW40')
            ->call('moveEntry', $entry->id, '2026-10-01')
            ->call('changeWeek', -1)
            ->assertSet('week', '');

        $this->assertSame('2026-10-01', $entry->fresh()->date->toDateString());

        Volt::test('recipes.plan', ['week' => '2026-09-30'])->call('removeEntry', $entry->id);
        $this->assertDatabaseMissing('meal_plans', ['id' => $entry->id]);
    }

    public function test_the_ingredients_of_the_week_go_onto_the_shopping_list_combined_and_scaled(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $pasta = Recipe::factory()->for($household)->create(['title' => 'Pasta', 'servings' => 2, 'ingredients' => [
            ['amount' => '200', 'unit' => 'g', 'name' => 'Nudeln'],
            ['amount' => '1', 'unit' => 'Prise', 'name' => 'Salz'],
        ]]);
        $salad = Recipe::factory()->for($household)->create(['title' => 'Nudelsalat', 'servings' => 4, 'ingredients' => [
            ['name' => 'Salat', 'isSection' => true],
            ['amount' => '250', 'unit' => 'g', 'name' => 'nudeln'],
            ['amount' => 'etwas', 'unit' => 'Prise', 'name' => 'Salz'],
        ]]);
        MealPlan::factory()->for($household)->create(['date' => '2026-09-22', 'recipe_id' => $pasta->id, 'title' => 'Pasta', 'servings' => 4]);
        MealPlan::factory()->for($household)->create(['date' => '2026-09-25', 'recipe_id' => $salad->id, 'title' => 'Nudelsalat', 'servings' => 4]);
        MealPlan::factory()->for($household)->create(['date' => '2026-09-30', 'recipe_id' => $pasta->id, 'title' => 'Pasta']);
        $this->actingAs($user);

        Volt::test('recipes.plan')
            ->call('chooseShopping')
            ->assertSet('selectedShopping', [0, 1])
            ->call('addWeekToShoppingList');

        $this->assertSame(
            [['Nudeln', '650 g'], ['Salz', '2 + etwas Prise']],
            ShoppingListEntry::orderBy('label')->get()->map(fn ($entry) => [$entry->label, $entry->quantity])->all(),
        );
    }

    public function test_combining_converts_units_merges_spellings_and_lists_the_recipes(): void
    {
        $combined = ShoppingIngredients::combine([
            ['name' => 'Milch', 'amount' => '200', 'unit' => 'ml', 'recipe' => 'A'],
            ['name' => 'Milch', 'amount' => '1', 'unit' => 'Liter', 'recipe' => 'B'],
            ['name' => 'Eier', 'amount' => '1,5', 'unit' => '', 'recipe' => 'A'],
            ['name' => 'eier', 'amount' => '1/2', 'unit' => 'Stück', 'recipe' => 'B'],
            ['name' => 'Zwiebel', 'amount' => '1', 'unit' => '', 'recipe' => 'A'],
            ['name' => 'Zwiebeln (rot)', 'amount' => '2', 'unit' => '', 'recipe' => 'B'],
            ['name' => 'Mehl', 'amount' => '300', 'unit' => 'g', 'recipe' => 'A'],
            ['name' => 'Mehl', 'amount' => '0,7', 'unit' => 'kg', 'recipe' => 'B'],
            ['name' => 'Öl', 'amount' => '1', 'unit' => 'Esslöffel', 'recipe' => 'A'],
            ['name' => 'Öl', 'amount' => '1', 'unit' => 'TL', 'recipe' => 'B'],
            ['name' => 'Petersilie', 'amount' => '1', 'unit' => 'Bund', 'recipe' => 'A'],
            ['name' => 'Petersilie', 'amount' => '20', 'unit' => 'g', 'recipe' => 'B'],
        ]);

        $this->assertSame([
            ['name' => 'Eier', 'quantity' => '2', 'recipes' => ['A', 'B']],
            ['name' => 'Mehl', 'quantity' => '1 kg', 'recipes' => ['A', 'B']],
            ['name' => 'Milch', 'quantity' => '1,2 l', 'recipes' => ['A', 'B']],
            ['name' => 'Öl', 'quantity' => '4 TL', 'recipes' => ['A', 'B']],
            ['name' => 'Petersilie', 'quantity' => '1 Bund', 'recipes' => ['A']],
            ['name' => 'Petersilie', 'quantity' => '20 g', 'recipes' => ['B']],
            ['name' => 'Zwiebel', 'quantity' => '3', 'recipes' => ['A', 'B']],
        ], $combined);
    }

    public function test_private_recipes_make_private_entries_and_hidden_recipes_are_not_linked(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $private = Recipe::factory()->for($household)->privateTo($owner)->create(['title' => 'Geheimrezept']);
        $shared = Recipe::factory()->for($household)->create(['title' => 'Chili', 'created_by' => $owner->id]);

        $this->actingAs($owner);
        Volt::test('recipes.show', ['recipe' => $private->id])->set('planDate', '2026-09-23')->call('planMeal');
        Volt::test('recipes.show', ['recipe' => $shared->id])->set('planDate', '2026-09-23')->set('planMealType', 'lunch')->call('planMeal');
        $this->assertTrue(MealPlan::where('recipe_id', $private->id)->firstOrFail()->isPrivate());

        // The shared recipe becomes private later: the entry stays, but without the link.
        $shared->update(['visibility' => 'private']);

        $this->actingAs($partner);
        Volt::test('recipes.plan')
            ->assertDontSee('Geheimrezept')
            ->assertSee('Chili')
            ->assertDontSeeHtml(e(route('recipes.show', $shared)));
        $this->assertThrows(fn () => Volt::test('recipes.plan')->call('startAdding', '2026-09-23')->call('addRecipe', $private->id));
    }

    public function test_the_dashboard_shows_todays_meals(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        MealPlan::factory()->for($household)->create(['date' => '2026-09-23', 'meal_type' => 'dinner', 'title' => 'Flammkuchen']);
        MealPlan::factory()->for($household)->create(['date' => '2026-09-24', 'title' => 'Pizza Freitag']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Flammkuchen')
            ->assertDontSee('Pizza Freitag');
    }
}
