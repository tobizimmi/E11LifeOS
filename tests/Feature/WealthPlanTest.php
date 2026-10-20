<?php

namespace Tests\Feature;

use App\Models\NetWorthItem;
use App\Models\Portfolio;
use App\Models\SavingsPlan;
use App\Support\Finance\WealthPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class WealthPlanTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_fire_number_and_years_to_target_without_return(): void
    {
        [$user] = $this->memberWithHousehold();

        $plan = WealthPlan::for($user, [
            'monthly_savings' => 1000,
            'monthly_expenses' => 2000,
            'return' => 0,
            'withdrawal' => 4,
        ]);

        // 2,000 × 12 ÷ 4 % = 600,000
        $this->assertEqualsWithDelta(600000.0, $plan['fire_number'], 0.001);
        $this->assertEqualsWithDelta(600000.0, $plan['target'], 0.001);
        // 1,000 × 12 months × 50 years = 600,000
        $this->assertSame(50, $plan['years_to_target']);
        $this->assertSame(2076, $plan['target_year']);
        $this->assertEqualsWithDelta(2000.0, $plan['passive_at_target'], 0.001);
    }

    public function test_starting_wealth_counts_and_return_compounds(): void
    {
        [$user] = $this->memberWithHousehold();
        NetWorthItem::factory()->create(['user_id' => $user->id, 'value' => 100000]);

        $plan = WealthPlan::for($user, [
            'monthly_savings' => 500,
            'return' => 6,
            'withdrawal' => 4,
            'target' => 200000,
        ]);

        $this->assertEqualsWithDelta(100000.0, $plan['start'], 0.001);
        $this->assertEqualsWithDelta(200000.0, $plan['target'], 0.001);

        // One year of 6 % monthly compounding on 100,000 plus 500 per month.
        $expected = 100000.0;

        for ($month = 0; $month < 12; $month++) {
            $expected = $expected * (1 + 0.06 / 12) + 500;
        }

        $this->assertEqualsWithDelta($expected, $plan['series'][1]['value'], 0.5);
        $this->assertNotNull($plan['years_to_target']);
        $this->assertGreaterThan(0, $plan['years_to_target']);
    }

    public function test_savings_plans_are_summed_to_a_monthly_rate(): void
    {
        [$user] = $this->memberWithHousehold();
        $portfolio = Portfolio::factory()->for($user)->create();
        SavingsPlan::create(['portfolio_id' => $portfolio->id, 'symbol' => 'VWCE.DE', 'name' => 'FTSE All-World', 'amount' => 300, 'frequency' => 'monthly', 'day_of_month' => 1, 'next_date' => today(), 'active' => true]);
        SavingsPlan::create(['portfolio_id' => $portfolio->id, 'symbol' => 'IWDA.AS', 'name' => 'MSCI World', 'amount' => 300, 'frequency' => 'quarterly', 'day_of_month' => 1, 'next_date' => today(), 'active' => true]);
        SavingsPlan::create(['portfolio_id' => $portfolio->id, 'symbol' => 'OLD.DE', 'name' => 'Paused', 'amount' => 999, 'frequency' => 'monthly', 'day_of_month' => 1, 'next_date' => today(), 'active' => false]);

        $plan = WealthPlan::for($user);

        // 300 monthly + 300 quarterly (=100/month); the inactive plan is ignored.
        $this->assertEqualsWithDelta(400.0, $plan['savings_plans'], 0.001);
    }

    public function test_already_reached_target_needs_no_years(): void
    {
        [$user] = $this->memberWithHousehold();
        NetWorthItem::factory()->create(['user_id' => $user->id, 'value' => 500000]);

        $plan = WealthPlan::for($user, ['target' => 400000]);

        $this->assertSame(0, $plan['years_to_target']);
        $this->assertSame(2026, $plan['target_year']);
    }

    public function test_page_shows_the_target_year(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('wealth-plan.index')
            ->set('monthlySavings', '1000')
            ->set('monthlyExpenses', '2000')
            ->set('return', '0')
            ->set('withdrawal', '4')
            ->assertHasNoErrors()
            ->assertSee('2076');
    }
}
