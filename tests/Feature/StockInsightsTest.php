<?php

namespace Tests\Feature;

use App\Models\NewsArticle;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\Security;
use App\Models\WatchlistItem;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\Positions;
use App\Support\Portfolio\StockInsights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class StockInsightsTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-01 12:00');
    }

    public function test_insider_trades_analyst_votes_and_yearly_figures_are_loaded_and_shown(): void
    {
        Http::fake([
            'fc.yahoo.com*' => Http::response(''),
            'query1.finance.yahoo.com/v1/test/getcrumb' => Http::response('crumb123'),
            'query2.finance.yahoo.com/v10/finance/quoteSummary/*' => Http::response(['quoteSummary' => ['result' => [[
                'summaryDetail' => ['trailingPE' => ['raw' => 30.0]],
                'financialData' => ['recommendationKey' => 'buy', 'targetMeanPrice' => ['raw' => 240.0]],
                'assetProfile' => ['sector' => 'Technology', 'irWebsite' => 'https://investor.apple.com/'],
                'netSharePurchaseActivity' => ['period' => '6m', 'buyInfoCount' => ['raw' => 1], 'sellInfoCount' => ['raw' => 9], 'netInfoShares' => ['raw' => -120000]],
                'insiderTransactions' => ['transactions' => [
                    ['filerName' => 'COOK TIMOTHY D', 'filerRelation' => 'Chief Executive Officer', 'transactionText' => 'Sale at price 220.00 per share.', 'startDate' => ['raw' => 1790000000], 'shares' => ['raw' => 100000], 'value' => ['raw' => 22000000]],
                ]],
                'earnings' => ['financialsChart' => ['yearly' => [
                    ['date' => 2023, 'revenue' => ['raw' => 383e9], 'earnings' => ['raw' => 97e9]],
                    ['date' => 2024, 'revenue' => ['raw' => 391e9], 'earnings' => ['raw' => 94e9]],
                    ['date' => 2025, 'revenue' => ['raw' => 416e9], 'earnings' => ['raw' => 112e9]],
                ]]],
                'recommendationTrend' => ['trend' => [['period' => '0m', 'strongBuy' => 8, 'buy' => 20, 'hold' => 10, 'sell' => 1, 'strongSell' => 1]]],
                'upgradeDowngradeHistory' => ['history' => [['epochGradeDate' => 1790500000, 'firm' => 'Morgan Stanley', 'toGrade' => 'Overweight', 'fromGrade' => 'Equal-Weight', 'action' => 'up']]],
                'calendarEvents' => ['earnings' => ['earningsDate' => [['raw' => 1793000000]]]],
            ]]]]),
            '*' => Http::response([], 404),
        ]);

        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $apple = Security::factory()->create(['symbol' => 'AAPL', 'name' => 'Apple Inc.', 'currency' => 'USD', 'last_price' => 200.0, 'price_updated_at' => now(), 'fundamentals' => ['pe_ratio' => 28.0], 'fundamentals_updated_at' => now()]);

        // Key figures loaded before this change are completed although they are fresh.
        $figures = app(MarketData::class)->fundamentals($apple);
        $this->assertSame([1, 9], [$figures['insider']['buys'], $figures['insider']['sells']]);
        $this->assertSame('COOK TIMOTHY D', $figures['insider']['transactions'][0]['name']);
        $this->assertSame(['2023', '2024', '2025'], array_column($figures['financials'], 'year'));
        $this->assertSame('https://investor.apple.com/', StockInsights::reportUrl($apple->fresh()));

        $this->assertSame(['score' => -80, 'level' => 'strong_sell'], array_intersect_key(StockInsights::insiderBarometer($figures), ['score' => 0, 'level' => 0]));
        $consensus = StockInsights::consensus($figures, 200.0);
        $this->assertSame(['buy', 28, 10, 2, 20.0], [$consensus['level'], $consensus['buy'], $consensus['hold'], $consensus['sell'], $consensus['upside']]);
        $this->assertSame(4.2, StockInsights::financials($figures)['revenue_cagr']);

        NewsArticle::create(['symbol' => 'AAPL', 'title' => 'Apple plant neues Werk in Indien', 'url' => 'https://example.com/1', 'url_hash' => sha1('1'), 'source' => 'Test', 'published_at' => now()]);
        NewsArticle::create(['symbol' => 'AAPL', 'title' => 'Aktie schließt im Minus', 'url' => 'https://example.com/2', 'url_hash' => sha1('2'), 'source' => 'Test', 'published_at' => now()->subHour()]);

        $this->get(route('portfolio.security', ['symbol' => 'AAPL']))->assertOk()
            ->assertSee('Insider verkaufen viel')
            ->assertSee('28 kaufen, 10 halten, 2 verkaufen')
            ->assertSee('Morgan Stanley')
            ->assertSee('Apple plant neues Werk in Indien')
            ->assertDontSee('Aktie schließt im Minus</a>', false)
            ->assertSee('https://investor.apple.com/');

        $portfolio = Portfolio::factory()->for($user)->create();
        PortfolioTransaction::factory()->for($portfolio)->create(['symbol' => 'AAPL', 'type' => 'buy', 'quantity' => 10, 'price' => 150, 'currency' => 'USD']);
        Positions::recalculate($portfolio);

        Volt::test('portfolio.insights')
            ->assertSee('Apple Inc.')
            ->assertSee('Kaufen')
            ->assertSee('Insider verkaufen viel')
            ->assertSee('Apple plant neues Werk in Indien')
            ->assertSee('1 ↑ 9 ↓')
            ->set('scope', 'market')
            ->assertDontSee('1 ↑ 9 ↓');
    }

    public function test_the_market_barometer_shows_other_shares_than_the_own_ones(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $insider = fn (int $buys, int $sells) => ['insider' => ['period' => '6m', 'buys' => $buys, 'sells' => $sells, 'net_shares' => null, 'transactions' => []]];
        Security::factory()->create(['symbol' => 'MINE', 'name' => 'Eigene AG', 'fundamentals' => $insider(5, 0)]);
        Security::factory()->create(['symbol' => 'OTHER', 'name' => 'Fremde Corp', 'scanned_at' => now(), 'fundamentals' => $insider(4, 1)]);
        WatchlistItem::create(['user_id' => $user->id, 'symbol' => 'MINE', 'name' => 'Eigene AG']);

        Volt::test('portfolio.insights')->assertSee('Eigene AG')->assertDontSee('Fremde Corp')
            ->set('scope', 'market')->assertSee('Fremde Corp')->assertSee('Weitere Aktien im Markt');
    }
}
