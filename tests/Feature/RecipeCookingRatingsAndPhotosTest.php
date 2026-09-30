<?php

namespace Tests\Feature;

use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\RecipeRating;
use App\Support\StepTimers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class RecipeCookingRatingsAndPhotosTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_timers_are_detected_in_steps(): void
    {
        $this->assertSame(
            [600, 1200, 5400, 30],
            array_column(StepTimers::detect('10 Minuten kochen, ca. 20-25 min backen, 1,5 Std. ruhen, 30 Sek. mixen, nochmal 10 Min.'), 'seconds'),
        );
        $this->assertSame([], StepTimers::detect('200 g Mehl und 3 Eier bei 180 Grad verrühren.'));
    }

    public function test_cooking_mode_shows_scaled_ingredients_and_steps_and_asks_for_a_rating(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create([
            'servings' => 2,
            'ingredients' => [['amount' => '200', 'unit' => 'g', 'name' => 'Mehl']],
            'instructions' => [['text' => 'Teig 15 Minuten ruhen lassen.']],
        ]);
        $this->actingAs($user);

        $this->get(route('recipes.cook', ['recipe' => $recipe, 'servings' => 4]))
            ->assertOk()
            ->assertSee('400 g')
            ->assertSee('Teig 15 Minuten ruhen lassen.')
            ->assertSee('15 Minuten');

        Volt::test('recipes.cook', ['recipe' => $recipe->id])
            ->call('finish')
            ->call('finish')
            ->assertSet('finished', true)
            ->set('rating', 4)
            ->set('comment', 'Mehr Salz')
            ->call('rate')
            ->assertRedirect(route('recipes.show', $recipe));

        $this->assertSame(1, $recipe->fresh()->cooked_count);
        $rating = RecipeRating::sole();
        $this->assertSame([4, 'Mehr Salz', $user->id, today()->toDateString()], [$rating->rating, $rating->comment, $rating->user_id, $rating->cooked_at->toDateString()]);
    }

    public function test_each_member_rates_once_and_the_average_is_shown_and_sortable(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $good = Recipe::factory()->for($household)->create(['title' => 'Gut']);
        $better = Recipe::factory()->for($household)->create(['title' => 'Besser']);
        Recipe::factory()->for($household)->create(['title' => 'Unbewertet']);

        $this->actingAs($owner);
        Volt::test('recipes.show', ['recipe' => $good->id])->set('myRating', 2)->call('saveRating')->set('myRating', 3)->call('saveRating');
        Volt::test('recipes.show', ['recipe' => $better->id])->set('myRating', 5)->call('saveRating');

        $this->actingAs($partner);
        Volt::test('recipes.show', ['recipe' => $good->id])
            ->assertSet('myRating', 0)
            ->set('myRating', 4)
            ->set('myComment', 'Lecker')
            ->call('saveRating')
            ->assertSee('3,5');

        $this->assertSame(2, $good->ratings()->count());
        $this->assertSame(3.5, $good->averageRating());

        Volt::test('recipes.index')->set('sort', 'rating')->assertSeeInOrder(['Besser', 'Gut', 'Unbewertet']);

        Volt::test('recipes.show', ['recipe' => $good->id])->call('removeRating')->assertSet('myRating', 0);
        $this->assertSame(1, $good->ratings()->count());

        Volt::test('recipes.show', ['recipe' => $good->id])->set('myRating', 9)->call('saveRating')->assertHasErrors('myRating');
    }

    public function test_the_pantry_search_ranks_recipes_by_available_ingredients(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        Recipe::factory()->for($household)->create(['title' => 'Bratkartoffeln', 'ingredients' => [
            ['amount' => '1', 'unit' => 'kg', 'name' => 'Kartoffeln'],
            ['amount' => '2', 'unit' => '', 'name' => 'Zwiebeln'],
        ]]);
        Recipe::factory()->for($household)->create(['title' => 'Tortilla', 'ingredients' => [
            ['amount' => '500', 'unit' => 'g', 'name' => 'Kartoffeln'],
            ['amount' => '6', 'unit' => '', 'name' => 'Eier'],
            ['amount' => '1', 'unit' => '', 'name' => 'Paprika'],
        ]]);
        Recipe::factory()->for($household)->create(['title' => 'Obstsalat', 'ingredients' => [['amount' => '2', 'unit' => '', 'name' => 'Äpfel']]]);
        $this->actingAs($user);

        Volt::test('recipes.index')
            ->call('togglePantryMode')
            ->set('pantry', 'kartoffel, EIER, x')
            ->assertSeeInOrder(['Tortilla', '2 von 3 Zutaten vorhanden', 'Paprika', 'Bratkartoffeln', '1 von 2 Zutaten vorhanden', 'Zwiebeln'])
            ->assertDontSee('Obstsalat')
            ->call('togglePantryMode')
            ->assertSet('pantry', '')
            ->assertSee('Obstsalat');
    }

    public function test_duplicating_copies_the_recipe_with_categories_tags_and_own_photo_files(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        Storage::disk('local')->put('recipes/cover.jpg', 'cover');
        Storage::disk('local')->put('recipes/extra.jpg', 'extra');
        $category = RecipeCategory::factory()->for($household)->create();
        $recipe = Recipe::factory()->for($household)->create([
            'title' => 'Kuchen', 'created_by' => $owner->id, 'cooked_count' => 5,
            'image_path' => 'recipes/cover.jpg', 'additional_images' => ['recipes/extra.jpg'],
        ]);
        $recipe->categories()->attach($category);
        $recipe->syncTagNames(['backen']);
        $recipe->rateBy($owner, 5);

        $this->actingAs($partner);
        Volt::test('recipes.show', ['recipe' => $recipe->id])->call('duplicate');

        $copy = Recipe::latest('id')->firstOrFail();
        $this->assertNotSame($recipe->id, $copy->id);
        $this->assertSame(['Kuchen (Kopie)', $partner->id, 0], [$copy->title, $copy->created_by, $copy->cooked_count]);
        $this->assertSame([$category->id], $copy->categories->pluck('id')->all());
        $this->assertSame(['backen'], $copy->tags->pluck('name')->all());
        $this->assertSame(0, $copy->ratings()->count());
        $this->assertNotSame('recipes/cover.jpg', $copy->image_path);
        Storage::disk('local')->assertExists([$copy->image_path, $copy->additional_images[0]]);

        // Deleting the original leaves the copy's photos alone.
        $recipe->delete();
        Storage::disk('local')->assertMissing(['recipes/cover.jpg', 'recipes/extra.jpg']);
        Storage::disk('local')->assertExists([$copy->image_path, $copy->additional_images[0]]);
    }

    public function test_additional_photos_can_be_added_removed_and_are_served_to_members_only(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [$stranger] = $this->memberWithHousehold();
        Storage::disk('local')->put('recipes/one.jpg', 'one');
        Storage::disk('local')->put('recipes/two.jpg', 'two');
        $recipe = Recipe::factory()->for($household)->create(['created_by' => $user->id, 'additional_images' => ['recipes/one.jpg', 'recipes/two.jpg']]);
        $this->actingAs($user);

        Volt::test('recipes.form', ['recipe' => $recipe->id])
            ->call('toggleRemovedPhoto', 0)
            ->set('newPhotos', [UploadedFile::fake()->image('step.jpg', 800, 600)])
            ->call('save')
            ->assertHasNoErrors();

        $images = $recipe->fresh()->additional_images;
        $this->assertCount(2, $images);
        $this->assertSame('recipes/two.jpg', $images[0]);
        Storage::disk('local')->assertMissing('recipes/one.jpg');
        Storage::disk('local')->assertExists($images[1]);

        $this->get(route('recipes.photo', ['recipe' => $recipe, 'index' => 1]))->assertOk();
        $this->get(route('recipes.photo', ['recipe' => $recipe, 'index' => 5]))->assertNotFound();
        $this->get(route('recipes.show', $recipe))->assertSee(e(route('recipes.photo', ['recipe' => $recipe->id, 'index' => 1, 'v' => substr(md5($images[1]), 0, 8)])), false);
        $this->actingAs($stranger)->get(route('recipes.photo', ['recipe' => $recipe, 'index' => 0]))->assertNotFound();
    }

    public function test_export_and_account_deletion_include_ratings_and_meal_plans(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $recipe = Recipe::factory()->for($household)->create(['title' => 'Suppe']);
        $recipe->rateBy($user, 4, 'Gut');
        $shared = MealPlan::factory()->for($household)->create(['title' => 'Suppe', 'recipe_id' => $recipe->id, 'created_by' => $user->id]);
        $private = MealPlan::factory()->for($household)->create(['title' => 'Nur ich', 'created_by' => $user->id, 'visibility' => 'private']);

        $export = $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->get('/profile/export')->assertOk()->json();
        $this->assertSame(4, $export['recipes'][0]['my_rating']['rating']);
        $this->assertSame(['Suppe', 'Nur ich'], collect($export['meal_plan'])->pluck('title')->all());

        $user->delete();
        $this->assertDatabaseMissing('meal_plans', ['id' => $private->id]);
        $this->assertDatabaseHas('meal_plans', ['id' => $shared->id, 'created_by' => null]);
        $this->assertSame(0, RecipeRating::count());
    }
}
