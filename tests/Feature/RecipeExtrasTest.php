<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\RecipeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class RecipeExtrasTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-23 12:00:00');
    }

    public function test_liked_recipes_not_cooked_for_a_while_are_suggested_again(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $forgotten = Recipe::factory()->for($household)->create(['title' => 'Omas Gulasch', 'cooked_count' => 5, 'last_cooked_at' => '2026-03-01']);
        Recipe::factory()->for($household)->create(['title' => 'Frisch gekocht', 'cooked_count' => 5, 'last_cooked_at' => '2026-09-20']);
        Recipe::factory()->for($household)->create(['title' => 'Nie gemocht', 'cooked_count' => 1, 'last_cooked_at' => '2026-01-01']);

        $this->assertSame([$forgotten->id], Recipe::query()->forgotten()->pluck('id')->all());
        $this->actingAs($user);
        Volt::test('recipes.index')->assertSee('Omas Gulasch')->assertSee(__('Long time no see'));

        Volt::test('recipes.show', ['recipe' => $forgotten->id])->call('markCooked');
        $forgotten->refresh();
        $this->assertSame('2026-09-23', $forgotten->last_cooked_at->toDateString());
        $this->assertSame(6, $forgotten->cooked_count);
        $this->assertSame([], Recipe::query()->forgotten()->pluck('id')->all());
    }

    public function test_recipes_can_be_dropped_on_a_day_leftovers_planned_and_nutrition_added_up(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $soup = Recipe::factory()->for($household)->create(['title' => 'Kürbissuppe', 'servings' => 4, 'nutrition' => ['calories' => 350, 'protein' => 8]]);
        $cake = Recipe::factory()->for($household)->create(['title' => 'Kuchen', 'servings' => 12, 'nutrition' => ['calories' => 420, 'protein' => 6]]);
        $this->actingAs($user);

        $component = Volt::test('recipes.plan')
            ->call('dropRecipe', $soup->id, '2026-09-24')
            ->call('dropRecipe', $cake->id, '2026-09-24');

        $entry = MealPlan::where('recipe_id', $soup->id)->firstOrFail();
        $this->assertSame(['2026-09-24', 'dinner', 4], [$entry->date->toDateString(), $entry->meal_type, $entry->servings]);
        $component->assertSee('≈ 770 kcal', false);

        $component->call('planLeftovers', $entry->id);
        $leftovers = MealPlan::whereDate('date', '2026-09-25')->firstOrFail();
        $this->assertSame(['Reste: Kürbissuppe', 'lunch', null], [$leftovers->title, $leftovers->meal_type, $leftovers->recipe_id]);
    }

    public function test_shared_links_become_recipes_or_inbox_entries(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Livewire::withQueryParams(['title' => 'Lasagne', 'text' => 'Schau mal https://example.com/lasagne'])->test('share.target')
            ->assertSet('text', 'Schau mal https://example.com/lasagne')->assertSet('sharedUrl', 'https://example.com/lasagne')
            ->assertSee(__('Import as recipe'))
            ->call('toRecipe')
            ->assertRedirect(route('recipes.import', ['url' => 'https://example.com/lasagne']));

        Livewire::withQueryParams(['title' => 'Lasagne', 'url' => 'https://example.com/lasagne'])->test('share.target')->call('toInbox');
        $this->assertSame('Lasagne https://example.com/lasagne', Item::where('household_id', $household->id)->where('type', Item::TYPE_INBOX)->value('title'));

        Livewire::withQueryParams(['url' => 'javascript:alert(1)'])->test('share.target')->assertSet('sharedUrl', '')->assertDontSee(__('Import as recipe'));
    }

    public function test_the_recipes_of_a_category_can_be_downloaded_as_a_cookbook(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [$stranger] = $this->memberWithHousehold();
        $category = RecipeCategory::create(['household_id' => $household->id, 'name' => 'Suppen']);
        Recipe::factory()->for($household)->create(['title' => 'Kürbissuppe'])->categories()->attach($category);
        Recipe::factory()->for($household)->create(['title' => 'Kuchen']);

        $response = $this->actingAs($user)->get(route('recipes.cookbook', ['category' => $category->id]))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('kochbuch-suppen.pdf', (string) $response->headers->get('Content-Disposition'));

        $this->actingAs($stranger)->get(route('recipes.cookbook'))->assertNotFound();
    }
}
