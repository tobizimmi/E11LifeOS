<?php

namespace Tests\Feature;

use App\Models\MealPlan;
use App\Models\MealPlanPreference;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Support\MealPlanAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class MealPlannerTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A Wednesday → the week runs from Mon 2026-09-21.
        Carbon::setTestNow('2026-09-23 12:00:00');
    }

    /**
     * @return array<string, mixed>
     */
    private function aiResult(): array
    {
        return [
            'supermarket' => 'Rewe',
            'currency' => 'EUR',
            'estimated_total' => 42.5,
            'within_budget' => true,
            'notes' => 'Günstig geplant.',
            'recipes' => [
                ['day' => 1, 'meal_type' => 'dinner', 'title' => 'Pasta Pomodoro', 'description' => 'Schnell', 'servings' => 2, 'prep_time' => 10, 'cook_time' => 15, 'difficulty' => 'easy', 'calories' => 600, 'meal_prep' => false, 'tags' => ['italian'], 'ingredients' => [['name' => 'Spaghetti', 'amount' => '250 g'], ['name' => 'Tomaten', 'amount' => '400 g']], 'instructions' => ['Nudeln kochen', 'Mischen']],
                ['day' => 2, 'meal_type' => 'dinner', 'title' => 'Gemüsecurry', 'servings' => 2, 'ingredients' => [['name' => 'Reis', 'amount' => '200 g']], 'instructions' => ['Reis kochen']],
            ],
            'shopping_list' => [
                ['name' => 'Spaghetti', 'amount' => '250 g', 'category' => 'Nudeln', 'estimated_price' => 0.99, 'on_offer' => false],
                ['name' => 'Tomaten', 'amount' => '400 g', 'category' => 'Konserven', 'estimated_price' => 1.2, 'on_offer' => true],
            ],
        ];
    }

    public function test_preferences_are_saved(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('recipes.planner')
            ->set('people', 4)
            ->set('daysPerWeek', 6)
            ->set('budget', '75')
            ->set('postalCode', '10115')
            ->set('diet', 'vegetarian')
            ->set('mealPrep', true)
            ->set('meals', ['lunch', 'dinner'])
            ->set('allergens', ['nuts', 'lactose'])
            ->set('supermarketsText', 'Rewe, Aldi')
            ->set('dislikedText', 'Koriander , Oliven')
            ->call('savePreferences')
            ->assertHasNoErrors();

        $preference = MealPlanPreference::where('user_id', $user->id)->sole();
        $this->assertSame(4, $preference->people);
        $this->assertSame(6, $preference->days_per_week);
        $this->assertSame('75.00', (string) $preference->weekly_budget);
        $this->assertTrue($preference->meal_prep);
        $this->assertSame(['lunch', 'dinner'], $preference->meals);
        $this->assertSame(['nuts', 'lactose'], $preference->allergens);
        $this->assertSame(['Rewe', 'Aldi'], $preference->supermarkets);
        $this->assertSame(['Koriander', 'Oliven'], $preference->disliked_ingredients);
    }

    public function test_generated_plan_can_be_saved_as_recipes_plan_entries_and_a_shopping_list(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->mock(MealPlanAi::class, fn (MockInterface $ai) => $ai->shouldReceive('generate')->once()->andReturn($this->aiResult()));

        $component = Volt::test('recipes.planner');
        $component->call('generate')->assertHasNoErrors();
        $this->assertSame('Rewe', $component->get('result')['supermarket']);

        // Save recipes + add to the weekly plan.
        $component->call('applyPlan', true);

        $this->assertSame(2, Recipe::where('household_id', $household->id)->count());
        $pasta = Recipe::where('title', 'Pasta Pomodoro')->sole();
        $this->assertSame('ai', $pasta->source_type);
        $this->assertCount(2, $pasta->ingredients);
        $this->assertSame('Spaghetti', $pasta->ingredients[0]['name']);

        $entries = MealPlan::orderBy('date')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(['2026-09-21', 'dinner', 'Pasta Pomodoro'], [$entries[0]->date->toDateString(), $entries[0]->meal_type, $entries[0]->title]);
        $this->assertNotNull($entries[0]->recipe_id);
        $this->assertSame('2026-09-22', $entries[1]->date->toDateString());

        // Build a shopping list from the consolidated ingredients.
        $component->call('createList');
        $this->assertSame(1, ShoppingList::where('household_id', $household->id)->count());
        $this->assertSame(2, ShoppingListEntry::count());
    }

    public function test_plan_can_be_added_without_saving_recipes(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->mock(MealPlanAi::class, fn (MockInterface $ai) => $ai->shouldReceive('generate')->andReturn($this->aiResult()));

        Volt::test('recipes.planner')->call('generate')->call('applyPlan', false);

        $this->assertSame(0, Recipe::where('household_id', $household->id)->count());
        $this->assertSame(2, MealPlan::count());
        $this->assertNull(MealPlan::orderBy('date')->first()->recipe_id);
    }
}
