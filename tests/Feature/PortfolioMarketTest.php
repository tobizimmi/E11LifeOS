<?php

namespace Tests\Feature;

use App\Models\NewsArticle;
use App\Models\Portfolio;
use App\Models\PortfolioRecommendation;
use App\Models\PortfolioSetting;
use App\Models\PortfolioTransaction;
use App\Models\Security;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\NewsFeed;
use App\Support\Portfolio\Positions;
use App\Support\Portfolio\RecommendationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PortfolioMarketTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-25 12:00'));
        Http::preventStrayRequests();
    }

    private function investor(): User
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        return $user;
    }

    /**
     * A year of daily closes: rising to $high, then falling by $drop % over the last weeks.
     */
    private function securityWithHistory(string $symbol, float $high, float $drop, array $fundamentals = []): Security
    {
        $days = 250;
        $rows = [];

        for ($i = 0; $i < $days; $i++) {
            $date = now()->subDays($days - $i)->toDateString();
            $price = $i < 220 ? $high * (0.8 + 0.2 * $i / 220) : $high * (1 - $drop / 100 * ($i - 219) / 30);
            $rows[] = ['symbol' => $symbol, 'date' => $date, 'close' => round($price, 4)];
        }

        DB::table('security_prices')->insert($rows);

        return Security::factory()->create([
            'symbol' => $symbol, 'name' => $symbol.' AG', 'market_cap' => 50_000_000_000, 'last_price' => end($rows)['close'],
            'previous_close' => $rows[count($rows) - 2]['close'], 'scanned_at' => now(), 'price_updated_at' => now(),
            'fundamentals' => $fundamentals + ['pe_ratio' => 14.0, 'debt_to_equity' => 0.4, 'revenue_growth' => 0.12, 'operating_margins' => 0.25, 'recommendation' => 'buy', 'dividend_yield' => 0.02],
            'fundamentals_updated_at' => now(),
        ]);
    }

    public function test_the_engine_recommends_quality_stocks_after_a_drop(): void
    {
        $user = $this->investor();
        $this->securityWithHistory('DIP.DE', 100, 25);
        $this->securityWithHistory('CALM.DE', 100, 2);
        $this->securityWithHistory('DEBT.DE', 100, 25, ['debt_to_equity' => 6.0]);

        $results = collect((new RecommendationEngine)->analyze(Security::all()))->keyBy('symbol');

        $this->assertSame(['DIP.DE'], $results->keys()->all());
        $dip = $results['DIP.DE'];
        $this->assertEqualsWithDelta(25.0, $dip['drop_percent'], 0.5);
        $this->assertLessThan(30, $dip['rsi']);
        $this->assertSame('strong_buy', $dip['rating']);
        $this->assertContains('Analysten-Konsens: Kaufen', $dip['reasons']);

        // A custom strategy with loose limits accepts all three.
        PortfolioSetting::for($user)->fill(['strategy' => 'custom', 'drop_threshold' => 1, 'drop_period_days' => 60, 'rsi_threshold' => 100, 'min_stability_score' => 0, 'max_debt_equity' => 10, 'max_pe_ratio' => 100, 'min_market_cap_bn' => 0])->save();
        $this->assertSame(3, RecommendationEngine::refreshFor($user));
        $this->assertSame(3, PortfolioRecommendation::where('user_id', $user->id)->count());

        PortfolioSetting::for($user)->fill(['strategy' => 'moderate'])->save();
        Volt::test('portfolio.recommendations')->call('refresh')->assertSee('DIP.DE AG')->assertDontSee('CALM.DE AG')->call('addToWatchlist', 'DIP.DE');
        $this->assertSame('DIP.DE', WatchlistItem::where('user_id', $user->id)->sole()->symbol);

        $backtest = Volt::test('portfolio.recommendations')->set('backtest.threshold', '10')->set('backtest.drop_days', '60')->set('backtest.hold_days', '10')->call('runBacktest')->get('backtestResult');
        $this->assertGreaterThan(0, $backtest['signals']);
        $this->assertSame(3, $backtest['symbols']);

        $this->assertEqualsWithDelta(100.0, RecommendationEngine::rsi(range(1, 30)), 0.01);
    }

    public function test_the_market_scan_stores_screener_results_and_key_figures(): void
    {
        Http::fake([
            'fc.yahoo.com*' => Http::response('', 404, ['Set-Cookie' => 'A3=abc; Domain=.yahoo.com; Path=/']),
            'query1.finance.yahoo.com/v1/test/getcrumb' => Http::response('crumb123'),
            'query1.finance.yahoo.com/v1/finance/screener/*' => Http::response(['finance' => ['result' => [['quotes' => [
                ['symbol' => 'INTC', 'quoteType' => 'EQUITY', 'longName' => 'Intel Corporation', 'marketCap' => 90_000_000_000, 'regularMarketPrice' => 20.5, 'regularMarketPreviousClose' => 22.0, 'currency' => 'USD'],
                ['symbol' => 'SPY', 'quoteType' => 'ETF', 'longName' => 'SPDR S&P 500'],
            ]]]]]),
            'query2.finance.yahoo.com/v10/finance/quoteSummary/*' => Http::response(['quoteSummary' => ['result' => [[
                'summaryDetail' => ['trailingPE' => ['raw' => 11.2], 'dividendYield' => ['raw' => 0.024], 'marketCap' => ['raw' => 91_000_000_000]],
                'financialData' => ['debtToEquity' => ['raw' => 45.0], 'revenueGrowth' => ['raw' => -0.02], 'operatingMargins' => ['raw' => 0.08], 'recommendationKey' => 'hold', 'targetMeanPrice' => ['raw' => 24.0]],
                'assetProfile' => ['sector' => 'Technology', 'country' => 'United States', 'industry' => 'Semiconductors'],
            ]]]]),
            'query1.finance.yahoo.com/v8/finance/chart/*' => Http::response([], 404),
        ]);

        $market = app(MarketData::class);
        $this->assertSame(['INTC'], $market->scan(['day_losers']));

        $intel = Security::firstWhere('symbol', 'INTC');
        $this->assertSame(['day_losers'], $intel->screeners);
        $this->assertEqualsWithDelta(-6.82, $intel->changePercent(), 0.01);

        $figures = $market->fundamentals($intel);
        $this->assertSame([11.2, 0.45, 'hold'], [$figures['pe_ratio'], $figures['debt_to_equity'], $figures['recommendation']]);
        $this->assertSame(['Technology', 'United States', 2.4], [$intel->fresh()->sector, $intel->fresh()->country, $intel->fresh()->dividend_yield]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'quoteSummary') && str_contains($request->url(), 'crumb=crumb123'));

        $this->investor();
        Volt::test('portfolio.discover')->assertSee('Intel Corporation');
        $this->get(route('portfolio.security', ['symbol' => 'INTC']))->assertOk()->assertSee('11,2');
    }

    public function test_news_are_fetched_parsed_and_pushed_to_owners(): void
    {
        $rss = '<?xml version="1.0"?><rss><channel>'
            .'<item><title>SAP hebt Prognose an</title><link>https://example.com/sap?utm=1</link><pubDate>Thu, 25 Sep 2026 08:00:00 +0200</pubDate><description>&lt;p&gt;Starkes Cloud-Geschäft&lt;/p&gt;</description><source>Handelsblatt</source></item>'
            .'<item><title>SAP: Analysten optimistisch</title><link>https://example.com/sap-2</link><pubDate>Thu, 25 Sep 2026 07:00:00 +0200</pubDate></item>'
            .'</channel></rss>';
        $atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><title>DAX im Plus</title><link href="https://example.com/dax"/><updated>2026-09-25T09:00:00Z</updated><summary>Markt</summary></entry></feed>';

        $this->assertSame('Starkes Cloud-Geschäft', NewsFeed::parse($rss)[0]['summary']);
        $this->assertSame('DAX im Plus', NewsFeed::parse($atom)[0]['title']);

        Http::fake([
            'news.google.com/*' => Http::response($rss),
            'api.pushover.net/*' => Http::response(['status' => 1]),
            '*' => Http::response($atom),
        ]);

        $user = $this->investor();
        $portfolio = Portfolio::factory()->for($user)->create();
        Security::factory()->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'isin' => 'DE0007164600', 'price_updated_at' => now()]);
        PortfolioTransaction::factory()->for($portfolio)->create(['symbol' => 'SAP.DE']);
        Positions::recalculate($portfolio);
        PortfolioSetting::for($user)->fill(['news_push_enabled' => true, 'pushover_user_key' => 'u', 'pushover_app_token' => 't'])->save();

        $this->artisan('portfolio:fetch-news')->expectsOutputToContain('notifications: 1')->assertSuccessful();

        $this->assertSame(2, NewsArticle::where('symbol', 'SAP.DE')->count());
        $this->assertSame(1, NewsArticle::whereNull('symbol')->count());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'pushover') && str_contains($request['message'], 'SAP hebt Prognose an'));
        // Headlines about the member's securities land in the "important" lane of the bell.
        $this->assertSame('important', $user->notifications()->sole()->data['category']);

        // Already announced articles are not pushed again.
        $this->artisan('portfolio:fetch-news')->expectsOutputToContain('New articles: 0, notifications: 0');

        Volt::test('portfolio.news')->assertSee('SAP hebt Prognose an')->assertDontSee('DAX im Plus')->set('filter', 'market')->assertSee('DAX im Plus');
        $this->get(route('portfolio.security', ['symbol' => 'SAP.DE']))->assertOk()->assertSee('SAP: Analysten optimistisch');
    }
}
