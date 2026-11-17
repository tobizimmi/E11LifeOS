<?php

namespace Tests\Feature;

use App\Models\InstagramPost;
use App\Models\PortfolioSetting;
use App\Models\PriceAlert;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Instagram posts of the stock profile for the mobile app.
 */
class ApiPortfolioInstagramTest extends TestCase
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

    public function test_posts_are_filtered_watched_alerted_and_the_push_switched(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        Http::fake(['*' => Http::response([], 500)]);
        $idea = InstagramPost::create(['profile' => 'aktien', 'instagram_id' => '1', 'shortcode' => 'a', 'published_at' => now(), 'summary' => 'Kaufidee', 'tickers' => ['SAP.DE'], 'stock_name' => 'SAP', 'sentiment' => 'bullish', 'entry_price' => 100, 'tranches' => [100, 90], 'currency' => 'EUR']);
        InstagramPost::create(['profile' => 'aktien', 'instagram_id' => '2', 'shortcode' => 'b', 'published_at' => now()->subDay(), 'summary' => 'Meinung', 'sentiment' => 'neutral']);
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/portfolio-instagram', $headers)->assertOk()->assertJsonCount(2, 'data.posts')->assertJsonPath('data.posts.0.ticker', 'SAP.DE');
        $this->getJson('/api/v1/portfolio-instagram?ideas=1', $headers)->assertOk()->assertJsonCount(1, 'data.posts');
        $this->getJson('/api/v1/portfolio-instagram?sentiment=neutral', $headers)->assertOk()->assertJsonCount(1, 'data.posts');
        $this->getJson('/api/v1/portfolio-instagram?sentiment=wild', $headers)->assertUnprocessable();

        $this->postJson("/api/v1/portfolio-instagram/{$idea->id}/watch", [], $headers)->assertOk();
        $this->assertSame(1, WatchlistItem::where('symbol', 'SAP.DE')->count());
        $this->postJson("/api/v1/portfolio-instagram/{$idea->id}/alerts", [], $headers)->assertOk()->assertJsonPath('data.created', 2);
        $this->assertSame(2, PriceAlert::count());

        $this->postJson('/api/v1/portfolio-instagram/push', [], $headers)->assertOk()->assertJsonPath('data.push', true);
        $this->assertTrue((bool) PortfolioSetting::firstOrFail()->getAttribute('instagram_push'));
    }
}
