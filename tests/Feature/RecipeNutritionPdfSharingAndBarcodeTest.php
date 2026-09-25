<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeRating;
use App\Support\Addons;
use App\Support\RecipeAi;
use App\Support\RecipeAiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class RecipeNutritionPdfSharingAndBarcodeTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_nutrition_can_be_estimated_by_the_ai_and_edited_by_hand(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        [$user, $household] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create(['created_by' => $user->id]);
        $this->mock(RecipeAi::class, fn (MockInterface $ai) => $ai->shouldReceive('estimateNutrition')->once()
            ->andReturn(['calories' => 420, 'protein' => 12, 'carbs' => 60, 'fat' => 14, 'fiber' => 3]));
        $this->actingAs($user);

        Volt::test('recipes.show', ['recipe' => $recipe->id])
            ->assertSee('Mit KI schätzen')
            ->call('estimateNutrition')
            ->assertSee('Nährwerte pro Portion')
            ->assertSee('geschätzt')
            ->assertSee('420');
        $this->assertSame(['calories' => 420, 'protein' => 12, 'carbs' => 60, 'fat' => 14, 'fiber' => 3, 'estimated' => true], $recipe->fresh()->nutrition);

        // Saving the form unchanged keeps the estimate; a manual change replaces it.
        Volt::test('recipes.form', ['recipe' => $recipe->id])->assertSet('nutrition.calories', 420)->call('save');
        $this->assertTrue($recipe->fresh()->nutrition['estimated']);

        Volt::test('recipes.form', ['recipe' => $recipe->id])->set('nutrition.calories', 380)->set('nutrition.fiber', null)->call('save');
        $this->assertSame(['calories' => 380, 'protein' => 12, 'carbs' => 60, 'fat' => 14, 'fiber' => null], $recipe->fresh()->nutrition);
        $this->assertSame(['calories' => 380.0, 'protein' => 12.0, 'carbs' => 60.0, 'fat' => 14.0], $recipe->fresh()->nutritionValues());

        Volt::test('recipes.form', ['recipe' => $recipe->id])->set('nutrition', ['calories' => null, 'protein' => null, 'carbs' => null, 'fat' => null, 'fiber' => null])->call('save');
        $this->assertNull($recipe->fresh()->nutrition);
    }

    public function test_a_failed_estimate_shows_a_message_and_without_api_key_there_is_no_button(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        [$user, $household] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create();
        $this->mock(RecipeAi::class, fn (MockInterface $ai) => $ai->shouldReceive('estimateNutrition')->andThrow(new RecipeAiException('Gerade nicht erreichbar.')));
        $this->actingAs($user);

        Volt::test('recipes.show', ['recipe' => $recipe->id])->call('estimateNutrition')->assertDispatched('toast', message: 'Gerade nicht erreichbar.');
        $this->assertNull($recipe->fresh()->nutrition);

        config(['services.anthropic.key' => null]);
        Volt::test('recipes.show', ['recipe' => $recipe->id])->assertDontSee('Mit KI schätzen');
    }

    public function test_recipes_can_be_downloaded_as_pdf_by_members_only(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [$stranger] = $this->memberWithHousehold();
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image);
        Storage::disk('local')->put('recipes/p.jpg', (string) ob_get_clean());
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Grüne Soße', 'image_path' => 'recipes/p.jpg', 'nutrition' => ['calories' => 300]]);

        $response = $this->actingAs($user)->get(route('recipes.pdf', ['recipe' => $recipe, 'servings' => 4]))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('gruene-sosse.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->actingAs($stranger)->get(route('recipes.pdf', $recipe))->assertNotFound();
    }

    public function test_a_public_link_shows_the_recipe_without_login_until_it_is_turned_off(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        Storage::disk('local')->put('recipes/p.jpg', 'jpeg');
        $recipe = Recipe::factory()->for($household)->create([
            'title' => 'Linsensuppe', 'created_by' => $owner->id, 'servings' => 2, 'image_path' => 'recipes/p.jpg',
            'ingredients' => [['amount' => '250', 'unit' => 'g', 'name' => 'Linsen']],
        ]);
        RecipeRating::create(['recipe_id' => $recipe->id, 'user_id' => $owner->id, 'rating' => 5, 'comment' => 'Geheime Notiz']);

        $this->actingAs($owner);
        Volt::test('recipes.show', ['recipe' => $recipe->id])->call('createPublicLink')->assertSet('sharing', true);
        $url = $recipe->fresh()->publicUrl();
        $this->assertNotNull($url);
        $this->assertSame(40, strlen($recipe->fresh()->public_token));

        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Linsensuppe')->assertSee('250 g')->assertDontSee('Geheime Notiz')->assertSee('noindex', false);
        $this->get($url.'?servings=4')->assertSee('500 g');
        $this->get(route('recipes.public.image', $recipe->fresh()->public_token))->assertOk();
        $this->get(route('recipes.public', str_repeat('x', 40)))->assertNotFound();

        // Copies get no link of their own.
        $this->assertNull($recipe->fresh()->duplicateFor($owner)->public_token);

        // Switching the addon off stops the link, too.
        $household->setAddon(Addons::RECIPES, false);
        $this->get($url)->assertNotFound();
        $household->setAddon(Addons::RECIPES, true);

        $this->actingAs($owner);
        Volt::test('recipes.show', ['recipe' => $recipe->id])->call('revokePublicLink');
        auth()->logout();
        $this->get($url)->assertNotFound();
    }

    public function test_a_scanned_barcode_fills_the_shopping_list_form(): void
    {
        [$user] = $this->memberWithHousehold();
        Http::fake([
            'world.openfoodfacts.org/api/v2/product/4000417025005.json*' => Http::response(['status' => 1, 'product' => ['product_name' => 'Vollmilch Schokolade', 'brands' => 'Ritter Sport,Alfred Ritter', 'quantity' => '100 g']]),
            'world.openfoodfacts.org/api/v2/product/*' => Http::response(['status' => 0], 404),
        ]);
        $this->actingAs($user);

        Volt::test('shopping-list.index')
            ->call('lookupBarcode', '4000417025005')
            ->assertSet('label', 'Ritter Sport Vollmilch Schokolade')
            ->assertSet('quantity', '100 g')
            ->call('add');
        $this->assertDatabaseHas('shopping_list_entries', ['label' => 'Ritter Sport Vollmilch Schokolade', 'quantity' => '100 g']);

        Volt::test('shopping-list.index')->call('lookupBarcode', '12345678')
            ->assertSet('label', '')
            ->assertDispatched('toast', message: 'Produkt 12345678 nicht gefunden – bitte selbst eintragen.');

        Volt::test('shopping-list.index')->call('lookupBarcode', '12ab')->assertDispatched('toast', message: 'Das ist kein gültiger Barcode.');
        Http::assertSentCount(2);
    }
}
