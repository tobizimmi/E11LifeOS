<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Quote;
use App\Models\QuoteComparison;
use App\Models\RealEstate;
use App\Support\ExternalLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class QuoteComparisonsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_quotes_are_compared_and_one_is_chosen(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('quotes.index')
            ->set('showForm', true)
            ->set('form.title', 'Neue Küche')
            ->call('create')
            ->assertHasNoErrors();

        $comparison = QuoteComparison::sole();
        $this->assertSame([$household->id, Item::VISIBILITY_SHARED], [$comparison->household_id, $comparison->visibility]);

        $page = Volt::test('quotes.show', ['comparison' => $comparison])
            ->call('addQuote')
            ->set('quote.vendor', 'Küchenstudio Nord')->set('quote.amount', '12.500,00')->set('quote.valid_until', '2020-01-31')
            ->call('saveQuote')
            ->assertHasNoErrors()
            ->call('addQuote')
            ->set('quote.vendor', 'Möbelhaus Süd')->set('quote.amount', '10000')->set('quote.pros', 'Montage inklusive')
            ->call('saveQuote');

        $this->assertSame([12500.0, 10000.0], $comparison->quotes()->pluck('amount')->all());
        $page->assertSee('Günstigstes')->assertSee('+2.500,00 €')->assertSee('abgelaufen')->assertSee('Montage inklusive');

        $cheap = Quote::where('vendor', 'Möbelhaus Süd')->sole();
        $page->call('choose', $cheap->id);
        $this->assertSame($cheap->id, $comparison->fresh()->chosen_quote_id);

        Volt::test('quotes.index')->assertSee('Neue Küche')->assertSee('Entschieden')->assertSee('Möbelhaus Süd');
        $this->assertNotNull(ExternalLinks::resolve($user, $household->id, 'quote', $cheap->id));
    }

    public function test_a_trade_of_a_property_takes_over_the_chosen_quote(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id, 'name' => 'Lindenhaus']);
        $roof = $realEstate->budgetItems()->create(['trade' => 'roof', 'title' => 'Dach', 'planned_amount' => 20000]);

        Volt::test('real-estate.show', ['realEstate' => $realEstate->id])->set('tab', 'budget')->assertSee('Angebote vergleichen');

        Livewire::withQueryParams(['budget_item' => $roof->id])->test('quotes.index')
            ->assertSet('form.title', 'Dach · Lindenhaus')
            ->call('create');

        $comparison = QuoteComparison::sole();
        $this->assertSame([$realEstate->id, $roof->id, 'quoted'], [$comparison->real_estate_id, $comparison->budget_item_id, $roof->fresh()->status]);

        $quote = $comparison->quotes()->create(['vendor' => 'Dachdecker Kurz', 'amount' => 18500]);
        Volt::test('quotes.show', ['comparison' => $comparison])->call('choose', $quote->id);

        $roof->refresh();
        $this->assertSame(['Dachdecker Kurz', 18500.0, 'commissioned'], [$roof->contractor, (float) $roof->planned_amount, $roof->status]);

        // Coming back from the trade opens the existing comparison.
        Livewire::withQueryParams(['budget_item' => $roof->id])->test('quotes.index')->assertRedirect(route('quotes.show', $comparison));
        Volt::test('real-estate.show', ['realEstate' => $realEstate->id])->set('tab', 'budget')->assertSee('1 Angebot vergleichen ✓');
    }

    public function test_comparisons_can_be_linked_later_and_stay_private(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        [$stranger, $otherHousehold] = $this->memberWithHousehold();
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id]);
        $windows = $realEstate->budgetItems()->create(['trade' => 'windows', 'title' => 'Fenster']);
        $foreignRealEstate = RealEstate::factory()->for($otherHousehold)->create(['created_by' => $stranger->id]);
        $private = QuoteComparison::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'visibility' => Item::VISIBILITY_PRIVATE, 'title' => 'Geheimes Geschenk']);
        $this->actingAs($user);

        Volt::test('quotes.show', ['comparison' => $private])
            ->set('linkRealEstateId', $realEstate->id)
            ->set('linkBudgetItemId', $windows->id)
            ->call('saveLink');
        $this->assertSame($windows->id, $private->fresh()->budget_item_id);

        Volt::test('quotes.show', ['comparison' => $private])->set('linkRealEstateId', $foreignRealEstate->id)->call('saveLink')->assertNotFound();

        $this->actingAs($partner);
        Volt::test('quotes.index')->assertDontSee('Geheimes Geschenk');
        $this->get(route('quotes.show', $private))->assertNotFound();

        $this->actingAs($stranger);
        $this->get(route('quotes.show', $private))->assertNotFound();
    }
}
