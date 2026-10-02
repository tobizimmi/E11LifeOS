<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Collection;
use App\Models\Item;
use App\Models\Transaction;
use App\Support\CollectionTemplates;
use App\Support\Finance\SubscriptionMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class SubscriptionMatcherTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_recurring_payments_are_compared_with_the_contracts(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
        [$user, $household] = $this->memberWithHousehold();
        $collection = Collection::factory()->create(['household_id' => $household->id, 'name' => 'Verträge']);
        CollectionTemplates::apply($collection, 'contracts');
        $property = fn (string $name) => $collection->properties()->where('name', $name)->sole()->id;
        $contract = fn (string $title, array $values) => Item::create([
            'household_id' => $household->id, 'collection_id' => $collection->id, 'type' => Item::TYPE_ENTRY, 'visibility' => Item::VISIBILITY_SHARED,
            'title' => $title, 'created_by' => $user->id, 'properties' => $values,
        ]);
        $internet = $contract('Internet', [$property('Monatliche Kosten (€)') => 39.99, $property('Anbieter') => 'Telekom']);
        $contract('Hausrat', [$property('Monatliche Kosten (€)') => 10.01]);

        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        foreach (['07', '08', '09'] as $month) {
            foreach ([['Telekom Deutschland GmbH', -44.99, 'telecom'], ['Netflix', -12.99, 'subscriptions'], ['Spotify AB', -9.99, 'subscriptions'], ['Sparplan Depot', -200, 'savings']] as [$name, $amount, $category]) {
                Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => "2026-$month-05", 'amount' => $amount, 'counterparty' => $name, 'purpose' => null, 'category' => $category]);
            }
        }

        $matching = SubscriptionMatcher::for($user, $household->id);
        $this->assertSame(['Netflix', 'Spotify AB'], array_column($matching['unmatched'], 'name'));
        $this->assertSame([5.0], array_column($matching['matched'], 'difference'));
        $this->assertSame(['Hausrat'], array_map(fn (array $contract) => $contract['item']->title, $matching['without_payment']));

        $this->actingAs($user);
        $spotify = $matching['unmatched'][1]['key'];
        Volt::test('contracts.index')
            ->assertSee('Mit deinen Kontobewegungen abgeglichen')
            ->assertSee('Vertrag 39,99 €, abgebucht 44,99 € pro Monat')
            ->call('adopt', $matching['unmatched'][0]['key'])
            ->call('dismiss', $spotify)
            ->call('updateCost', $internet->id)
            ->assertDontSee('Spotify AB')
            ->call('adopt', 'unknown')->assertNotFound();

        $netflix = Item::where('title', 'Netflix')->sole();
        $this->assertSame([$collection->id, 12.99, 'o4', 'Netflix'], [$netflix->collection_id, $netflix->propertyValue($property('Monatliche Kosten (€)')), $netflix->propertyValue($property('Kategorie')), $netflix->propertyValue($property('Anbieter'))]);
        $this->assertSame(44.99, $internet->fresh()->propertyValue($property('Monatliche Kosten (€)')));
        $this->assertSame([$spotify], $user->fresh()->dismissed_subscriptions);
        $this->assertSame([], SubscriptionMatcher::for($user, $household->id)['unmatched']);
    }

    public function test_any_payment_becomes_a_contract_and_contracts_are_added_by_hand(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
        [$user, $household] = $this->memberWithHousehold();
        $mine = Collection::factory()->create(['household_id' => $household->id, 'name' => 'Meine Verträge']);
        CollectionTemplates::apply($mine, 'contracts');
        $cost = $mine->properties()->where('name', 'Monatliche Kosten (€)')->sole()->id;
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => '2026-04-01', 'amount' => -59, 'counterparty' => 'ADAC', 'purpose' => null, 'category' => 'mobility']);

        $this->assertSame([], SubscriptionMatcher::for($user, $household->id)['unmatched']);
        $adac = collect(SubscriptionMatcher::payees($user, $household->id))->firstWhere('name', 'ADAC')['key'];

        $this->actingAs($user);
        Volt::test('contracts.index')
            ->set('showAllPayments', true)->assertSee('Alle weiteren Zahlungen (1)')->assertSee('ADAC')
            // A payment seen once opens the form, the yearly amount is entered as monthly cost.
            ->call('adopt', $adac)->assertSet('creating', true)->assertSet('contract.payment', $adac)
            ->set('contract.monthly', '4,92')->set('listId', $mine->id)->call('saveContract')->assertHasNoErrors()
            ->assertSee('Abbuchung')->assertDontSee('Alle weiteren Zahlungen')
            // A contract without any payment yet.
            ->call('startCreate')->set('contract.title', 'Fitnessstudio')->set('contract.provider', 'FitX')->set('contract.monthly', '24,90')->call('saveContract')
            ->call('startCreate')->call('saveContract')->assertHasErrors('contract.title');

        $contract = Item::where('title', 'ADAC')->sole();
        $this->assertSame([$mine->id, 4.92, [$adac]], [$contract->collection_id, $contract->propertyValue($cost), $contract->content['payment_keys']]);
        $this->assertSame([$mine->id, 24.9], [Item::where('title', 'Fitnessstudio')->sole()->collection_id, Item::where('title', 'Fitnessstudio')->sole()->propertyValue($cost)]);
    }
}
