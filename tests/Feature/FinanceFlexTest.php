<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Transaction;
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
