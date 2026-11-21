<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Support\Finance\FinanceCategories;
use App\Support\Finance\FinancePeriod;
use App\Support\Finance\FinanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class FinanceFlexTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FinanceCategories::flush();
    }

    public function test_a_custom_range_limits_the_report_to_the_chosen_dates(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $giro = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        Transaction::factory()->create(['bank_account_id' => $giro->id, 'amount' => -100, 'booked_on' => '2026-06-15', 'category' => 'groceries']);
        Transaction::factory()->create(['bank_account_id' => $giro->id, 'amount' => -200, 'booked_on' => '2026-09-15', 'category' => 'groceries']);

        Volt::test('finance.index')
            ->set('tab', 'report')
            ->set('period', FinancePeriod::CUSTOM)
            ->set('customFrom', '2026-06-01')
            ->set('customTo', '2026-06-30')
            ->assertViewHas('report', fn (array $report) => $report['expenses'] === 100.0);
    }

    public function test_the_comparison_period_is_built_when_enabled(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $giro = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        Transaction::factory()->create(['bank_account_id' => $giro->id, 'amount' => -50, 'booked_on' => now(), 'category' => 'groceries']);

        Volt::test('finance.index')
            ->set('tab', 'report')
            ->set('compare', true)
            ->assertViewHas('comparison', fn (?array $comparison) => $comparison !== null && isset($comparison['from'], $comparison['to']))
            ->assertViewHas('deltas', fn (array $deltas) => count($deltas) === 3);
    }

    public function test_the_previous_range_precedes_and_matches_the_length(): void
    {
        [$previousFrom, $previousTo] = FinancePeriod::previousRange(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->assertSame('2026-06-30', $previousTo->toDateString());
        $this->assertSame('2026-05-31', $previousFrom->toDateString());
    }

    public function test_a_custom_range_swaps_dates_given_the_wrong_way_round(): void
    {
        [$from, $to] = FinancePeriod::customRange('2026-09-30', '2026-09-01');

        $this->assertSame('2026-09-01', $from->toDateString());
        $this->assertSame('2026-09-30', $to->toDateString());
    }

    public function test_transfers_count_as_income_and_expense_only_when_included(): void
    {
        $transactions = collect([
            new Transaction(['amount' => 500, 'category' => 'transfer', 'booked_on' => now()]),
            new Transaction(['amount' => -500, 'category' => 'transfer', 'booked_on' => now()]),
            new Transaction(['amount' => -100, 'category' => 'groceries', 'booked_on' => now()]),
        ]);
        [$from, $to] = FinancePeriod::range('6m');

        $default = FinanceReport::build($transactions, $from, $to);
        $this->assertSame(0.0, $default['income']);
        $this->assertSame(100.0, $default['expenses']);

        $raw = FinanceReport::build($transactions, $from, $to, ['includeTransfers' => true]);
        $this->assertSame(500.0, $raw['income']);
        $this->assertSame(600.0, $raw['expenses']);
    }

    public function test_the_category_filter_drills_the_report_to_one_category(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        $giro = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        Transaction::factory()->create(['bank_account_id' => $giro->id, 'amount' => -100, 'booked_on' => '2026-06-15', 'category' => 'groceries']);
        Transaction::factory()->create(['bank_account_id' => $giro->id, 'amount' => -40, 'booked_on' => '2026-06-16', 'category' => 'restaurants']);

        Volt::test('finance.index')
            ->set('tab', 'report')
            ->set('period', FinancePeriod::CUSTOM)
            ->set('customFrom', '2026-06-01')
            ->set('customTo', '2026-06-30')
            ->set('categoryFilter', 'groceries')
            ->assertViewHas('report', fn (array $report) => $report['expenses'] === 100.0 && count($report['categories']) === 1);
    }

    public function test_a_custom_category_is_added_and_joins_the_catalogue(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('finance.index')
            ->set('tab', 'budgets')
            ->set('newCategory.label', 'Garten')
            ->set('newCategory.icon', '🌱')
            ->set('newCategory.group', 'wants')
            ->call('addCategory')
            ->assertHasNoErrors();

        $category = FinanceCategory::where('label', 'Garten')->first();
        $this->assertNotNull($category);
        $this->assertSame('wants', $category->group);

        FinanceCategories::flush();
        $this->assertTrue(FinanceCategories::exists($category->key));
        $this->assertSame('Garten', FinanceCategories::label($category->key));
        $this->assertTrue(FinanceCategories::isCustom($category->key));
        $this->assertFalse(FinanceCategories::isCustom('groceries'));
    }

    public function test_deleting_a_custom_category_moves_its_bookings_to_other(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $component = Volt::test('finance.index');
        $householdId = (int) $component->get('householdId');

        $giro = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $householdId]);
        FinanceCategory::factory()->create(['user_id' => $user->id, 'household_id' => $householdId, 'key' => 'cat_test1234', 'label' => 'Garten', 'group' => 'wants']);
        $booking = Transaction::factory()->create(['bank_account_id' => $giro->id, 'household_id' => $householdId, 'user_id' => $user->id, 'amount' => -20, 'booked_on' => now(), 'category' => 'cat_test1234']);

        FinanceCategories::flush();
        $component->call('deleteCategory', 'cat_test1234');

        $this->assertDatabaseMissing('finance_categories', ['key' => 'cat_test1234']);
        $this->assertSame('other', $booking->fresh()->category);
    }

    public function test_expenses_are_broken_down_by_account(): void
    {
        $giro = new Transaction(['amount' => -100, 'category' => 'groceries', 'booked_on' => now()]);
        $giro->bank_account_id = 1;
        $giro->setRelation('account', new BankAccount(['name' => 'Giro']));

        $visa = new Transaction(['amount' => -60, 'category' => 'restaurants', 'booked_on' => now()]);
        $visa->bank_account_id = 2;
        $visa->setRelation('account', new BankAccount(['name' => 'Visa']));

        [$from, $to] = FinancePeriod::range('6m');
        $report = FinanceReport::build(collect([$giro, $visa]), $from, $to);

        $this->assertCount(2, $report['byAccount']);
        $this->assertSame('Giro', $report['byAccount'][0]['label']);
        $this->assertSame(100.0, $report['byAccount'][0]['amount']);
    }

    public function test_savings_count_as_expense_only_when_included(): void
    {
        $transactions = collect([
            new Transaction(['amount' => 2000, 'category' => 'income', 'booked_on' => now()]),
            new Transaction(['amount' => -500, 'category' => 'savings', 'booked_on' => now()]),
            new Transaction(['amount' => -100, 'category' => 'groceries', 'booked_on' => now()]),
        ]);
        [$from, $to] = FinancePeriod::range('6m');

        $default = FinanceReport::build($transactions, $from, $to);
        $this->assertSame(100.0, $default['expenses']);
        $this->assertSame(500.0, $default['savingsTransfers']);

        $withSavings = FinanceReport::build($transactions, $from, $to, ['includeSavings' => true]);
        $this->assertSame(600.0, $withSavings['expenses']);
        $this->assertSame(0.0, $withSavings['savingsTransfers']);
    }
}
