<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Recipes API (read-only, gated by the "recipes" addon): browse and open recipes.
 */
class ApiRecipesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');
    }

    public function test_recipes_are_listed_and_shown(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('recipes', true);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $recipe = Recipe::factory()->create([
            'household_id' => $household->id,
            'created_by' => $user->id,
            'title' => 'Pfannkuchen',
            'ingredients' => [['name' => 'Mehl', 'amount' => '250 g']],
            'instructions' => ['Teig rühren', 'Backen'],
        ]);

        $this->getJson('/api/v1/recipes', $headers)->assertOk()->assertJsonPath('data.0.title', 'Pfannkuchen');

        $this->getJson("/api/v1/recipes/{$recipe->id}", $headers)->assertOk()
            ->assertJsonPath('data.title', 'Pfannkuchen')
            ->assertJsonCount(2, 'data.instructions');
    }

    public function test_recipes_of_another_household_are_hidden(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $household->setAddon('recipes', true);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        Recipe::factory()->create(['household_id' => $otherHousehold->id, 'created_by' => $other->id, 'title' => 'Fremd']);

        $this->getJson('/api/v1/recipes', $headers)->assertOk()->assertJsonCount(0, 'data');
    }
}
