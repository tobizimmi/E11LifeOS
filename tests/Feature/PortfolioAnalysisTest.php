<?php

namespace Tests\Feature;

use App\Models\Portfolio;
use App\Models\PortfolioSetting;
use App\Models\PortfolioTransaction;
use App\Models\PriceAlert;
use App\Models\SavingsPlan;
use App\Models\Security;
use App\Models\TargetAllocation;
use App\Models\User;
use App\Support\Portfolio\Analytics;
use App\Support\Portfolio\Positions;
use App\Support\Portfolio\TaxReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PortfolioAnalysisTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 12:00'));
        // Quotes come from the database (fresh), nothing is fetched.
        Http::preventStrayRequests();
        Http::fake(['api.pushover.net/*' => Http::response(['status' => 1])]);
    }

    /**
     * @return array{0: User, 1: Portfolio}
     */
    private function investor(): array
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        return [$user, Portfolio::factory()->for($user)->create()];
    }

    private function security(string $symbol, float $price, string $type = 'stock'): Security
    {
        return Security::factory()->create(['symbol' => $symbol, 'name' => $symbol.' AG', 'last_price' => $price, 'previous_close' => $price, 'asset_type' => $type, 'price_updated_at' => now()]);
    }

    public function test_realised_gains_follow_fifo_and_share_losses_stay_in_their_pot(): void
    {
        [, $portfolio] = $this->investor();
        $add = fn (array $attributes) => PortfolioTransaction::factory()->for($portfolio)->create($attributes + ['fees' => 0, 'taxes' => 0]);

        $add(['symbol' => 'SAP.DE', 'type' => 'buy', 'quantity' => 10, 'price' => 100, 'executed_on' => '2025-01-10']);
        $add(['symbol' => 'SAP.DE', 'type' => 'buy', 'quantity' => 10, 'price' => 150, 'executed_on' => '2025-06-10']);
        // FIFO: 15 sold = 10 × 100 + 5 × 150 = 1750 cost; proceeds 15 × 200 − 10 fees = 2990.
        $add(['symbol' => 'SAP.DE', 'type' => 'sell', 'quantity' => 15, 'price' => 200, 'fees' => 10, 'executed_on' => '2026-03-01']);
        $add(['symbol' => 'BAD.DE', 'type' => 'buy', 'quantity' => 10, 'price' => 100, 'executed_on' => '2025-02-01']);
        $add(['symbol' => 'BAD.DE', 'type' => 'sell', 'quantity' => 10, 'price' => 80, 'executed_on' => '2026-04-01']);
        $add(['symbol' => 'ETF', 'type' => 'buy', 'quantity' => 10, 'price' => 50, 'executed_on' => '2025-02-01']);
        $add(['symbol' => 'ETF', 'type' => 'sell', 'quantity' => 10, 'price' => 40, 'executed_on' => '2026-04-01']);
        $add(['symbol' => 'SAP.DE', 'type' => 'dividend', 'quantity' => 5, 'price' => 2.2, 'executed_on' => '2026-05-20']);
        $add(['symbol' => 'SAP.DE', 'type' => 'sell', 'quantity' => 5, 'price' => 300, 'executed_on' => '2027-01-05']);

        $report = TaxReport::build($portfolio->transactions()->get(), 2026, 1000, 0, ['ETF' => 'etf']);

        $this->assertSame([1240.0, -200.0, 0.0, -100.0, 11.0], [$report['share_gains'], $report['share_losses'], $report['other_gains'], $report['other_losses'], $report['dividends']]);
        // (1240 − 200) + (−100) + 11 = 951 → below the allowance, no tax.
        $this->assertSame([951.0, 951.0, 0.0, 0.0], [$report['net'], $report['allowance_used'], $report['taxable'], $report['tax']]);

        $withChurch = TaxReport::build($portfolio->transactions()->get(), 2026, 500, 0.09, ['ETF' => 'etf']);
        // 451 € taxable × 24.45 % capital gains tax × 1.145 (Soli and church tax on it) ≈ 126.26 €.
        $this->assertSame([451.0, 126.26], [$withChurch['taxable'], $withChurch['tax']]);

        Volt::test('portfolio.taxes')->set('year', 2026)->assertSee('951,00 €');
    }

    public function test_savings_plans_are_booked_on_their_day_and_notify_via_pushover(): void
    {
        [$user, $portfolio] = $this->investor();
        $this->security('VWCE.DE', 125);
        PortfolioSetting::for($user)->fill(['pushover_user_key' => 'u-key', 'pushover_app_token' => 'a-token'])->save();

        Volt::test('portfolio.savings')
            ->call('newPlan')
            ->set('form.symbol', 'VWCE.DE')
            ->set('form.amount', '250')
            ->set('form.day_of_month', '1')
            ->call('savePlan')
            ->assertHasNoErrors();

        $plan = SavingsPlan::sole();
        $this->assertSame('2026-10-01', $plan->next_date->toDateString());

        $this->artisan('portfolio:execute-savings-plans')->expectsOutputToContain('executed: 0');

        $this->travelTo(Carbon::parse('2026-10-01 09:15'));
        $this->artisan('portfolio:execute-savings-plans')->expectsOutputToContain('executed: 1');

        $purchase = $portfolio->transactions()->sole();
        $this->assertSame(['buy', 2.0, 125.0, '2026-10-01'], [$purchase->type, $purchase->quantity, $purchase->price, $purchase->executed_on->toDateString()]);
        $this->assertSame(['2026-11-01', 2.0], [$plan->fresh()->next_date->toDateString(), $portfolio->positions()->sole()->quantity]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'pushover') && $request['user'] === 'u-key' && $request['token'] === 'a-token');

        Volt::test('portfolio.savings')->call('toggle', $plan->id);
        $this->assertFalse($plan->fresh()->active);
    }

    public function test_price_alerts_and_stop_losses_notify_once(): void
    {
        [$user, $portfolio] = $this->investor();
        $sap = $this->security('SAP.DE', 95);

        Volt::test('portfolio.alerts')
            ->set('form.symbol', 'SAP.DE')
            ->set('form.condition', 'below')
            ->set('form.target_price', '100,00')
            ->call('saveAlert')
            ->assertHasNoErrors();

        PortfolioTransaction::factory()->for($portfolio)->create(['symbol' => 'SAP.DE', 'quantity' => 1, 'price' => 120]);
        Positions::recalculate($portfolio);
        $position = $portfolio->positions()->sole();
        $position->update(['stop_loss_price' => 96]);

        $this->artisan('portfolio:check-alerts')->expectsOutputToContain('Notifications sent: 2');
        $this->assertNotNull(PriceAlert::sole()->triggered_at);
        $this->assertNotNull($position->fresh()->stop_loss_notified_at);

        $this->artisan('portfolio:check-alerts')->expectsOutputToContain('Notifications sent: 0');

        // Back above the stop: the next crossing notifies again.
        $sap->update(['last_price' => 99]);
        $this->artisan('portfolio:check-alerts')->expectsOutputToContain('Notifications sent: 0');
        $this->assertNull($position->fresh()->stop_loss_notified_at);

        Volt::test('portfolio.alerts')->call('rearm', PriceAlert::sole()->id);
        $this->assertNull(PriceAlert::sole()->triggered_at);
    }

    public function test_one_button_sets_up_profit_alerts_above_the_buy_price_and_notifies(): void
    {
        [$user, $portfolio] = $this->investor();
        $sap = $this->security('SAP.DE', 100);
        PortfolioTransaction::factory()->for($portfolio)->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'quantity' => 10, 'price' => 100, 'fees' => 0]);
        Positions::recalculate($portfolio);
        $position = $portfolio->positions()->sole();
        $this->assertSame(100.0, $position->avg_buy_price);

        // The edit sheet offers the button with the computed target prices.
        Volt::test('portfolio.index')->call('editPosition', $position->id)
            ->assertSee(__('Set up profit alerts'))
            ->assertSee('130,00');

        Volt::test('portfolio.index')->call('setupGainAlerts', $position->id);

        $alerts = PriceAlert::where('user_id', $user->id)->orderBy('gain_percent')->get();
        $this->assertSame([5, 10, 20, 30], $alerts->pluck('gain_percent')->all());
        $this->assertSame([105.0, 110.0, 120.0, 130.0], $alerts->pluck('target_price')->all());
        $this->assertTrue($alerts->every(fn (PriceAlert $alert) => $alert->condition === 'above' && $alert->symbol === 'SAP.DE'));

        // The button is idempotent: a second press adds nothing.
        Volt::test('portfolio.index')->call('setupGainAlerts', $position->id);
        $this->assertSame(4, PriceAlert::where('user_id', $user->id)->count());

        // At +12 % only the 5 % and 10 % alerts trigger, each once.
        $sap->update(['last_price' => 112, 'previous_close' => 112]);
        $this->artisan('portfolio:check-alerts')->expectsOutputToContain('Notifications sent: 2');
        $this->assertSame(2, PriceAlert::whereNotNull('triggered_at')->count());
        $this->artisan('portfolio:check-alerts')->expectsOutputToContain('Notifications sent: 0');
    }

    public function test_profit_alerts_are_not_offered_without_an_average_buy_price(): void
    {
        [$user, $portfolio] = $this->investor();
        $this->security('MANUAL.DE', 50);
        $position = $portfolio->positions()->create(['symbol' => 'MANUAL.DE', 'name' => 'Manual AG', 'quantity' => 1, 'avg_buy_price' => 0, 'currency' => 'EUR', 'manual' => true]);

        Volt::test('portfolio.index')->call('setupGainAlerts', $position->id);
        $this->assertSame(0, PriceAlert::where('user_id', $user->id)->count());
    }

    public function test_rebalancing_suggests_trades_towards_the_target_weights(): void
    {
        $analytics = app(Analytics::class);
        $current = ['A' => ['name' => 'A', 'value' => 700.0], 'B' => ['name' => 'B', 'value' => 300.0]];

        $plan = collect($analytics->rebalance($current, ['A' => 50, 'B' => 50]))->keyBy('symbol');
        $this->assertSame([-200.0, 200.0], [$plan['A']['trade'], $plan['B']['trade']]);

        // 1000 € new money, buying only: targets 1000 € each, so A gets its gap of 300 €, B 700 €.
        $buyOnly = collect($analytics->rebalance($current, ['A' => 50, 'B' => 50], 1000, true))->keyBy('symbol');
        $this->assertSame([300.0, 700.0], [$buyOnly['A']['trade'], $buyOnly['B']['trade']]);

        [$user, $portfolio] = $this->investor();
        $this->security('SAP.DE', 100);
        PortfolioTransaction::factory()->for($portfolio)->create(['symbol' => 'SAP.DE', 'quantity' => 10, 'price' => 90]);
        Positions::recalculate($portfolio);

        Volt::test('portfolio.rebalancing')
            ->set('targets.SAP~DE', '120')
            ->call('saveTargets')
            ->assertHasErrors('targets')
            ->set('targets.SAP~DE', '60,5')
            ->call('saveTargets')
            ->assertHasNoErrors()
            ->assertSee('60,5');

        $this->assertSame(60.5, TargetAllocation::where('user_id', $user->id)->sole()->target_percent);
    }

    public function test_analysis_compares_with_the_benchmark_and_dividends_are_summed(): void
    {
        [$user, $portfolio] = $this->investor();
        PortfolioSetting::for($user)->fill(['benchmark_symbol' => '^GDAXI'])->save();
        $this->security('SAP.DE', 110);
        $this->security('IFX.DE', 30);

        foreach (range(0, 40) as $day) {
            $date = now()->subDays(40 - $day);
            $portfolio->snapshots()->create(['taken_at' => $date->copy()->setTime(18, 0), 'total_value' => 1000 + $day * 5, 'total_cost' => 1000]);
            DB::table('security_prices')->insert([
                ['symbol' => '^GDAXI', 'date' => $date->toDateString(), 'close' => 20000 + $day * 20],
                ['symbol' => 'SAP.DE', 'date' => $date->toDateString(), 'close' => 100 + sin($day) * 3],
                ['symbol' => 'IFX.DE', 'date' => $date->toDateString(), 'close' => 30 + sin($day) * 1.5],
            ]);
        }

        PortfolioTransaction::factory()->for($portfolio)->create(['symbol' => 'SAP.DE', 'quantity' => 10, 'price' => 100, 'fees' => 0, 'executed_on' => '2025-01-01']);
        PortfolioTransaction::factory()->for($portfolio)->create(['symbol' => 'IFX.DE', 'name' => 'Infineon', 'isin' => null, 'quantity' => 10, 'price' => 25, 'fees' => 0, 'executed_on' => '2025-01-01']);
        PortfolioTransaction::factory()->for($portfolio)->create(['symbol' => 'SAP.DE', 'type' => 'dividend', 'quantity' => 10, 'price' => 2.35, 'fees' => 0, 'taxes' => 6.2, 'executed_on' => '2026-05-20']);
        Positions::recalculate($portfolio);

        $analysis = Volt::test('portfolio.analysis')->assertSee('^GDAXI');
        $performance = $analysis->viewData('performance');
        $this->assertSame(20.0, end($performance)['value']);
        $this->assertSame(4.0, end($performance)['compare']);
        $this->assertSame(['SAP.DE', 'IFX.DE'], $analysis->viewData('correlation')['symbols']);
        $this->assertSame(1.0, $analysis->viewData('correlation')['matrix']['SAP.DE']['IFX.DE']);
        $this->assertSame(['SAP SE', 'Infineon'], array_column($analysis->set('allocation', 'position')->viewData('allocationRows'), 'label'));

        $dividends = Volt::test('portfolio.dividends');
        $report = $dividends->viewData('report');
        // 23.50 € gross − 6.20 € withholding tax = 17.30 € net.
        $this->assertSame([17.3, 17.3, [2026 => 17.3]], [$report['trailing'], $report['expected'], $report['by_year']]);

        foreach (['analysis', 'dividends', 'taxes', 'rebalancing', 'savings', 'alerts'] as $page) {
            $this->get(route('portfolio.'.$page))->assertOk();
        }
    }
}
