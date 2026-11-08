<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Support\MealSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class MealSuggestionsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday: the free days of the week are Wed–Sun.
        Carbon::setTestNow('2026-09-23 12:00:00');
    }

    private function pantryItem(Collection $pantry, string $title, ?string $bestBefore = null, ?int $quantity = 1): void
    {
        $entry = Item::create(['household_id' => $pantry->household_id, 'collection_id' => $pantry->id, 'type' => Item::TYPE_ENTRY, 'title' => $title, 'visibility' => Item::VISIBILITY_SHARED]);
        $entry->setPropertyValue($pantry->properties->firstWhere('name', 'Menge'), $quantity);

        if ($bestBefore) {
            $entry->setPropertyValue($pantry->properties->firstWhere('name', 'Haltbar bis'), $bestBefore);
        }

        $entry->save();
    }

    public function test_favorites_pantry_and_expiring_food_rank_first_and_recent_recipes_last(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        Volt::test('collections.index')->set('name', 'Vorräte')->set('template', 'pantry')->call('create');
        $pantry = Collection::where('name', 'Vorräte')->sole();
        $this->pantryItem($pantry, 'Spinat', '2026-09-25');
        $this->pantryItem($pantry, 'Reis');
        $this->pantryItem($pantry, 'Kartoffeln', null, 0);

        $spinach = Recipe::factory()->for($household)->create(['title' => 'Spinat-Risotto', 'ingredients' => [['amount' => '300', 'unit' => 'g', 'name' => 'Reis'], ['amount' => '200', 'unit' => 'g', 'name' => 'Spinat']]]);
        $favorite = Recipe::factory()->for($household)->create(['title' => 'Lieblingscurry', 'ingredients' => [['amount' => '1', 'unit' => '', 'name' => 'Kokosmilch']]]);
        $favorite->favoritedBy()->attach($user->id);
        RecipeRating::create(['recipe_id' => $favorite->id, 'user_id' => $user->id, 'rating' => 5]);
        $recent = Recipe::factory()->for($household)->create(['title' => 'Gestern erst', 'ingredients' => [['amount' => '1', 'unit' => 'kg', 'name' => 'Kartoffeln']]]);
        MealPlan::factory()->for($household)->create(['date' => '2026-09-21', 'recipe_id' => $recent->id, 'title' => 'Gestern erst']);

        $this->assertSame(['stock' => ['spinat', 'reis'], 'expiring' => ['spinat']], MealSuggestions::pantry($user, $household->id));

        $suggestions = MealSuggestions::forDates($user, $household->id, ['2026-09-24', '2026-09-25', '2026-09-26']);
        $titles = collect($suggestions)->map(fn (array $row) => $row['recipe']->title)->all();

        $this->assertSame(['Spinat-Risotto', 'Lieblingscurry'], collect($titles)->take(2)->sort()->values()->reverse()->values()->all());
        $this->assertSame('Gestern erst', $titles['2026-09-26']);
        $reasons = collect($suggestions)->mapWithKeys(fn (array $row) => [$row['recipe']->title => implode(' · ', $row['reasons'])]);
        $this->assertSame('🧺 2 Zutaten vorrätig · ⏳ nutzt Spinat', $reasons['Spinat-Risotto']);
        $this->assertSame('♥ Favorit · ★ 5,0', $reasons['Lieblingscurry']);
    }

    public function test_the_week_is_suggested_planned_and_only_missing_ingredients_are_preselected(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        Volt::test('collections.index')->set('name', 'Vorräte')->set('template', 'pantry')->call('create');
        $this->pantryItem(Collection::where('name', 'Vorräte')->sole(), 'Reis');
        $risotto = Recipe::factory()->for($household)->create(['title' => 'Risotto', 'servings' => 2, 'ingredients' => [['amount' => '300', 'unit' => 'g', 'name' => 'Reis'], ['amount' => '1', 'unit' => '', 'name' => 'Zwiebel']]]);
        MealPlan::factory()->for($household)->create(['date' => '2026-09-24', 'meal_type' => 'dinner', 'title' => 'Essen gehen']);

        $component = Volt::test('recipes.plan')->call('suggestWeek');

        // Wed, Fri, Sat, Sun are free (Thursday already has a dinner, Mon/Tue are past); only one recipe exists.
        $this->assertSame(['2026-09-23'], array_keys($component->get('suggestions')));

        $component->assertSee('Risotto')
            ->call('rerollSuggestion', '2026-09-23')
            ->call('applySuggestions')
            ->assertSet('suggestions', [])
            ->assertSet('choosingShopping', true)
            ->assertSet('selectedShopping', [1])
            ->assertSee('vorrätig');

        $this->assertSame(['2026-09-23', 'dinner', 2], [MealPlan::where('recipe_id', $risotto->id)->sole()->date->toDateString(), MealPlan::where('recipe_id', $risotto->id)->sole()->meal_type, MealPlan::where('recipe_id', $risotto->id)->sole()->servings]);
    }

    public function test_another_course_than_dinner_can_be_suggested_and_planned(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $pancakes = Recipe::factory()->for($household)->create(['title' => 'Pfannkuchen', 'servings' => 2]);
        // A dinner on Wednesday must not block a breakfast suggestion for the same day.
        MealPlan::factory()->for($household)->create(['date' => '2026-09-23', 'meal_type' => 'dinner', 'title' => 'Essen gehen']);

        Volt::test('recipes.plan')
            ->set('suggestMealType', 'breakfast')
            ->call('suggestWeek')
            ->assertSee('Vorschläge für Frühstück')
            ->assertSee('Pfannkuchen')
            ->call('applySuggestions');

        $planned = MealPlan::where('recipe_id', $pancakes->id)->sole();
        $this->assertSame(['2026-09-23', 'breakfast'], [$planned->date->toDateString(), $planned->meal_type]);
    }
}
