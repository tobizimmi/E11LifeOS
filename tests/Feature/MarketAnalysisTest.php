<?php

namespace Tests\Feature;

use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\WatchlistItem;
use App\Support\Portfolio\MarketAnalysis;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\StockScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class MarketAnalysisTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-01 12:00'));
        Http::fake(['*' => Http::response([], 404)]);
    }

    /**
     * Daily closes for the last 270 days, rising (or falling) by $step per day.
     */
    private function prices(string $symbol, float $start, float $step): void
    {
        $rows = [];

        for ($day = 270; $day >= 1; $day--) {
            $rows[] = ['symbol' => $symbol, 'date' => today()->subDays($day)->toDateString(), 'close' => $start + (270 - $day) * $step];
        }

        SecurityPrice::insert($rows);
    }

    public function test_points_system_rates_figures_chart_sector_and_market(): void
    {
        $strong = StockScore::rate(
            ['pe_ratio' => 12.0, 'price_to_cashflow' => 8.0, 'operating_margins' => 0.2, 'return_on_equity' => 0.18, 'revenue_growth' => 0.12, 'earnings_growth' => 0.15, 'debt_to_equity' => 0.4, 'dividend_yield' => 0.03],
            ['trend' => 'up', 'momentum_6m' => 0.2], 'top', 'green',
        );
        $this->assertSame([24, 24, 100, 'buy'], [$strong['points'], $strong['max'], $strong['percent'], $strong['verdict']]);

        $weak = StockScore::rate(['pe_ratio' => -5.0, 'price_to_cashflow' => 40.0, 'operating_margins' => 0.02, 'revenue_growth' => -0.1, 'debt_to_equity' => 3.0], ['trend' => 'down', 'momentum_6m' => -0.3], 'bottom', 'red');
        $this->assertSame('avoid', $weak['verdict']);

        $this->assertSame('unknown', StockScore::rate(['pe_ratio' => 12.0])['verdict']);
    }

    public function test_market_page_shows_global_and_sector_analysis_and_ranks_own_shares(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $this->prices('^GSPC', 5000, 2);
        $this->prices('^GDAXI', 18000, 5);
        $this->prices('XLK', 200, 0.5);
        $this->prices('XLE', 90, -0.1);
        $this->prices('XLV', 100, 0.05);
        $this->prices('SAP.DE', 150, 0.3);
        Security::factory()->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'asset_type' => 'stock', 'sector' => 'Technology', 'fundamentals' => ['pe_ratio' => 12.0, 'price_to_cashflow' => 8.0, 'operating_margins' => 0.2, 'revenue_growth' => 0.12]]);
        WatchlistItem::create(['user_id' => $user->id, 'symbol' => 'SAP.DE', 'name' => 'SAP SE']);

        Volt::test('portfolio.market')->call('load')
            ->assertSee('Globalanalyse')->assertSee('Börsenampel: günstig')
            ->assertSee('Branchenanalyse')->assertSee('Technologie')
            ->assertSee('SAP SE')->assertSee('Attraktiv – Kaufkandidat');

        $sectors = MarketAnalysis::sectorRanking(app(MarketData::class));
        $this->assertSame('XLK', $sectors[0]['symbol']);
        $this->assertSame('top', MarketAnalysis::sectorStanding($sectors, 'Technology'));
        $this->assertSame('bottom', MarketAnalysis::sectorStanding($sectors, 'Energy'));

        // The share's page uses today's market result for the points.
        $this->get(route('portfolio.security', ['symbol' => 'SAP.DE']))->assertOk()->assertSee('Punktesystem')->assertSee('unter den stärksten');
    }
}
