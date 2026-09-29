<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Item;
use App\Models\ItemActivity;
use App\Models\NetWorthSnapshot;
use App\Models\ShoppingListEntry;
use App\Models\Transaction;
use App\Support\Finance\CashflowStatement;
use App\Support\Life\HouseholdLoad;
use App\Support\Life\YearReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class InsightsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_cashflow_history_and_the_dashboard_widget(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);

        foreach (['2026-07-05' => [3000, -2000], '2026-08-05' => [3000, -3500], '2026-09-05' => [3200, -2200]] as $date => [$income, $expense]) {
            Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => $date, 'amount' => $income, 'category' => 'salary']);
            Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => $date, 'amount' => $expense, 'category' => 'groceries']);
        }

        $history = CashflowStatement::history($user);
        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($history, 'month'));
        $this->assertSame([1000.0, -500.0, 1000.0], array_column($history, 'cashflow'));

        $this->actingAs($user);
        Volt::test('cashflow.index')->assertSee('Entwicklung')->assertSee('Cashflow pro Monat');

        $user->update(['dashboard_widgets' => ['freedom', 'life']]);
        Volt::test('dashboard')->call('loadWidgets')->assertSee('Finanzielle Freiheit')->assertSee('Leben & Vorsorge: nächste Termine');
    }

    public function test_the_household_sees_who_carries_the_load(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $task = Item::factory()->create(['household_id' => $household->id, 'created_by' => $user->id]);

        foreach (range(1, 9) as $i) {
            ItemActivity::create(['item_id' => $task->id, 'user_id' => $user->id, 'action' => 'completed', 'created_at' => now()->subDays($i)]);
        }

        ItemActivity::create(['item_id' => $task->id, 'user_id' => $partner->id, 'action' => 'completed', 'created_at' => now()->subDay()]);
        ItemActivity::create(['item_id' => $task->id, 'user_id' => $partner->id, 'action' => 'completed', 'created_at' => now()->subDays(40)]);
        ShoppingListEntry::create(['household_id' => $household->id, 'label' => 'Milch', 'created_by' => $user->id]);

        $load = HouseholdLoad::for($household);
        $this->assertSame([$user->id, $partner->id], array_column($load['members'], 'user_id'));
        $this->assertSame([9, 1], array_column($load['members'], 'completed'));
        $this->assertSame([91, 9], array_column($load['members'], 'share'));
        $this->assertSame($user->name, $load['lopsided']);

        $this->actingAs($partner);
        Volt::test('household.show')->assertSee('Wer macht was?')->assertSee('trägt gerade den Großteil');
    }

    public function test_the_year_in_review_sums_up_money_and_everyday_life(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => '2026-03-01', 'amount' => 40000, 'category' => 'salary']);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => '2026-03-02', 'amount' => -12000, 'category' => 'housing']);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => '2026-04-02', 'amount' => -8000, 'category' => 'travel']);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => '2025-12-02', 'amount' => -999, 'category' => 'travel']);
        NetWorthSnapshot::create(['user_id' => $user->id, 'taken_on' => '2026-01-05', 'assets' => 100000, 'liabilities' => 50000]);
        NetWorthSnapshot::create(['user_id' => $user->id, 'taken_on' => '2026-09-01', 'assets' => 130000, 'liabilities' => 48000]);
        $task = Item::factory()->create(['household_id' => $household->id, 'created_by' => $user->id]);
        ItemActivity::create(['item_id' => $task->id, 'user_id' => $user->id, 'action' => 'completed', 'created_at' => '2026-05-01 10:00']);

        $review = YearReview::for($user, 2026);
        $this->assertSame([40000.0, 20000.0, 20000.0, 50.0], [$review['finance']['income'], $review['finance']['expenses'], $review['finance']['saved'], $review['finance']['rate']]);
        $this->assertSame('Wohnen & Miete', $review['finance']['categories'][0]['label']);
        $this->assertSame(32000.0, $review['net_worth']['change']);
        $this->assertSame(1, $review['tasks']);

        $this->actingAs($user);
        Volt::test('review.index')->assertSee('Alltag')->assertSee('50,0 %')->assertSee('+32.000 €');
        Volt::test('review.index', ['year' => 2025])->assertSee('2025')->assertDontSee('50,0 %');
    }
}
