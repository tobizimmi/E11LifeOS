<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Portfolio;
use App\Models\PortfolioSetting;
use App\Models\PortfolioTransaction;
use App\Models\Security;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Support\Portfolio\Positions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Spatie\LaravelPasskeys\Models\Passkey;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @return array{User, string}
     */
    private function signedInInvestor(): array
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);

        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone 16'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->json('data.token');

        return [$user, $token];
    }

    public function test_devices_sign_in_with_a_token_and_can_be_signed_out(): void
    {
        [$user, $token] = $this->signedInInvestor();

        $this->assertSame('iPhone 16', ApiToken::where('user_id', $user->id)->sole()->name);
        $this->assertNotSame($token, ApiToken::sole()->token);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.portfolio_enabled', true);
        $this->withToken('wrong')->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('success', false);
        $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'wrong'])->assertStatus(422)->assertJsonPath('success', false);

        $this->actingAs($user);
        Volt::test('profile.app-devices-form')->assertSee('iPhone 16')->call('revoke', ApiToken::sole()->id);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

        [, $second] = $this->signedInInvestor();
        $this->withToken($second)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, ApiToken::count());
    }

    public function test_app_tokens_expire_after_90_days_without_use(): void
    {
        [$user, $token] = $this->signedInInvestor();

        $this->travel(80)->days();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->actingAs($user);
        Volt::test('profile.app-devices-form')->assertSee('läuft ohne Nutzung am '.now()->addDays(90)->format('d.m.Y').' ab');

        $this->travel(91)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(0, ApiToken::count());

        [, $second] = $this->signedInInvestor();
        $this->travel(91)->days();
        $this->assertSame(1, ApiToken::pruneExpired());
    }

    public function test_the_app_reads_portfolios_positions_and_watchlist(): void
    {
        [$user, $token] = $this->signedInInvestor();
        $portfolio = Portfolio::factory()->for($user)->create(['name' => 'Trade Republic']);
        Security::factory()->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'last_price' => 120, 'previous_close' => 118, 'currency' => 'EUR', 'price_updated_at' => now()]);
        PortfolioTransaction::factory()->for($portfolio)->create(['quantity' => 10, 'price' => 100, 'fees' => 0]);
        Positions::recalculate($portfolio);
        PortfolioSetting::for($user)->fill(['strategy' => 'thieme'])->save();

        $api = $this->withToken($token);
        $api->getJson('/api/v1/portfolios')->assertOk()->assertJsonPath('data.0.name', 'Trade Republic');
        $api->getJson("/api/v1/portfolios/{$portfolio->id}/positions")->assertOk()
            ->assertJsonPath('data.0.symbol', 'SAP.DE')
            ->assertJsonPath('data.0.current_value', 1200)
            ->assertJsonPath('data.0.gain_loss', 200);
        $api->getJson("/api/v1/portfolios/{$portfolio->id}/summary")->assertOk()
            ->assertJsonPath('data.total_value', 1200)
            ->assertJsonPath('data.position_count', 1)
            ->assertJsonPath('data.day_change', 20);
        $api->getJson("/api/v1/portfolios/{$portfolio->id}/transactions?limit=5")->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.transactions.0.type', 'buy');
        $quotes = $api->getJson('/api/v1/quotes?symbols=SAP.DE,<bad>')->assertOk()->json('data');
        $this->assertSame(['SAP.DE'], array_keys($quotes));
        $this->assertEquals(120, $quotes['SAP.DE']['price']);

        $api->postJson('/api/v1/watchlist', ['symbol' => 'sap.de'])->assertCreated();
        $api->getJson('/api/v1/watchlist')->assertOk()->assertJsonPath('data.0.name', 'SAP SE')->assertJsonPath('data.0.current_price', 120);
        $api->deleteJson('/api/v1/watchlist/SAP.DE')->assertOk();
        $this->assertSame(0, WatchlistItem::count());

        $api->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.strategy', 'thieme');
        $api->putJson('/api/v1/settings', ['strategy' => 'conservative', 'drop_threshold' => 15])->assertOk()->assertJsonPath('data.strategy', 'conservative');
        $api->putJson('/api/v1/settings', ['strategy' => 'yolo'])->assertStatus(422)->assertJsonPath('success', false);
        $api->getJson('/api/v1/recommendations')->assertOk()->assertJsonPath('data.meta.cache_empty', true);

        $api->getJson('/api/v1/strategy')->assertOk()
            ->assertJsonPath('data.positions.0.symbol', 'SAP.DE')
            ->assertJsonPath('data.positions.0.thirds_filled', 3)
            ->assertJsonPath('data.positions.0.cluster_risk', true)
            ->assertJsonPath('data.health.sectors', 0);
    }

    public function test_other_users_portfolios_and_disabled_addons_are_hidden(): void
    {
        [, $token] = $this->signedInInvestor();
        $foreign = Portfolio::factory()->create();

        $this->withToken($token)->getJson("/api/v1/portfolios/{$foreign->id}/positions")->assertNotFound()->assertJsonPath('success', false);

        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', false);
        $plain = ApiToken::issue($user, 'iPad');
        $this->app['auth']->forgetGuards();

        $this->withToken($plain)->getJson('/api/v1/portfolios')->assertNotFound();
        $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.portfolio_enabled', false);
    }

    public function test_login_requires_two_factor_setup_when_globally_enforced(): void
    {
        config(['security.require_two_factor' => true]);
        [$user] = $this->memberWithHousehold();

        $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('setup_required', true);
        $this->assertSame(0, ApiToken::count());
    }

    public function test_login_requires_two_factor_setup_when_only_a_passkey_is_registered(): void
    {
        [$user] = $this->memberWithHousehold();
        Passkey::factory()->create(['authenticatable_id' => $user->id]);

        $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('setup_required', true);
        $this->assertSame(0, ApiToken::count());
    }
}
