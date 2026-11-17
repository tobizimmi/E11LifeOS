<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Recipe categories for the mobile app.
 */
class ApiRecipeCategoryTest extends TestCase
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

    public function test_categories_can_be_managed_and_assigned_to_recipes(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('recipes', true);
        $headers = $this->headersFor($user);

        $first = $this->postJson('/api/v1/recipe-categories', ['name' => 'Desserts', 'icon' => '🍰'], $headers)->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/recipe-categories', ['name' => 'Suppen'], $headers)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/recipe-categories', ['name' => 'Desserts'], $headers)->assertUnprocessable();

        $this->putJson("/api/v1/recipe-categories/{$second}", ['name' => 'Eintöpfe'], $headers)->assertOk()->assertJsonPath('data.name', 'Eintöpfe');
        $this->postJson("/api/v1/recipe-categories/{$second}/move", ['direction' => -1], $headers)->assertOk();
        $this->getJson('/api/v1/recipe-categories', $headers)->assertOk()->assertJsonPath('data.0.name', 'Eintöpfe')->assertJsonPath('data.1.icon', '🍰');

        $recipeId = $this->postJson('/api/v1/recipes', ['title' => 'Tiramisu', 'category_ids' => [$first]], $headers)->assertCreated()->assertJsonPath('data.categories.0', 'Desserts')->json('data.id');
        $this->getJson('/api/v1/recipe-categories', $headers)->assertJsonPath('data.1.recipes', 1);

        $this->deleteJson("/api/v1/recipe-categories/{$first}", [], $headers)->assertOk();
        $this->assertSame(1, Recipe::where('id', $recipeId)->count());
        $this->assertSame(1, RecipeCategory::count());
    }

    public function test_foreign_categories_are_not_reachable(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('recipes', true);
        $foreign = RecipeCategory::factory()->create();

        $headers = $this->headersFor($user);
        $this->putJson("/api/v1/recipe-categories/{$foreign->id}", ['name' => 'X'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/recipe-categories/{$foreign->id}", [], $headers)->assertNotFound();

        $id = $this->postJson('/api/v1/recipes', ['title' => 'A', 'category_ids' => [$foreign->id]], $headers)->assertCreated()->assertJsonPath('data.categories', [])->json('data.id');
        $this->assertNotNull($id);
    }
}
