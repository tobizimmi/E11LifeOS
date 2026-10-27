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

    public function test_recipes_can_be_created_updated_and_deleted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('recipes', true);
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $id = $this->postJson('/api/v1/recipes', [
            'title' => 'Chili',
            'servings' => 4,
            'difficulty' => 'easy',
            'ingredients' => ['Bohnen', 'Hackfleisch'],
            'instructions' => ['Anbraten', 'Köcheln'],
        ], $headers)->assertCreated()
            ->assertJsonPath('data.title', 'Chili')
            ->assertJsonPath('data.servings', 4)
            ->assertJsonCount(2, 'data.ingredients')
            ->json('data.id');

        $this->assertDatabaseHas('recipes', ['id' => $id, 'household_id' => $household->id, 'created_by' => $user->id]);

        // An invalid difficulty is rejected.
        $this->postJson('/api/v1/recipes', ['title' => 'X', 'difficulty' => 'extreme'], $headers)->assertStatus(422);

        // Partial update keeps other fields.
        $this->putJson("/api/v1/recipes/{$id}", [
            'title' => 'Chili con Carne',
            'instructions' => ['Anbraten', 'Köcheln', 'Servieren'],
        ], $headers)->assertOk()
            ->assertJsonPath('data.title', 'Chili con Carne')
            ->assertJsonPath('data.servings', 4)
            ->assertJsonCount(3, 'data.instructions');

        // Deletion removes it.
        $this->deleteJson("/api/v1/recipes/{$id}", [], $headers)->assertOk();
        $this->assertDatabaseMissing('recipes', ['id' => $id]);

        // Another household's recipe cannot be edited or deleted.
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $foreign = Recipe::factory()->create(['household_id' => $otherHousehold->id, 'created_by' => $other->id, 'title' => 'Fremd']);
        $this->putJson("/api/v1/recipes/{$foreign->id}", ['title' => 'Hack'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/recipes/{$foreign->id}", [], $headers)->assertNotFound();
    }
}
