<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Item;
use App\Support\CollectionTemplates;
use App\Support\ContractOverview;
use App\Support\LetterTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class ContractCostsAndLettersTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    /**
     * @return array{0: Collection, 1: Item}
     */
    private function contractsWithEntries(int $householdId, int $userId): array
    {
        $collection = Collection::factory()->create(['household_id' => $householdId, 'name' => 'Verträge']);
        CollectionTemplates::apply($collection, 'contracts');
        $property = fn (string $name) => $collection->properties()->where('name', $name)->sole()->id;
        $entry = fn (string $title, array $values) => Item::create([
            'household_id' => $householdId,
            'collection_id' => $collection->id,
            'type' => Item::TYPE_ENTRY,
            'visibility' => Item::VISIBILITY_SHARED,
            'title' => $title,
            'created_by' => $userId,
            'properties' => $values,
        ]);

        $internet = $entry('Internet', [$property('Monatliche Kosten (€)') => 39.99, $property('Anbieter') => 'Telekom', $property('Kundennummer') => 'K-123', $property('Kündigen bis') => '2026-10-15', $property('Endet am') => '2026-12-31', $property('Kategorie') => 'o3']);
        $entry('Hausrat', [$property('Monatliche Kosten (€)') => 10.01, $property('Kategorie') => 'o1', $property('Kündigen bis') => '2027-06-01']);

        return [$collection, $internet];
    }

    public function test_contract_costs_are_summed_with_the_next_deadlines(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [, $internet] = $this->contractsWithEntries($household->id, $user->id);
        $this->actingAs($user);

        $overview = ContractOverview::for($user, $household->id);
        $this->assertSame(50.0, $overview['monthly']);
        $this->assertSame(600.0, $overview['yearly']);
        $this->assertSame(['Internet & Telefon' => 39.99, 'Versicherung' => 10.01], $overview['by_category']);
        $this->assertSame([$internet->id], array_map(fn (array $contract) => $contract['item']->id, $overview['deadlines']));

        $this->get(route('contracts.index'))->assertOk()
            ->assertSee('50,00 €')->assertSee('600,00 €')->assertSee('Telekom')
            ->assertSee('kündigen bis 15.10.2026')->assertSee('Kündigung schreiben');
    }

    public function test_contracts_of_other_households_are_not_counted(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $this->contractsWithEntries($otherHousehold->id, $other->id);
        $this->actingAs($user);

        $this->get(route('contracts.index'))->assertOk()->assertSee('Noch keine Verträge mit Kosten.')->assertDontSee('Telekom');
    }

    public function test_cancellation_letter_is_prefilled_from_the_contract_and_downloaded(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        [, $internet] = $this->contractsWithEntries($household->id, $user->id);
        $this->actingAs($user);

        $component = Livewire::withQueryParams(['template' => LetterTemplates::CANCELLATION, 'entry' => $internet->id])->test('letters.index')
            ->assertSet('recipient', 'Telekom')
            ->assertSet('subject', 'Kündigung: Internet – Kundennummer K-123');
        $this->assertStringContainsString('hiermit kündige ich Internet (Kundennummer K-123) fristgerecht zum 31.12.2026.', $component->get('body'));

        $component->set('sender', "Tobi Muster\nHauptstr. 1\n12345 Musterstadt")
            ->set('recipient', "Telekom\nPostfach 1\n53105 Bonn")
            ->call('download')
            ->assertHasNoErrors()
            ->assertFileDownloaded('kundigung-internet-kundennummer-k-123.pdf');

        $this->assertSame("Tobi Muster\nHauptstr. 1\n12345 Musterstadt", $user->fresh()->postal_address);
        Volt::test('letters.index')->assertSet('sender', "Tobi Muster\nHauptstr. 1\n12345 Musterstadt");
    }

    public function test_letters_cannot_be_filled_from_foreign_entries_and_need_a_recipient(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        [, $foreign] = $this->contractsWithEntries($otherHousehold->id, $other->id);
        $this->actingAs($user);

        Livewire::withQueryParams(['template' => LetterTemplates::OBJECTION, 'entry' => $foreign->id])->test('letters.index')
            ->assertSet('recipient', '')
            ->assertSet('subject', 'Widerspruch: meinen Vertrag')
            ->call('download')
            ->assertHasErrors('recipient');
    }
}
