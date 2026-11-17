<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use App\Support\RecipeAi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Recipe import for the mobile app.
 */
class ApiRecipeImportTest extends TestCase
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

    public function test_a_link_is_imported_as_a_recipe(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('recipes', true);
        $this->mock(RecipeAi::class, fn (MockInterface $ai) => $ai->shouldNotReceive('extractFromText'));
        $jsonLd = json_encode(['@context' => 'https://schema.org', '@type' => 'Recipe', 'name' => 'Käsespätzle', 'recipeYield' => '4', 'recipeIngredient' => ['500 g Mehl', '5 Eier'], 'recipeInstructions' => [['@type' => 'HowToStep', 'text' => 'Teig rühren.']]]);
        Http::fake(['rezepte.example/*' => Http::response('<html><head><script type="application/ld+json">'.$jsonLd.'</script></head><body></body></html>')]);

        $headers = $this->headersFor($user);
        $this->postJson('/api/v1/recipes/import', ['source' => 'link', 'url' => 'https://rezepte.example/kaese'], $headers)->assertCreated()->assertJsonPath('data.count', 1);

        $recipe = Recipe::where('household_id', $household->id)->firstOrFail();
        $this->assertSame('Käsespätzle', $recipe->title);
        $this->assertSame(4, $recipe->servings);
        $this->getJson('/api/v1/recipes', $headers)->assertOk()->assertJsonPath('data.0.title', 'Käsespätzle');
    }

    public function test_bad_input_and_failures_are_reported(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('recipes', true);
        $headers = $this->headersFor($user);

        $this->postJson('/api/v1/recipes/import', ['source' => 'link', 'url' => 'nope'], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/recipes/import', ['source' => 'text', 'text' => 'kurz'], $headers)->assertUnprocessable();

        Http::fake(['kaputt.example/*' => Http::response('', 500)]);
        $this->postJson('/api/v1/recipes/import', ['source' => 'link', 'url' => 'https://kaputt.example/x'], $headers)->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->assertSame(0, Recipe::count());
    }
}
