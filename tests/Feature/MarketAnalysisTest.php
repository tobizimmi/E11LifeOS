<?php

namespace Tests\Feature;

use App\Models\PortfolioSetting;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\StockRating;
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

    public function test_points_system_rates_the_figures_with_zero_to_three_points_and_weighting(): void
    {
        $figures = ['pe_ratio' => 11.0, 'market_cap' => 150e9, 'debt_to_equity' => 0.2, 'price_to_book' => 1.5, 'dividend_yield' => 0.045, 'revenue_growth' => 0.12, 'earnings_growth' => 0.2, 'return_on_equity' => 0.25, 'analyst_votes' => ['strong_buy' => 10, 'buy' => 2, 'hold' => 0, 'sell' => 0, 'strong_sell' => 0]];
        $technical = ['price' => 80.0, 'high_52w' => 100.0, 'trend' => 'up', 'momentum_6m' => 0.15];
        $score = StockScore::rate($figures, $technical, ['sector_share' => 0.05, 'sector' => 'Technology']);
        $rows = collect($score['rows'])->keyBy('key');

        // KGV 11 → 3 points, counting double; 20 % below the high → 3; the business model waits for the own rating.
        $this->assertSame([3, 2, 6], [$rows['pe']['points'], $rows['pe']['weight'], $rows['pe']['score']]);
        $this->assertSame([3, 3, 3, 3], [$rows['high_low']['points'], $rows['diversification']['points'], $rows['technical']['points'], $rows['analysts']['points']]);
        $this->assertNull($rows['business_model']['points']);
        $this->assertSame([6, 'buy'], [$score['open'], $score['verdict']]);

        // Own points rate the soft factors and overrule figures; a factor can be switched off.
        $own = StockScore::rate($figures, $technical, ['ratings' => ['business_model' => 1, 'pe' => 0], 'weights' => ['dividend' => 0, 'pe' => 3]]);
        $ownRows = collect($own['rows'])->keyBy('key');
        $this->assertSame([0, 3, 0, true], [$ownRows['pe']['points'], $ownRows['pe']['weight'], $ownRows['pe']['score'], $ownRows['pe']['own']]);
        $this->assertSame(1, $ownRows['business_model']['score']);
        $this->assertFalse($ownRows->has('dividend'));

        $weak = StockScore::rate(['pe_ratio' => -5.0, 'market_cap' => 50e6, 'debt_to_equity' => 3.0, 'price_to_book' => 6.0, 'dividend_yield' => 0.0, 'revenue_growth' => -0.1, 'earnings_growth' => -0.2, 'return_on_equity' => -0.1], ['price' => 30.0, 'high_52w' => 100.0, 'trend' => 'down', 'momentum_6m' => -0.3]);
        $this->assertSame('avoid', $weak['verdict']);
        $this->assertSame('unknown', StockScore::rate(['pe_ratio' => 12.0])['verdict']);
    }

    public function test_own_points_and_weighting_are_saved(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $this->prices('SAP.DE', 150, 0.3);
        Security::factory()->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'asset_type' => 'stock', 'fundamentals' => ['pe_ratio' => 30.0]]);

        Volt::test('portfolio.security', ['symbol' => 'SAP.DE'])
            ->assertSee('Doppelte Gewichtung: Jeder Punkt zählt doppelt.')->assertSee('Geschäftsmodell')
            ->call('rateFactor', 'business_model', 3)
            ->call('rateFactor', 'pe', 2)->assertSee('eigene Bewertung')
            ->call('rateFactor', 'pe')
            ->call('rateFactor', 'unknown', 1)->assertStatus(422);
        $this->assertSame(['business_model' => 3], StockRating::where('user_id', $user->id)->sole()->points);

        Volt::test('portfolio.settings')->call('scorePreset', 'creative')->assertSet('scoreWeights.pe', '3')
            ->set('scoreWeights.news', '0')->call('saveScoreWeights');
        $weights = PortfolioSetting::for($user)->score_weights;
        $this->assertSame([3, 0, 1], [$weights['pe'], $weights['news'], $weights['business_model']]);
        Volt::test('portfolio.settings')->call('scorePreset', 'book');
        $this->assertNull(PortfolioSetting::for($user)->fresh()->score_weights);
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
        Security::factory()->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'asset_type' => 'stock', 'sector' => 'Technology', 'fundamentals' => ['pe_ratio' => 12.0, 'market_cap' => 200e9, 'debt_to_equity' => 0.25, 'price_to_book' => 3.0, 'dividend_yield' => 0.03, 'revenue_growth' => 0.12, 'earnings_growth' => 0.15, 'return_on_equity' => 0.25]]);
        WatchlistItem::create(['user_id' => $user->id, 'symbol' => 'SAP.DE', 'name' => 'SAP SE']);

        Volt::test('portfolio.market')->call('load')
            ->assertSee('Globalanalyse')->assertSee('Börsenampel: günstig')
            ->assertSee('Branchenanalyse')->assertSee('Technologie')
            ->assertSee('SAP SE')->assertSee('Attraktiv – Kaufkandidat');

        $sectors = MarketAnalysis::sectorRanking(app(MarketData::class));
        $this->assertSame('XLK', $sectors[0]['symbol']);
        $this->assertSame('top', MarketAnalysis::sectorStanding($sectors, 'Technology'));
        $this->assertSame('bottom', MarketAnalysis::sectorStanding($sectors, 'Energy'));

        $this->get(route('portfolio.security', ['symbol' => 'SAP.DE']))->assertOk()->assertSee('Punktesystem')->assertSee('Endergebnis');
    }
}
