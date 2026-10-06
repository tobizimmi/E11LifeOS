<?php

namespace Tests\Feature;

use App\Models\MealPlan;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;
use ZipArchive;

class RecipeBulkAndPlanPdfTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_the_zip_export_can_be_limited_to_selected_recipes(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $one = Recipe::factory()->for($household)->create(['title' => 'Erstes']);
        $two = Recipe::factory()->for($household)->create(['title' => 'Zweites']);
        Recipe::factory()->for($household)->create(['title' => 'Drittes']);

        $response = $this->actingAs($user)->get(route('recipes.archive', ['ids' => $one->id.','.$two->id]))->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'dl');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertContains('recipes/erstes.json', $names);
        $this->assertContains('recipes/zweites.json', $names);
        $this->assertNotContains('recipes/drittes.json', $names);
    }

    public function test_the_cookbook_pdf_can_be_limited_to_selected_recipes(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Auswahl']);
        Recipe::factory()->for($household)->create(['title' => 'Andere']);

        $response = $this->actingAs($user)->get(route('recipes.cookbook', ['ids' => (string) $recipe->id]))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_week_meal_plan_is_downloadable_as_pdf(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Linsensuppe']);
        MealPlan::factory()->for($household)->create([
            'created_by' => $user->id, 'date' => today()->startOfWeek(), 'meal_type' => 'dinner', 'recipe_id' => $recipe->id,
        ]);

        $response = $this->actingAs($user)->get(route('recipes.plan.pdf'))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('wochenplan-', (string) $response->headers->get('Content-Disposition'));
    }
}
