<?php

namespace Tests\Feature;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\ShoppingListEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for the pantry, the meal plan and the habits.
 */
class ApiKitchenAndHabitsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_pantry_entries_are_added_adjusted_and_put_on_the_shopping_list(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/pantry', ['name' => 'Nudeln', 'kind' => 'food', 'quantity' => 2, 'unit' => 'Pck', 'minimum' => 2], $headers)
            ->assertCreated()->assertJsonPath('data.name', 'Nudeln')->assertJsonPath('data.low', true)->json('data.id');

        // The same name again adds to the existing entry.
        $this->postJson('/api/v1/pantry', ['name' => 'Nudeln', 'kind' => 'food', 'quantity' => 3], $headers)->assertCreated()->assertJsonPath('data.id', $id);

        $data = $this->getJson('/api/v1/pantry', $headers)->assertOk()->json('data');
        $this->assertCount(1, $data['items']);
        $this->assertEquals(5, $data['items'][0]['quantity']);
        $this->assertFalse($data['items'][0]['low']);
        $this->assertContains('supplies', array_column($data['kinds'], 'key'));

        $this->postJson("/api/v1/pantry/{$id}/adjust", ['delta' => -1], $headers)->assertOk()->assertJsonPath('data.quantity', 4);
        $this->postJson("/api/v1/pantry/{$id}/adjust", ['delta' => 5], $headers)->assertStatus(422);

        $this->postJson('/api/v1/pantry/shopping', ['item' => $id], $headers)->assertOk()->assertJsonPath('data.added', 1);
        $this->assertTrue(ShoppingListEntry::where('label', 'Nudeln')->exists());
        $this->postJson('/api/v1/pantry/shopping', [], $headers)->assertOk()->assertJsonPath('data.added', 0);
        $this->postJson('/api/v1/pantry/shopping', ['item' => 999999], $headers)->assertNotFound();

        $this->deleteJson("/api/v1/pantry/{$id}", [], $headers)->assertOk();
        $this->getJson('/api/v1/pantry', $headers)->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_pantry_entries_of_another_household_are_out_of_reach(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other] = $this->memberWithHousehold();

        $foreignId = $this->postJson('/api/v1/pantry', ['name' => 'Reis', 'kind' => 'food', 'quantity' => 1], $this->headersFor($other))->assertCreated()->json('data.id');

        $headers = $this->headersFor($user);
        $this->getJson('/api/v1/pantry', $headers)->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson("/api/v1/pantry/{$foreignId}/adjust", ['delta' => 1], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/pantry/{$foreignId}", [], $headers)->assertNotFound();
        $this->assertNotNull(Item::find($foreignId));
    }

    public function test_meals_are_planned_moved_and_their_ingredients_put_on_the_shopping_list(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('recipes', true);
        $headers = $this->headersFor($user);
        $recipe = Recipe::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'title' => 'Pfannkuchen', 'servings' => 2]);
        $monday = today()->startOfWeek()->toDateString();
        $tuesday = today()->startOfWeek()->addDay()->toDateString();

        $id = $this->postJson('/api/v1/meal-plan', ['date' => $monday, 'meal_type' => 'dinner', 'recipe_id' => $recipe->id, 'servings' => 4], $headers)
            ->assertCreated()->assertJsonPath('data.title', 'Pfannkuchen')->assertJsonPath('data.recipe_id', $recipe->id)->assertJsonPath('data.servings', 4)->json('data.id');
        $customId = $this->postJson('/api/v1/meal-plan', ['date' => $monday, 'meal_type' => 'lunch', 'title' => ' Reste '], $headers)->assertCreated()->assertJsonPath('data.title', 'Reste')->assertJsonPath('data.recipe_id', null)->json('data.id');
        $this->postJson('/api/v1/meal-plan', ['date' => $monday, 'meal_type' => 'dinner'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/meal-plan', ['date' => $monday, 'meal_type' => 'brunch', 'title' => 'X'], $headers)->assertStatus(422);

        $week = $this->getJson('/api/v1/meal-plan', $headers)->assertOk()->json('data');
        $this->assertSame($monday, $week['from']);
        $this->assertCount(2, $week['entries']);
        $this->assertCount(4, $week['meal_types']);

        $this->putJson("/api/v1/meal-plan/{$id}", ['date' => $tuesday, 'title' => 'Ignoriert'], $headers)->assertOk()->assertJsonPath('data.date', $tuesday)->assertJsonPath('data.title', 'Pfannkuchen');
        $this->putJson("/api/v1/meal-plan/{$customId}", ['title' => 'Auswärts'], $headers)->assertOk()->assertJsonPath('data.title', 'Auswärts');

        // 4 servings of a 2-serving recipe: amounts are doubled.
        $ingredients = $this->getJson('/api/v1/meal-plan/ingredients', $headers)->assertOk()->json('data');
        $this->assertCount(2, $ingredients);
        $flour = collect($ingredients)->firstWhere('name', 'Mehl');
        $this->assertStringContainsString('400', $flour['quantity']);

        $this->postJson('/api/v1/meal-plan/shopping', ['indexes' => [0]], $headers)->assertOk()->assertJsonPath('data.added', 1);
        $this->assertSame(1, ShoppingListEntry::count());
        $this->postJson('/api/v1/meal-plan/shopping', [], $headers)->assertOk()->assertJsonPath('data.added', 2);

        $this->deleteJson("/api/v1/meal-plan/{$id}", [], $headers)->assertOk();
        $this->assertNull(MealPlan::find($id));
    }

    public function test_the_meal_plan_is_scoped_to_the_household_and_the_recipes_addon(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('recipes', true);
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $otherHousehold->setAddon('recipes', true);
        $headers = $this->headersFor($user);

        $foreignRecipe = Recipe::factory()->create(['household_id' => $otherHousehold->id, 'created_by' => $other->id]);
        $foreign = MealPlan::create(['household_id' => $otherHousehold->id, 'created_by' => $other->id, 'date' => today()->toDateString(), 'meal_type' => 'dinner', 'title' => 'Fremd']);

        $this->getJson('/api/v1/meal-plan?from='.today()->toDateString(), $headers)->assertOk()->assertJsonCount(0, 'data.entries');
        $this->postJson('/api/v1/meal-plan', ['date' => today()->toDateString(), 'meal_type' => 'dinner', 'recipe_id' => $foreignRecipe->id], $headers)->assertNotFound();
        $this->putJson("/api/v1/meal-plan/{$foreign->id}", ['meal_type' => 'lunch'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/meal-plan/{$foreign->id}", [], $headers)->assertNotFound();

        // With the recipes add-on switched off the meal plan is locked like the recipes themselves.
        $household->setAddon('recipes', false);
        $this->assertNotSame(200, $this->getJson('/api/v1/meal-plan', $headers)->status());
    }

    public function test_habits_are_created_ticked_counted_and_archived(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $checkId = $this->postJson('/api/v1/habits', ['name' => 'Lesen', 'icon' => '📖', 'type' => 'check', 'schedule' => 'daily'], $headers)
            ->assertCreated()->assertJsonPath('data.done', false)->assertJsonPath('data.target', 1)->json('data.id');
        $countId = $this->postJson('/api/v1/habits', ['name' => 'Wasser', 'type' => 'count', 'target' => 2, 'unit' => 'Gläser', 'schedule' => 'daily'], $headers)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/habits', ['name' => 'X', 'type' => 'count', 'schedule' => 'daily'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/habits', ['name' => 'X', 'type' => 'check', 'schedule' => 'weekdays'], $headers)->assertStatus(422);

        $this->postJson("/api/v1/habits/{$checkId}/tap", [], $headers)->assertOk()->assertJsonPath('data.done', true)->assertJsonPath('data.value', 1)->assertJsonPath('data.streak', 1);
        $this->postJson("/api/v1/habits/{$checkId}/tap", [], $headers)->assertOk()->assertJsonPath('data.done', false);

        $this->postJson("/api/v1/habits/{$countId}/tap", ['step' => 1], $headers)->assertOk()->assertJsonPath('data.value', 1)->assertJsonPath('data.done', false);
        $this->postJson("/api/v1/habits/{$countId}/tap", ['step' => 1], $headers)->assertOk()->assertJsonPath('data.value', 2)->assertJsonPath('data.done', true);
        $this->postJson("/api/v1/habits/{$countId}/tap", ['step' => -1], $headers)->assertOk()->assertJsonPath('data.value', 1);

        // Yesterday can be filled in later; the day after tomorrow cannot.
        $yesterday = today()->subDay()->toDateString();
        $this->postJson("/api/v1/habits/{$checkId}/tap", ['date' => $yesterday], $headers)->assertOk()->assertJsonPath('data.done', true);
        $this->assertTrue(HabitLog::where('habit_id', $checkId)->whereDate('date', $yesterday)->exists());
        $this->postJson("/api/v1/habits/{$checkId}/tap", ['date' => today()->addDays(3)->toDateString()], $headers)->assertStatus(422);

        $list = $this->getJson('/api/v1/habits', $headers)->assertOk()->json('data');
        $this->assertCount(2, $list['habits']);
        $this->assertCount(7, $list['habits'][0]['week']);

        $this->putJson("/api/v1/habits/{$checkId}", ['name' => 'Lesen 20 min', 'type' => 'check', 'schedule' => 'weekly', 'per_week' => 3], $headers)->assertOk()->assertJsonPath('data.per_week', 3);
        $this->postJson("/api/v1/habits/{$checkId}/archive", [], $headers)->assertOk()->assertJsonPath('data.archived', true);
        $this->getJson('/api/v1/habits', $headers)->assertOk()->assertJsonCount(1, 'data.habits');

        $this->deleteJson("/api/v1/habits/{$countId}", [], $headers)->assertOk();
        $this->assertNull(Habit::find($countId));
    }

    public function test_habits_are_private_to_the_member(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $foreign = Habit::factory()->create(['user_id' => $partner->id]);
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/habits', $headers)->assertOk()->assertJsonCount(0, 'data.habits');
        $this->postJson("/api/v1/habits/{$foreign->id}/tap", [], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/habits/{$foreign->id}", [], $headers)->assertNotFound();
    }
}
