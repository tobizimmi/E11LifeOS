<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ShoppingListEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SearchPaletteTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_search_finds_matches_across_all_modules(): void
    {
        [$user, $household] = $this->memberWithHousehold();

        $note = Item::factory()->note()->for($household)->create(['title' => 'Gartenplanung', 'content' => ['body' => 'Tomaten pflanzen']]);
        Item::factory()->todo()->for($household)->create(['title' => 'Tomaten gießen']);
        Item::factory()->inbox()->for($household)->create(['title' => 'Tomatensorten recherchieren']);
        ShoppingListEntry::factory()->for($household)->create(['label' => 'Tomaten']);
        Item::factory()->note()->for($household)->create(['title' => 'Steuern'])->syncTagNames(['tomatenzucht']);

        $this->actingAs($user);

        Volt::test('search-palette')
            ->set('query', 'tomat')
            ->assertSee(['Gartenplanung', 'Tomaten gießen', 'Tomatensorten recherchieren', 'Tomaten', '#tomatenzucht', 'Steuern'])
            ->assertSeeHtml(e(route('pages.index', ['item' => $note->id])));
    }

    public function test_search_needs_at_least_two_characters(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        Item::factory()->note()->for($household)->create(['title' => 'Xylophon']);

        $this->actingAs($user);

        Volt::test('search-palette')
            ->set('query', 'x')
            ->assertDontSee('Xylophon')
            ->assertViewHas('groups', []);
    }

    public function test_search_excludes_other_households_and_other_members_private_items(): void
    {
        [$owner, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        [, $otherHousehold] = $this->memberWithHousehold();

        Item::factory()->note()->for($household)->privateTo($owner)->create(['title' => 'Urlaub geheim'])->syncTagNames(['urlaubsgeheimnis']);
        Item::factory()->note()->for($otherHousehold)->create(['title' => 'Urlaub fremd']);
        Item::factory()->note()->for($household)->create(['title' => 'Urlaub gemeinsam']);

        $this->actingAs($partner);

        Volt::test('search-palette')
            ->set('query', 'urlaub')
            ->assertSee('Urlaub gemeinsam')
            ->assertDontSee(['Urlaub geheim', 'Urlaub fremd', 'urlaubsgeheimnis']);
    }

    public function test_the_palette_is_part_of_the_navigation(): void
    {
        [$user] = $this->memberWithHousehold();

        $this->actingAs($user)->get(route('pages.index'))->assertOk()->assertSeeVolt('search-palette');
    }

    public function test_the_palette_jumps_to_places_and_creates_tasks_items_and_inbox_entries(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('search-palette')
            ->assertSee('Springen zu')->assertSee('Einkaufsliste')
            ->set('query', 'angebot')->assertSee('Angebote')->assertDontSee('Mitglieder')
            ->set('query', 'Milch')->assertSee('Einkaufsliste: „Milch“')
            ->call('addToShopping')->assertSet('query', '')->assertDispatched('close-search')
            ->set('query', 'Steuer machen morgen')->call('createTask')
            ->set('query', 'Idee für den Garten')->call('addToInbox');

        $this->assertSame('Milch', ShoppingListEntry::sole()->label);
        $this->assertSame('Steuer machen', Item::where('type', Item::TYPE_TODO)->sole()->title);
        $this->assertSame('Idee für den Garten', Item::where('type', Item::TYPE_INBOX)->sole()->title);
        Volt::test('search-palette')->call('createTask')->assertStatus(422);
    }
}
