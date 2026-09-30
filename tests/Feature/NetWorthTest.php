<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\NetWorthItem;
use App\Models\NetWorthSnapshot;
use App\Models\RealEstate;
use App\Models\Transaction;
use App\Support\Finance\NetWorth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class NetWorthTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_net_worth_adds_accounts_properties_and_manual_items_and_subtracts_debts(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Girokonto', 'balance' => 2000, 'balance_on' => '2026-09-01']);
        Transaction::factory()->create(['user_id' => $user->id, 'household_id' => $household->id, 'bank_account_id' => $account->id, 'booked_on' => '2026-08-30', 'amount' => -999]);
        Transaction::factory()->create(['user_id' => $user->id, 'household_id' => $household->id, 'bank_account_id' => $account->id, 'booked_on' => '2026-09-15', 'amount' => -500]);
        $house = RealEstate::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'purchase_price' => 300000]);
        $house->valuations()->create(['valued_on' => '2026-06-01', 'value' => 350000, 'source' => 'estimate']);
        $house->loans()->create(['lender' => 'Bank', 'kind' => 'annuity', 'amount' => 200000, 'interest_rate' => 3, 'repayment_rate' => 2, 'start_on' => '2026-10-01']);
        NetWorthItem::factory()->create(['user_id' => $user->id, 'name' => 'Auto', 'value' => 15000]);
        NetWorthItem::factory()->liability()->create(['user_id' => $user->id, 'value' => 5000]);

        $netWorth = NetWorth::for($user);

        // 1,500 (only the later transaction counts) + 350,000 + 15,000 − 200,000 − 5,000
        $this->assertEqualsWithDelta(366500.0, $netWorth['assets'], 0.001);
        $this->assertEqualsWithDelta(205000.0, $netWorth['liabilities'], 0.001);
        $this->assertEqualsWithDelta(161500.0, $netWorth['net'], 0.001);
    }

    public function test_balances_and_items_are_maintained_on_the_page_and_the_history_is_stored(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Girokonto']);
        $this->actingAs($user);

        Volt::test('net-worth.index')
            ->assertSee('Noch kein Kontostand eingetragen')
            ->call('editAccount', $account->id)
            ->set('account.balance', '1234.50')
            ->call('saveAccount')
            ->assertHasNoErrors()
            ->set('item.name', 'Bargeld')->set('item.kind', 'asset')->set('item.value', '300')
            ->call('addItem')
            ->assertHasNoErrors()
            ->assertSee('1.534,50 €');

        $this->assertEqualsWithDelta(1534.5, NetWorthSnapshot::where('user_id', $user->id)->sole()->netWorth(), 0.001);

        $this->artisan('net-worth:snapshot')->assertSuccessful();
        $this->assertSame(1, NetWorthSnapshot::where('user_id', $user->id)->count());
    }

    public function test_items_and_accounts_of_other_members_cannot_be_changed(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $item = NetWorthItem::factory()->create(['user_id' => $other->id]);
        $account = BankAccount::factory()->create(['user_id' => $other->id, 'household_id' => $otherHousehold->id]);
        $this->actingAs($user);

        Volt::test('net-worth.index')->call('deleteItem', $item->id)->assertNotFound();
        Volt::test('net-worth.index')->call('editAccount', $account->id)->assertNotFound();
        $this->assertModelExists($item);
    }
}
