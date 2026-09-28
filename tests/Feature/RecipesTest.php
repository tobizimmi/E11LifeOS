<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\ShoppingListEntry;
use App\Support\Addons;
use App\Support\IngredientAmount;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class RecipesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_amounts_are_scaled_for_other_servings(): void
    {
        $this->assertSame('400', IngredientAmount::scale('200', 2));
        $this->assertSame('3', IngredientAmount::scale('1,5', 2));
        $this->assertSame('1,5', IngredientAmount::scale('1/2', 3));
        $this->assertSame('3', IngredientAmount::scale('1 1/2', 2));
        $this->assertSame('1', IngredientAmount::scale('½', 2));
        $this->assertSame('4–6', IngredientAmount::scale('2-3', 2));
        $this->assertSame('etwas', IngredientAmount::scale('etwas', 2));
        $this->assertSame('0,33', IngredientAmount::scale('1', 1 / 3));
    }

    public function test_the_addon_can_be_switched_off_by_the_owner_only(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $member = $this->addMemberTo($household);

        $this->assertTrue($household->hasAddon(Addons::RECIPES));
        $this->actingAs($owner)->get(route('recipes.index'))->assertOk();

        $this->actingAs($member);
        Volt::test('household.show')->call('toggleAddon', Addons::RECIPES)->assertForbidden();

        $this->actingAs($owner);
        Volt::test('household.show')->call('toggleAddon', Addons::RECIPES);

        $this->assertFalse($household->fresh()->hasAddon(Addons::RECIPES));
        $this->get(route('recipes.index'))->assertNotFound();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('recipes.index'));
    }

    public function test_a_recipe_can_be_created_with_photo_ingredients_steps_categories_and_tags(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $component = Volt::test('recipes.form')
            ->set('title', 'Pfannkuchen')
            ->set('servings', 4)
            ->set('prepTime', 10)
            ->set('difficulty', 'easy')
            ->set('ingredients', [
                ['amount' => '', 'unit' => '', 'name' => 'Teig', 'isSection' => true],
                ['amount' => '250', 'unit' => 'g', 'name' => 'Mehl', 'isSection' => false],
                ['amount' => '', 'unit' => '', 'name' => '', 'isSection' => false],
            ])
            ->set('steps', ['Verrühren', '', 'Ausbacken'])
            ->set('newCategoryName', 'Frühstück')
            ->call('createCategory')
            ->set('tagsInput', 'Süß, schnell')
            ->set('photo', UploadedFile::fake()->image('pfannkuchen.jpg', 3000, 2000))
            ->call('save')
            ->assertHasNoErrors();

        $recipe = Recipe::where('title', 'Pfannkuchen')->firstOrFail();
        $component->assertRedirect(route('recipes.show', $recipe));

        $this->assertSame($household->id, $recipe->household_id);
        $this->assertEquals([['name' => 'Teig', 'isSection' => true], ['amount' => '250', 'unit' => 'g', 'name' => 'Mehl']], $recipe->ingredients);
        $this->assertSame(['Verrühren', 'Ausbacken'], $recipe->steps());
        $this->assertSame(['Frühstück'], $recipe->categories->pluck('name')->all());
        $this->assertSame(['schnell', 'süß'], $recipe->tags->pluck('name')->sort()->values()->all());

        Storage::disk('local')->assertExists($recipe->image_path);
        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($recipe->image_path));
        $this->assertSame([1600, 1067], [$width, $height]);
    }

    public function test_photos_are_only_served_to_members_who_may_see_the_recipe(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        [$stranger] = $this->memberWithHousehold();

        Storage::disk('local')->put('recipes/x.jpg', 'jpeg-bytes');
        $shared = Recipe::factory()->for($household)->create(['image_path' => 'recipes/x.jpg']);
        $private = Recipe::factory()->for($household)->privateTo($owner)->create(['image_path' => 'recipes/x.jpg']);

        $this->actingAs($partner)->get(route('recipes.image', $shared))->assertOk();
        $this->actingAs($partner)->get(route('recipes.image', $private))->assertNotFound();
        $this->actingAs($stranger)->get(route('recipes.image', $shared))->assertNotFound();
    }

    public function test_replacing_or_removing_the_photo_deletes_the_old_file(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        Storage::disk('local')->put('recipes/old.jpg', 'old');
        $recipe = Recipe::factory()->for($household)->create(['image_path' => 'recipes/old.jpg', 'created_by' => $user->id]);

        Volt::test('recipes.form', ['recipe' => $recipe->id])->set('removePhoto', true)->call('save');

        $this->assertNull($recipe->fresh()->image_path);
        Storage::disk('local')->assertMissing('recipes/old.jpg');
    }

    public function test_the_portion_calculator_scales_the_ingredients(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $recipe = Recipe::factory()->for($household)->create(['servings' => 2]);

        Volt::test('recipes.show', ['recipe' => $recipe->id])
            ->assertSet('servings', 2)
            ->call('changeServings', 2)
            ->assertSet('servings', 4)
            ->assertViewHas('ingredients', fn (array $ingredients) => $ingredients[0]['amount'] === '400' && $ingredients[1]['amount'] === '4');
    }

    public function test_selected_ingredients_go_onto_the_shopping_list_scaled(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $recipe = Recipe::factory()->for($household)->create([
            'servings' => 2,
            'ingredients' => [
                ['name' => 'Teig', 'isSection' => true],
                ['amount' => '200', 'unit' => 'g', 'name' => 'Mehl'],
                ['amount' => '2', 'unit' => '', 'name' => 'Eier'],
                ['amount' => '', 'unit' => '', 'name' => 'Salz'],
            ],
        ]);

        Volt::test('recipes.show', ['recipe' => $recipe->id])
            ->call('changeServings', 1)
            ->call('chooseIngredients')
            ->assertSet('selectedIngredients', [1, 2, 3])
            ->set('selectedIngredients', [1, 3])
            ->call('addToShoppingList')
            ->assertDispatched('toast');

        $this->assertSame(
            [['Mehl', '300 g'], ['Salz', null]],
            ShoppingListEntry::where('household_id', $household->id)->orderBy('id')->get()->map(fn ($entry) => [$entry->label, $entry->quantity])->all(),
        );
    }

    public function test_favorites_cooked_counter_archive_and_delete(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        Storage::disk('local')->put('recipes/photo.jpg', 'x');
        $recipe = Recipe::factory()->for($household)->create(['image_path' => 'recipes/photo.jpg']);

        $component = Volt::test('recipes.show', ['recipe' => $recipe->id])
            ->call('toggleFavorite')
            ->call('markCooked')
            ->call('toggleArchive');

        $recipe->refresh();
        $this->assertTrue($recipe->isFavoriteOf($user));
        $this->assertSame(1, $recipe->cooked_count);
        $this->assertTrue($recipe->isArchived());

        $component->call('delete')->assertRedirect(route('recipes.index'));
        $this->assertDatabaseMissing('recipes', ['id' => $recipe->id]);
        Storage::disk('local')->assertMissing('recipes/photo.jpg');
    }

    public function test_the_list_filters_by_search_category_favorites_and_archive(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $category = RecipeCategory::factory()->for($household)->create(['name' => 'Suppen']);

        $soup = Recipe::factory()->for($household)->create(['title' => 'Kürbissuppe', 'ingredients' => [['amount' => '1', 'unit' => '', 'name' => 'Hokkaido']]]);
        $soup->categories()->attach($category);
        $cake = Recipe::factory()->for($household)->create(['title' => 'Apfelkuchen']);
        $cake->favoritedBy()->attach($user);
        Recipe::factory()->for($household)->create(['title' => 'Alter Auflauf', 'archived_at' => now()]);

        $titles = fn ($recipes) => $recipes->pluck('title')->sort()->values()->all();

        Volt::test('recipes.index')
            ->assertViewHas('recipes', fn ($recipes) => $titles($recipes) === ['Apfelkuchen', 'Kürbissuppe'])
            ->set('search', 'hokkaido')
            ->assertViewHas('recipes', fn ($recipes) => $titles($recipes) === ['Kürbissuppe'])
            ->set('search', '')
            ->set('category', (string) $category->id)
            ->assertViewHas('recipes', fn ($recipes) => $titles($recipes) === ['Kürbissuppe'])
            ->set('category', '')
            ->set('favorites', true)
            ->assertViewHas('recipes', fn ($recipes) => $titles($recipes) === ['Apfelkuchen'])
            ->set('favorites', false)
            ->set('archived', true)
            ->assertViewHas('recipes', fn ($recipes) => $titles($recipes) === ['Alter Auflauf']);
    }

    public function test_recipes_can_be_shown_as_cards_list_or_table(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        Recipe::factory()->for($household)->create(['title' => 'Lasagne', 'prep_time' => 20, 'cook_time' => 40, 'cooked_count' => 3]);

        Volt::test('recipes.index')->assertSet('view', 'cards')->assertSee('Lasagne')
            ->set('view', 'list')->assertSee('Lasagne')->assertSee('60 Min')
            ->set('view', 'table')->assertSee('Gesamtzeit')->assertSee('Lasagne')
            ->set('view', 'unknown');

        // The choice is kept for the session; unknown values fall back to cards.
        Volt::test('recipes.index')->assertSet('view', 'cards');
        Volt::test('recipes.index')->set('view', 'table');
        Volt::test('recipes.index')->assertSet('view', 'table');
    }

    public function test_private_recipes_and_other_households_stay_hidden(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        [, $otherHousehold] = $this->memberWithHousehold();

        $private = Recipe::factory()->for($household)->privateTo($owner)->create(['title' => 'Geheimrezept']);
        Recipe::factory()->for($otherHousehold)->create(['title' => 'Nachbars Rezept']);

        $this->actingAs($partner);

        Volt::test('recipes.index')->assertDontSee('Geheimrezept')->assertDontSee('Nachbars Rezept');
        $this->get(route('recipes.show', $private))->assertNotFound();
        $this->assertThrows(fn () => Volt::test('recipes.form', ['recipe' => $private->id]), ModelNotFoundException::class);
    }

    public function test_categories_can_be_managed(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $component = Volt::test('recipes.categories')
            ->set('name', 'Desserts')->set('icon', '🍰')->call('create')
            ->set('name', 'Salate')->call('create')
            ->set('name', 'Salate')->call('create')
            ->assertHasErrors('name');

        [$desserts, $salads] = [RecipeCategory::where('name', 'Desserts')->firstOrFail(), RecipeCategory::where('name', 'Salate')->firstOrFail()];

        $component->call('move', $salads->id, -1)->call('rename', $desserts->id, 'Nachtisch')->call('changeIcon', $salads->id, '🥗');
        $this->assertSame(['Salate', 'Nachtisch'], RecipeCategory::where('household_id', $household->id)->orderBy('position')->pluck('name')->all());
        $this->assertSame('🥗', $salads->fresh()->icon);

        $recipe = Recipe::factory()->for($household)->create();
        $recipe->categories()->attach($salads);
        $component->call('delete', $salads->id);
        $this->assertDatabaseHas('recipes', ['id' => $recipe->id]);
        $this->assertDatabaseMissing('recipe_categories', ['id' => $salads->id]);
    }

    public function test_search_export_and_account_deletion_include_recipes(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $shared = Recipe::factory()->for($household)->create(['title' => 'Chili sin Carne', 'created_by' => $user->id]);
        $private = Recipe::factory()->for($household)->privateTo($user)->create(['title' => 'Mein Curry']);

        $this->actingAs($user);
        Volt::test('search-palette')->set('query', 'chili')->assertSee('Chili sin Carne')->assertSeeHtml(e(route('recipes.show', $shared)));

        $export = $this->get('/profile/export')->assertOk()->json();
        $this->assertSame(['Chili sin Carne', 'Mein Curry'], collect($export['recipes'])->pluck('title')->all());

        $user->delete();
        $this->assertDatabaseMissing('recipes', ['id' => $private->id]);
        $this->assertDatabaseHas('recipes', ['id' => $shared->id, 'created_by' => null]);
    }
}
