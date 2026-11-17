<?php

namespace Tests\Feature;

use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\User;
use App\Support\AiException;
use App\Support\MealPlanAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * The AI meal planner for the mobile app.
 */
class ApiMealPlannerTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiResult(): array
    {
        return [
            'supermarket' => 'Rewe',
            'estimated_total' => 42.5,
            'within_budget' => true,
            'notes' => 'Günstig geplant.',
            'recipes' => [
                ['day' => 1, 'meal_type' => 'dinner', 'title' => 'Pasta Pomodoro', 'servings' => 2, 'prep_time' => 10, 'cook_time' => 15, 'ingredients' => [['name' => 'Spaghetti', 'amount' => '250 g']], 'instructions' => ['Nudeln kochen']],
                ['day' => 2, 'meal_type' => 'dinner', 'title' => 'Gemüsecurry', 'servings' => 2, 'ingredients' => [['name' => 'Reis', 'amount' => '200 g']], 'instructions' => ['Reis kochen']],
            ],
            'shopping_list' => [['name' => 'Spaghetti', 'amount' => '250 g', 'category' => 'Nudeln', 'estimated_price' => 0.99, 'on_offer' => false]],
        ];
    }

    public function test_preferences_generate_add_and_shopping_list(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('recipes', true);
        $this->mock(MealPlanAi::class, fn (MockInterface $ai) => $ai->shouldReceive('generate')->once()->andReturn($this->aiResult()));
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/meal-planner', $headers)->assertOk()->assertJsonPath('data.preferences.people', 2);
        $this->putJson('/api/v1/meal-planner/preferences', ['people' => 4, 'allergens' => ['nuts']], $headers)->assertOk()->assertJsonPath('data.people', 4);

        $generated = $this->postJson('/api/v1/meal-planner/generate', ['weekly_budget' => 60, 'offers' => 'Tomaten 0,99'], $headers)
            ->assertOk()->assertJsonPath('data.recipes.1.title', 'Gemüsecurry')->json('data');
        $this->getJson('/api/v1/meal-planner', $headers)->assertJsonPath('data.preferences.weekly_budget', 60)->assertJsonPath('data.preferences.allergens.0', 'nuts');

        $this->postJson('/api/v1/meal-planner/add', ['token' => $generated['token'], 'week' => '2026-11-16', 'keep' => [0]], $headers)
            ->assertOk()->assertJsonPath('data.added', 2)->assertJsonPath('data.kept', 1);
        $this->assertSame(2, MealPlan::count());
        $this->assertSame(1, Recipe::whereNull('expires_at')->count());

        // Same week again: the duplicates are reported first, nothing is inserted.
        $this->postJson('/api/v1/meal-planner/add', ['token' => $generated['token'], 'week' => '2026-11-16'], $headers)
            ->assertOk()->assertJsonPath('data.added', 0)->assertJsonCount(2, 'data.duplicates');
        $this->assertSame(2, MealPlan::count());
        $this->postJson('/api/v1/meal-planner/add', ['token' => $generated['token'], 'week' => '2026-11-16', 'confirm_duplicates' => [1]], $headers)
            ->assertOk()->assertJsonPath('data.added', 1);

        $this->postJson('/api/v1/meal-planner/shopping-list', ['token' => $generated['token'], 'week' => '2026-11-16'], $headers)->assertCreated();
        $this->assertSame(1, ShoppingList::count());
    }

    public function test_ai_failures_and_expired_plans_are_reported(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('recipes', true);
        $this->mock(MealPlanAi::class, fn (MockInterface $ai) => $ai->shouldReceive('generate')->andThrow(new AiException('KI nicht erreichbar')));
        $headers = $this->headersFor($user);

        $this->postJson('/api/v1/meal-planner/generate', [], $headers)->assertUnprocessable()->assertJsonValidationErrors('ai');
        $this->postJson('/api/v1/meal-planner/add', ['token' => 'abgelaufen'], $headers)->assertNotFound();
        $this->putJson('/api/v1/meal-planner/preferences', ['people' => 99], $headers)->assertUnprocessable();
    }
}
