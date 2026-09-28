<?php

namespace Tests\Feature;

use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\RecipeRating;
use App\Support\RecipeImages;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class RecipeImportTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // A throwaway RezepteApp2 database with the columns the import reads.
        config(['database.connections.rezepteapp' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('rezepteapp');
        $schema = Schema::connection('rezepteapp');

        $schema->create('users', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('email');
        });
        $schema->create('recipes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('userId');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('ingredients')->nullable();
            $table->json('instructions')->nullable();
            $table->integer('prepTime')->nullable();
            $table->integer('cookTime')->nullable();
            $table->integer('servings')->nullable();
            $table->string('imageUrl')->nullable();
            $table->json('additionalImages')->nullable();
            $table->string('sourceUrl')->nullable();
            $table->string('sourceType')->nullable();
            $table->string('difficulty')->nullable();
            $table->json('nutrition')->nullable();
            $table->integer('cookedCount')->default(0);
            $table->timestamp('archivedAt')->nullable();
            $table->timestamp('createdAt')->nullable();
            $table->timestamp('updatedAt')->nullable();
        });
        $schema->create('categories', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('userId')->nullable();
            $table->string('name');
            $table->string('color')->nullable();
            $table->text('icon')->nullable();
            $table->integer('displayOrder')->default(0);
        });
        $schema->create('category_recipes', function (Blueprint $table) {
            $table->string('categoryId');
            $table->string('recipeId');
        });
        $schema->create('tags', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('userId');
            $table->string('name');
        });
        $schema->create('recipe_tags', function (Blueprint $table) {
            $table->string('tagId');
            $table->string('recipeId');
        });
        $schema->create('favorites', function (Blueprint $table) {
            $table->string('userId');
            $table->string('recipeId');
        });
        $schema->create('recipe_ratings', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('recipeId');
            $table->string('userId');
            $table->integer('rating');
            $table->text('comment')->nullable();
            $table->dateTime('cookedAt')->nullable();
        });
        $schema->create('meal_plans', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('userId');
            $table->string('recipeId')->nullable();
            $table->date('date');
            $table->string('mealType')->nullable();
            $table->integer('servings')->nullable();
            $table->string('customTitle')->nullable();
            $table->text('note')->nullable();
        });

        $source = DB::connection('rezepteapp');
        $source->table('users')->insert([['id' => 'u1', 'email' => 'tobi@example.com'], ['id' => 'u2', 'email' => 'other@example.com']]);
        $source->table('recipes')->insert([
            [
                'id' => 'r1', 'userId' => 'u1', 'title' => 'Lasagne', 'description' => 'Omas Rezept',
                'ingredients' => json_encode([
                    ['name' => 'Soße', 'isSection' => true],
                    ['amount' => '500', 'unit' => 'g', 'name' => 'Hackfleisch'],
                    ['amount' => '', 'unit' => '', 'name' => ''],
                ]),
                'instructions' => json_encode([['step' => 1, 'text' => 'Anbraten'], ['step' => 2, 'text' => '  ']]),
                'prepTime' => 30, 'cookTime' => 45, 'servings' => 4, 'imageUrl' => 'https://zimmimail.de/RezepteApp2/uploads/recipes/lasagne.jpg',
                'additionalImages' => json_encode(['/uploads/recipes/lasagne.jpg', '/uploads/recipes/fehlt.jpg']),
                'sourceUrl' => null, 'sourceType' => 'MANUAL', 'difficulty' => 'mittel', 'nutrition' => null, 'cookedCount' => 7,
                'archivedAt' => null, 'createdAt' => '2025-01-10 12:00:00', 'updatedAt' => '2025-02-01 12:00:00',
            ],
            [
                'id' => 'r2', 'userId' => 'u1', 'title' => 'Altes Rezept', 'description' => null, 'ingredients' => null, 'instructions' => null,
                'prepTime' => null, 'cookTime' => null, 'servings' => null, 'imageUrl' => null, 'additionalImages' => null, 'sourceUrl' => 'https://example.org/x',
                'sourceType' => 'WEB', 'difficulty' => null, 'nutrition' => null, 'cookedCount' => 0,
                'archivedAt' => '2025-03-01 00:00:00', 'createdAt' => '2025-01-01 00:00:00', 'updatedAt' => '2025-01-01 00:00:00',
            ],
            [
                'id' => 'r3', 'userId' => 'u2', 'title' => 'Fremdes Rezept', 'description' => null, 'ingredients' => null, 'instructions' => null,
                'prepTime' => null, 'cookTime' => null, 'servings' => null, 'imageUrl' => null, 'additionalImages' => null, 'sourceUrl' => null,
                'sourceType' => null, 'difficulty' => null, 'nutrition' => null, 'cookedCount' => 0,
                'archivedAt' => null, 'createdAt' => null, 'updatedAt' => null,
            ],
        ]);
        $source->table('categories')->insert([
            ['id' => 'c1', 'userId' => null, 'name' => 'Hauptgericht', 'color' => '#f00', 'icon' => '🍝', 'displayOrder' => 1],
            ['id' => 'c2', 'userId' => 'u1', 'name' => 'Lieblinge', 'color' => null, 'icon' => '/uploads/category-icons/x.png', 'displayOrder' => 2],
            ['id' => 'c3', 'userId' => 'u2', 'name' => 'Nicht meins', 'color' => null, 'icon' => null, 'displayOrder' => 3],
        ]);
        $source->table('category_recipes')->insert([['categoryId' => 'c1', 'recipeId' => 'r1'], ['categoryId' => 'c2', 'recipeId' => 'r1']]);
        $source->table('tags')->insert([['id' => 't1', 'userId' => 'u1', 'name' => 'Familie']]);
        $source->table('recipe_tags')->insert([['tagId' => 't1', 'recipeId' => 'r1']]);
        $source->table('favorites')->insert([['userId' => 'u1', 'recipeId' => 'r1']]);
        $source->table('recipe_ratings')->insert([
            ['id' => 'rt1', 'recipeId' => 'r1', 'userId' => 'u1', 'rating' => 5, 'comment' => 'Super', 'cookedAt' => '2025-02-01 18:00:00'],
            ['id' => 'rt2', 'recipeId' => 'r1', 'userId' => 'u2', 'rating' => 1, 'comment' => null, 'cookedAt' => null],
        ]);
        $source->table('meal_plans')->insert([
            ['id' => 'm1', 'userId' => 'u1', 'recipeId' => 'r1', 'date' => '2025-03-03', 'mealType' => 'lunch', 'servings' => 6, 'customTitle' => null, 'note' => null],
            ['id' => 'm2', 'userId' => 'u1', 'recipeId' => null, 'date' => '2025-03-04', 'mealType' => 'brunch', 'servings' => null, 'customTitle' => 'Reste', 'note' => 'vom Vortag'],
            ['id' => 'm3', 'userId' => 'u1', 'recipeId' => 'r3', 'date' => '2025-03-05', 'mealType' => 'dinner', 'servings' => null, 'customTitle' => null, 'note' => null],
            ['id' => 'm4', 'userId' => 'u2', 'recipeId' => 'r3', 'date' => '2025-03-05', 'mealType' => 'dinner', 'servings' => null, 'customTitle' => null, 'note' => null],
        ]);

        // Photo in the RezepteApp2 upload directory.
        $this->uploads = sys_get_temp_dir().'/rezepteapp-uploads-'.uniqid();
        mkdir($this->uploads.'/recipes', 0777, true);
        $image = imagecreatetruecolor(40, 30);
        imagejpeg($image, $this->uploads.'/recipes/lasagne.jpg');

        // No real RezepteApp2 on the test machine: only the directory above is used.
        config(['services.rezepteapp.uploads_path' => null, 'services.rezepteapp.uploads_url' => null]);
        Http::preventStrayRequests();
        Http::fake(['zimmimail.de/*' => Http::response('', 404)]);
    }

    public function test_recipes_categories_tags_favorites_and_photos_are_imported(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $user->update(['email' => 'tobi@example.com']);

        $this->artisan('recipes:import-rezepteapp', ['email' => 'tobi@example.com', '--uploads-path' => $this->uploads])
            ->assertSuccessful();

        $this->assertSame(2, Recipe::where('household_id', $household->id)->count());

        $lasagne = Recipe::where('legacy_id', 'r1')->firstOrFail();
        $this->assertSame('Lasagne', $lasagne->title);
        $this->assertEquals([['name' => 'Soße', 'isSection' => true], ['amount' => '500', 'unit' => 'g', 'name' => 'Hackfleisch']], $lasagne->ingredients);
        $this->assertSame([['text' => 'Anbraten']], $lasagne->instructions);
        $this->assertSame([30, 45, 4, 7, 'mittel'], [$lasagne->prep_time, $lasagne->cook_time, $lasagne->servings, $lasagne->cooked_count, $lasagne->difficulty]);
        $this->assertSame('2025-01-10', $lasagne->created_at->toDateString());
        $this->assertSame($user->id, $lasagne->created_by);
        $this->assertFalse($lasagne->isPrivate());
        $this->assertSame(['Hauptgericht', 'Lieblinge'], $lasagne->categories->pluck('name')->sort()->values()->all());
        $this->assertSame(['familie'], $lasagne->tags->pluck('name')->all());
        $this->assertTrue($lasagne->isFavoriteOf($user));
        $this->assertNotNull($lasagne->image_path);
        Storage::disk('local')->assertExists($lasagne->image_path);

        $this->assertCount(1, $lasagne->additional_images);
        Storage::disk('local')->assertExists($lasagne->additional_images[0]);

        $rating = RecipeRating::sole();
        $this->assertSame([$lasagne->id, $user->id, 5, 'Super', '2025-02-01'], [$rating->recipe_id, $rating->user_id, $rating->rating, $rating->comment, $rating->cooked_at->toDateString()]);

        $plans = MealPlan::where('household_id', $household->id)->orderBy('date')->get();
        $this->assertSame(
            [['2025-03-03', 'lunch', $lasagne->id, 'Lasagne', 6, null], ['2025-03-04', 'dinner', null, 'Reste', null, 'vom Vortag']],
            $plans->map(fn (MealPlan $plan) => [$plan->date->toDateString(), $plan->meal_type, $plan->recipe_id, $plan->title, $plan->servings, $plan->note])->all(),
        );

        $this->assertTrue(Recipe::where('legacy_id', 'r2')->firstOrFail()->isArchived());
        $this->assertDatabaseMissing('recipes', ['legacy_id' => 'r3']);

        $this->assertSame('🍝', RecipeCategory::where('name', 'Hauptgericht')->value('icon'));
        $this->assertNull(RecipeCategory::where('name', 'Lieblinge')->value('icon'));
        $this->assertDatabaseMissing('recipe_categories', ['name' => 'Nicht meins']);
    }

    public function test_missing_photos_of_imported_recipes_are_repaired_without_touching_the_source(): void
    {
        [$user] = $this->memberWithHousehold();
        $user->update(['email' => 'tobi@example.com']);
        $source = $this->uploads.'/recipes/lasagne.jpg';
        $checksum = md5_file($source);

        // First import without access to the photos.
        $this->artisan('recipes:import-rezepteapp', ['email' => 'tobi@example.com'])->assertSuccessful();
        $lasagne = Recipe::where('legacy_id', 'r1')->firstOrFail();
        $this->assertNull($lasagne->image_path);
        $updatedAt = $lasagne->updated_at->toDateTimeString();

        // Running it again with the upload directory (configured on the admin page) adds them.
        config(['services.rezepteapp.uploads_path' => $this->uploads]);
        $this->artisan('recipes:import-rezepteapp', ['email' => 'tobi@example.com'])
            ->expectsOutputToContain('Added missing photos to 1 recipes')
            ->assertSuccessful();

        $lasagne->refresh();
        Storage::disk('local')->assertExists($lasagne->image_path);
        $this->assertCount(1, $lasagne->additional_images);
        $this->assertSame($updatedAt, $lasagne->updated_at->toDateTimeString());

        // A photo changed in LifeOS is kept, and the RezepteApp2 file is only read.
        $lasagne->update(['image_path' => $own = RecipeImages::store(file_get_contents($source), $lasagne->household_id)]);
        $this->artisan('recipes:import-rezepteapp', ['email' => 'tobi@example.com'])->assertSuccessful();
        $this->assertSame($own, $lasagne->fresh()->image_path);
        $this->assertFileExists($source);
        $this->assertSame($checksum, md5_file($source));
        $this->assertSame(3, DB::connection('rezepteapp')->table('recipes')->count());
    }

    public function test_running_the_import_again_does_not_duplicate_anything(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $user->update(['email' => 'tobi@example.com']);

        $this->artisan('recipes:import-rezepteapp', ['email' => 'tobi@example.com'])->assertSuccessful();
        Recipe::where('legacy_id', 'r1')->update(['title' => 'Lasagne (angepasst)']);
        $this->artisan('recipes:import-rezepteapp', ['email' => 'tobi@example.com'])->assertSuccessful();

        $this->assertSame(2, Recipe::where('household_id', $household->id)->count());
        $this->assertSame(2, RecipeCategory::where('household_id', $household->id)->count());
        $this->assertSame('Lasagne (angepasst)', Recipe::where('legacy_id', 'r1')->value('title'));
        $this->assertSame(1, RecipeRating::count());
        $this->assertSame(2, MealPlan::where('household_id', $household->id)->count());
    }

    public function test_dry_run_and_private_import(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        $this->artisan('recipes:import-rezepteapp', ['email' => $user->email, '--source-email' => 'tobi@example.com', '--dry-run' => true])
            ->expectsOutputToContain('Lasagne')
            ->assertSuccessful();
        $this->assertSame(0, Recipe::count());

        $this->artisan('recipes:import-rezepteapp', ['email' => $user->email, '--source-email' => 'tobi@example.com', '--private' => true])
            ->assertSuccessful();
        $this->assertTrue(Recipe::where('household_id', $household->id)->get()->every->isPrivate());
    }

    public function test_all_accounts_can_be_imported_into_one_household(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        $this->artisan('recipes:import-rezepteapp', ['email' => $user->email, '--all' => true])->assertSuccessful();

        $this->assertSame(['Altes Rezept', 'Fremdes Rezept', 'Lasagne'], Recipe::where('household_id', $household->id)->orderBy('title')->pluck('title')->all());
    }

    public function test_unknown_accounts_fail_cleanly(): void
    {
        [$user] = $this->memberWithHousehold();

        $this->artisan('recipes:import-rezepteapp', ['email' => 'niemand@example.com'])->assertFailed();
        $this->artisan('recipes:import-rezepteapp', ['email' => $user->email])->assertFailed();
    }
}
