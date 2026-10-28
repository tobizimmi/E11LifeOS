<?php

namespace Tests\Feature;

use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\Security;
use App\Support\Portfolio\Positions;
use App\Support\Portfolio\StrategyCoach;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * The three-thirds strategy coach: depot health, three-thirds buy state and sell signals.
 */
class PortfolioStrategyTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function row(string $symbol, float $cost, float $weight, ?float $gainPercent = null, ?string $sector = 'Technologie', ?float $price = null, float $avgBuyPrice = 100.0): array
    {
        return [
            'symbol' => $symbol,
            'name' => $symbol.' AG',
            'sector' => $sector,
            'cost' => $cost,
            'weight' => $weight,
            'gain_percent' => $gainPercent,
            'price' => $price,
            'avg_buy_price' => $avgBuyPrice,
        ];
    }

    public function test_cluster_risk_and_ground_rules_are_flagged(): void
    {
        $result = StrategyCoach::analyse([
            $this->row('A.DE', cost: 5000, weight: 5.0, sector: 'Technologie'),
            $this->row('B.DE', cost: 2000, weight: 2.0, sector: 'Finanzen'),
        ]);

        $checks = collect($result['health']['checks'])->keyBy('key');
        $this->assertSame('bad', $checks['cluster']['status']);
        $this->assertStringContainsString('A.DE AG', $checks['cluster']['detail']);
        // Two sectors is below the minimum of five.
        $this->assertSame('bad', $checks['sectors']['status']);
        $this->assertSame(2, $result['health']['sectors']);
        $this->assertSame(5.0, $result['health']['top_weight']);

        $cluster = collect($result['signals'])->firstWhere('type', 'cluster');
        $this->assertSame('A.DE', $cluster['symbol']);
    }

    public function test_healthy_depot_scores_well(): void
    {
        $rows = [];
        $sectors = ['Technologie', 'Finanzen', 'Gesundheit', 'Konsum', 'Industrie'];
        foreach (range(0, 24) as $i) {
            $rows[] = $this->row('S'.$i, cost: 1000, weight: 2.0, sector: $sectors[$i % 5]);
        }

        $result = StrategyCoach::analyse($rows);

        $this->assertSame(5, $result['health']['sectors']);
        $this->assertSame(100, $result['health']['score']);
        $this->assertSame([], $result['signals']);
    }

    public function test_three_thirds_state_follows_the_number_of_buys(): void
    {
        // One third per buy: three buys = full, one buy = first third, two buys = second third.
        $result = StrategyCoach::analyse(
            [
                $this->row('FULL.DE', cost: 3000, weight: 3.0, price: 90, avgBuyPrice: 100),
                $this->row('ONE.DE', cost: 1000, weight: 1.0, price: 90, avgBuyPrice: 100),
                $this->row('TWO.DE', cost: 2000, weight: 2.0, price: 90, avgBuyPrice: 100),
            ],
            lastBuyPrices: ['ONE.DE' => 100.0, 'TWO.DE' => 100.0, 'FULL.DE' => 100.0],
            buyCounts: ['FULL.DE' => 3, 'ONE.DE' => 1, 'TWO.DE' => 2],
        );

        $positions = collect($result['positions'])->keyBy('symbol');
        $this->assertSame(3, $positions['FULL.DE']['thirds_filled']);
        $this->assertNull($positions['FULL.DE']['next_tranche']);
        $this->assertSame(1, $positions['ONE.DE']['thirds_filled']);
        $this->assertSame(2, $positions['TWO.DE']['thirds_filled']);

        // Next third is bought 10 % below the last buy (100 → 90); the price is at 90, so it is reached.
        $next = $positions['ONE.DE']['next_tranche'];
        $this->assertSame(2, $next['third']);
        $this->assertSame(90.0, $next['trigger_price']);
        $this->assertSame(85.0, $next['trigger_price_max']);
        $this->assertTrue($next['reached']);

        $this->assertSame('buy_tranche', collect($result['signals'])->firstWhere('symbol', 'ONE.DE')['type']);
    }

    #[DataProvider('sellCases')]
    public function test_sell_signals_trigger_against_the_average_buy_price(float $gainPercent, array $expectedTriggered): void
    {
        $result = StrategyCoach::analyse([$this->row('X.DE', cost: 1000, weight: 1.0, gainPercent: $gainPercent)]);

        $triggered = collect($result['positions'][0]['sell_signals'])->where('triggered', true)->pluck('key')->all();
        $this->assertSame($expectedTriggered, $triggered);
    }

    /**
     * @return list<array{0: float, 1: list<string>}>
     */
    public static function sellCases(): array
    {
        return [
            [10.0, []],
            [26.0, ['first_third']],
            [36.0, ['first_third', 'second_third', 'trailing']],
            [120.0, ['first_third', 'second_third', 'trailing', 'all']],
        ];
    }

    public function test_strategy_page_renders_for_an_investor(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $this->actingAs($user);
        $portfolio = Portfolio::factory()->for($user)->create();

        Security::factory()->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'sector' => 'Technologie', 'last_price' => 90, 'previous_close' => 90, 'price_updated_at' => now()]);
        PortfolioTransaction::factory()->for($portfolio)->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'quantity' => 10, 'price' => 100, 'fees' => 0]);
        Positions::recalculate($portfolio);

        $page = Volt::test('portfolio.strategy');
        $page->assertOk()->assertSee(__('Three-thirds state'))->assertSee('SAP SE');

        $coach = $page->viewData('coach');
        $this->assertSame('SAP.DE', $coach['positions'][0]['symbol']);
        $this->assertSame(1, $coach['positions'][0]['thirds_filled']);

        $this->get(route('portfolio.strategy'))->assertOk();
    }
}
