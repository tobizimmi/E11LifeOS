<?php

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;
use ZipArchive;

class RecipeFileImportExportTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_single_schema_org_json_file_is_imported_and_opens_the_recipe(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $json = json_encode([
            '@context' => 'https://schema.org', '@type' => 'Recipe', 'name' => 'Pfannkuchen',
            'recipeYield' => '4', 'prepTime' => 'PT15M', 'cookTime' => 'PT10M',
            'recipeIngredient' => ['250 g Mehl', '3 Eier'],
            'recipeInstructions' => [['@type' => 'HowToStep', 'text' => 'Verrühren.'], ['@type' => 'HowToStep', 'text' => 'Braten.']],
            'recipeCategory' => 'Süßes', 'keywords' => 'schnell, süß',
            'nutrition' => ['@type' => 'NutritionInformation', 'calories' => '320 kcal', 'proteinContent' => '9 g'],
        ]);

        Volt::test('recipes.import')
            ->set('source', 'file')
            ->set('recipeFile', UploadedFile::fake()->createWithContent('pfannkuchen.json', $json))
            ->call('import')
            ->assertHasNoErrors()
            ->assertRedirect();

        $recipe = Recipe::sole();
        $this->assertSame('Pfannkuchen', $recipe->title);
        $this->assertSame(15, $recipe->prep_time);
        $this->assertSame(10, $recipe->cook_time);
        $this->assertSame(4, $recipe->servings);
        $this->assertSame('FILE', $recipe->source_type);
        $this->assertSame('250', $recipe->ingredients[0]['amount']);
        $this->assertSame('Mehl', $recipe->ingredients[0]['name']);
        $this->assertSame('Verrühren.', $recipe->instructions[0]['text']);
        $this->assertEquals(320, $recipe->nutrition['calories']);
        $this->assertEquals(9, $recipe->nutrition['protein']);
        $this->assertEqualsCanonicalizing(['Süßes'], $recipe->categories->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['schnell', 'süß'], $recipe->tags->pluck('name')->all());
    }

    public function test_a_json_list_imports_several_recipes_at_once(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $json = json_encode([
            ['@type' => 'Recipe', 'name' => 'Erstes', 'recipeIngredient' => ['1 Ei'], 'recipeInstructions' => 'Kochen.'],
            ['@type' => 'Recipe', 'name' => 'Zweites', 'recipeIngredient' => ['2 Eier'], 'recipeInstructions' => 'Braten.'],
        ]);

        Volt::test('recipes.import')
            ->set('source', 'file')
            ->set('recipeFile', UploadedFile::fake()->createWithContent('rezepte.json', $json))
            ->call('import')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSet('imported', '2 Rezepte importiert');

        $this->assertEqualsCanonicalizing(['Erstes', 'Zweites'], Recipe::pluck('title')->all());
    }

    public function test_a_paprika_archive_with_a_photo_and_sections_is_imported(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $paprika = [
            'name' => 'Lasagne', 'servings' => '6', 'cook_time' => '45', 'source_url' => 'https://example.com/l',
            'ingredients' => "Für die Soße:\n500 g Hackfleisch\n1 Dose Tomaten\nFür den Rest:\n250 g Nudeln",
            'directions' => "Soße anbraten.\nSchichten.\nBacken.",
            'categories' => ['Hauptgericht', 'Italienisch'],
            'photo_data' => base64_encode($this->jpegBytes()),
        ];

        $path = tempnam(sys_get_temp_dir(), 'pap');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('Lasagne.paprikarecipe', gzencode((string) json_encode($paprika)));
        $zip->close();

        Volt::test('recipes.import')
            ->set('source', 'file')
            ->set('recipeFile', UploadedFile::fake()->createWithContent('export.paprikarecipes', (string) file_get_contents($path)))
            ->call('import')
            ->assertHasNoErrors()
            ->assertRedirect();

        $recipe = Recipe::sole();
        $this->assertSame('Lasagne', $recipe->title);
        $this->assertSame(6, $recipe->servings);
        $this->assertSame(45, $recipe->cook_time);
        $this->assertTrue($recipe->ingredients[0]['isSection']);
        $this->assertSame('Für die Soße', $recipe->ingredients[0]['name']);
        $this->assertSame('Hackfleisch', $recipe->ingredients[1]['name']);
        $this->assertSame('500', $recipe->ingredients[1]['amount']);
        $this->assertCount(3, $recipe->instructions);
        $this->assertNotNull($recipe->image_path);
        $this->assertTrue(Storage::disk('local')->exists($recipe->image_path));
        $this->assertEqualsCanonicalizing(['Hauptgericht', 'Italienisch'], $recipe->categories->pluck('name')->all());
    }

    private function jpegBytes(): string
    {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    public function test_an_unreadable_file_shows_a_friendly_error(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('recipes.import')
            ->set('source', 'file')
            ->set('recipeFile', UploadedFile::fake()->createWithContent('bad.json', 'not json at all'))
            ->call('import')
            ->assertSet('failure', fn ($value) => filled($value))
            ->assertNoRedirect();

        $this->assertSame(0, Recipe::count());
    }

    public function test_recipes_are_exported_as_a_zip_of_recipe_files(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        Storage::disk('local')->put('recipes/p.jpg', 'jpeg-bytes');
        Recipe::factory()->for($household)->create(['title' => 'Grüne Soße', 'image_path' => 'recipes/p.jpg']);
        Recipe::factory()->for($household)->create(['title' => 'Linsensuppe']);

        $response = $this->actingAs($user)->get(route('recipes.archive'))->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));

        $path = tempnam(sys_get_temp_dir(), 'dl');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertContains('recipes/gruene-sosse.json', $names);
        $this->assertContains('recipes/linsensuppe.json', $names);
        $this->assertContains('images/gruene-sosse.jpg', $names);

        $data = json_decode((string) $zip->getFromName('recipes/gruene-sosse.json'), true);
        $this->assertSame('Grüne Soße', $data['name']);
        $this->assertSame('images/gruene-sosse.jpg', $data['image']);
        $zip->close();
    }
}
