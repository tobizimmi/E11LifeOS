<?php

namespace Tests\Feature;

use App\Models\NewsArticle;
use App\Models\Security;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Market analysis and news for the mobile app.
 */
class ApiPortfolioMarketTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_news_of_the_own_securities_can_be_filtered_and_searched(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $headers = $this->headersFor($user);
        WatchlistItem::create(['user_id' => $user->id, 'symbol' => 'SAP.DE']);
        NewsArticle::create(['symbol' => 'SAP.DE', 'title' => 'SAP hebt Prognose an', 'url' => 'https://n.example/1', 'url_hash' => sha1('1'), 'source' => 'Example', 'published_at' => now()]);
        NewsArticle::create(['symbol' => 'OTHER', 'title' => 'Fremde Meldung', 'url' => 'https://n.example/2', 'url_hash' => sha1('2'), 'source' => 'Example', 'published_at' => now()]);
        NewsArticle::create(['symbol' => null, 'title' => 'DAX schliesst fester', 'url' => 'https://n.example/3', 'url_hash' => sha1('3'), 'source' => 'Example', 'published_at' => now()]);

        $this->getJson('/api/v1/portfolio-reports/news', $headers)->assertOk()->assertJsonCount(1, 'data.articles')->assertJsonPath('data.articles.0.title', 'SAP hebt Prognose an');
        $this->getJson('/api/v1/portfolio-reports/news?filter=market', $headers)->assertOk()->assertJsonCount(1, 'data.articles')->assertJsonPath('data.articles.0.symbol', null);
        $this->getJson('/api/v1/portfolio-reports/news?filter=all&q=dax', $headers)->assertOk()->assertJsonCount(1, 'data.articles');
        $this->getJson('/api/v1/portfolio-reports/news?filter=nope', $headers)->assertUnprocessable();
    }

    public function test_the_market_analysis_answers_without_market_data(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        Http::fake(['*' => Http::response([], 500)]);

        $this->getJson('/api/v1/portfolio-reports/market', $this->headersFor($user))->assertOk()->assertJsonStructure(['data' => ['global' => ['rows', 'light'], 'sectors', 'scores']]);
    }

    public function test_discover_lists_indices_and_scan_results(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        Http::fake(['*' => Http::response([], 500)]);
        Security::create(['symbol' => 'AAA', 'name' => 'Alpha', 'last_price' => 90, 'previous_close' => 100, 'currency' => 'EUR', 'screeners' => ['day_losers'], 'scanned_at' => now()]);
        Security::create(['symbol' => 'BBB', 'name' => 'Beta', 'last_price' => 110, 'previous_close' => 100, 'currency' => 'EUR', 'screeners' => ['day_gainers'], 'scanned_at' => now()]);
        WatchlistItem::create(['user_id' => $user->id, 'symbol' => 'AAA']);
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/portfolio-reports/discover', $headers)->assertOk()
            ->assertJsonPath('data.results.0.symbol', 'AAA')->assertJsonPath('data.results.0.watched', true)->assertJsonPath('data.results.0.change', -10)
            ->assertJsonCount(1, 'data.results')->assertJsonPath('data.indices.0.name', 'DAX');
        $this->getJson('/api/v1/portfolio-reports/discover?screener=day_gainers', $headers)->assertOk()->assertJsonPath('data.results.0.symbol', 'BBB');
        $this->getJson('/api/v1/portfolio-reports/discover?screener=nope', $headers)->assertOk()->assertJsonPath('data.screener', 'day_losers');
    }
}
