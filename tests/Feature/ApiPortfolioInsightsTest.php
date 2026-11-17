<?php

namespace Tests\Feature;

use App\Models\NewsArticle;
use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\Security;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Portfolio insights for the mobile app.
 */
class ApiPortfolioInsightsTest extends TestCase
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

    public function test_insights_show_the_own_positions_and_the_insider_barometer(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        Http::fake(['*' => Http::response([], 500)]);
        Security::create(['symbol' => 'SAP.DE', 'name' => 'SAP', 'last_price' => 100, 'previous_close' => 99, 'currency' => 'EUR', 'fundamentals' => []]);
        $portfolio = Portfolio::create(['user_id' => $user->id, 'name' => 'Depot']);
        PortfolioPosition::create(['portfolio_id' => $portfolio->id, 'symbol' => 'SAP.DE', 'name' => 'SAP', 'quantity' => 2, 'avg_price' => 80, 'currency' => 'EUR']);
        NewsArticle::create(['symbol' => 'SAP.DE', 'title' => 'SAP investiert in neue KI', 'url' => 'https://n.example/1', 'url_hash' => sha1('1'), 'source' => 'Example', 'published_at' => now()]);
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/portfolio-reports/insights', $headers)->assertOk()
            ->assertJsonPath('data.scope', 'mine')->assertJsonPath('data.checks.0.symbol', 'SAP.DE')->assertJsonPath('data.checks.0.news.title', 'SAP investiert in neue KI')
            ->assertJsonStructure(['data' => ['checks', 'buying', 'selling', 'updated_at']]);
        $this->getJson('/api/v1/portfolio-reports/insights?scope=market', $headers)->assertOk()->assertJsonPath('data.scope', 'market');
        $this->postJson('/api/v1/portfolio-reports/insights/refresh', [], $headers)->assertOk()->assertJsonStructure(['data' => ['updated']]);
    }
}
