<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Support\RecipeSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class RecipeExportTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_recipe_is_exported_as_schema_org_json_for_members_only(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [$stranger] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create([
            'title' => 'Grüne Soße', 'prep_time' => 20, 'cook_time' => 90, 'servings' => 4,
            'ingredients' => [['name' => 'Kräuter', 'isSection' => true], ['amount' => '250', 'unit' => 'g', 'name' => 'Schmand']],
            'instructions' => [['text' => 'Kräuter hacken.'], ['text' => 'Verrühren.']],
            'nutrition' => ['calories' => 300, 'protein' => 9],
        ]);
        $recipe->categories()->attach(RecipeCategory::create(['household_id' => $household->id, 'name' => 'Beilagen']));

        $response = $this->actingAs($user)->get(route('recipes.export', $recipe))->assertOk();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('gruene-sosse.json', (string) $response->headers->get('Content-Disposition'));

        $data = $response->json();
        $this->assertSame('Recipe', $data['@type']);
        $this->assertSame('Grüne Soße', $data['name']);
        $this->assertSame('PT1H30M', $data['cookTime']);
        $this->assertSame('PT20M', $data['prepTime']);
        $this->assertSame('4', $data['recipeYield']);
        $this->assertSame('Beilagen', $data['recipeCategory']);
        $this->assertContains('# Kräuter', $data['recipeIngredient']);
        $this->assertContains('250 g Schmand', $data['recipeIngredient']);
        $this->assertSame('Kräuter hacken.', $data['recipeInstructions'][0]['text']);
        $this->assertSame('NutritionInformation', $data['nutrition']['@type']);
        $this->assertSame('300 kcal', $data['nutrition']['calories']);

        $this->actingAs($stranger)->get(route('recipes.export', $recipe))->assertNotFound();
    }

    public function test_a_recipe_is_exported_as_markdown(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create([
            'title' => 'Linsensuppe', 'servings' => 2,
            'ingredients' => [['amount' => '250', 'unit' => 'g', 'name' => 'Linsen']],
            'instructions' => [['text' => 'Kochen.']],
        ]);

        $response = $this->actingAs($user)->get(route('recipes.export', ['recipe' => $recipe, 'format' => 'md']))->assertOk();
        $this->assertStringContainsString('text/markdown', (string) $response->headers->get('Content-Type'));
        $body = $response->getContent();
        $this->assertStringContainsString('# Linsensuppe', $body);
        $this->assertStringContainsString('- 250 g Linsen', $body);
        $this->assertStringContainsString('1. Kochen.', $body);
    }

    public function test_the_public_page_embeds_schema_org_json_ld(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create([
            'title' => 'Linsensuppe', 'public_token' => Str::random(40),
            'ingredients' => [['amount' => '250', 'unit' => 'g', 'name' => 'Linsen']],
        ]);

        $response = $this->get(route('recipes.public', $recipe->public_token))->assertOk();
        $response->assertSee('application/ld+json', false);
        $response->assertSee('"@type":"Recipe"', false);
        $response->assertSee('250 g Linsen', false);
    }

    public function test_the_schema_uses_iso_durations_and_skips_empty_values(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create([
            'title' => 'Schnell', 'prep_time' => 5, 'cook_time' => null, 'servings' => null, 'description' => '',
            'nutrition' => null,
        ]);
        $recipe->load('categories', 'tags');

        $schema = RecipeSchema::toArray($recipe);
        $this->assertSame('PT5M', $schema['prepTime']);
        $this->assertArrayNotHasKey('cookTime', $schema);
        $this->assertArrayNotHasKey('recipeYield', $schema);
        $this->assertArrayNotHasKey('description', $schema);
        $this->assertArrayNotHasKey('nutrition', $schema);
    }
}
