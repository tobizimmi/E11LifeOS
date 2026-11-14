<?php

namespace Tests\Feature;

use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioTransaction;
use App\Models\TargetAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for the portfolio evaluations: dividends, taxes, analysis and rebalancing.
 */
class ApiPortfolioReportsTest extends TestCase
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

    public function test_dividends_and_taxes_are_reported_for_the_members_portfolios(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $headers = $this->headersFor($user);
        $portfolio = Portfolio::factory()->for($user)->create();
        $year = now()->year;

        PortfolioTransaction::factory()->create(['portfolio_id' => $portfolio->id, 'type' => 'buy', 'quantity' => 10, 'price' => 100, 'fees' => 0, 'executed_on' => ($year - 1).'-03-01']);
        PortfolioTransaction::factory()->create(['portfolio_id' => $portfolio->id, 'type' => 'sell', 'quantity' => 10, 'price' => 150, 'fees' => 0, 'executed_on' => $year.'-02-01']);
        PortfolioTransaction::factory()->create(['portfolio_id' => $portfolio->id, 'type' => 'dividend', 'quantity' => 1, 'price' => 40, 'fees' => 0, 'executed_on' => now()->subMonths(2)->toDateString()]);
        PortfolioPosition::create(['portfolio_id' => $portfolio->id, 'symbol' => 'SAP.DE', 'quantity' => 10, 'avg_buy_price' => 100, 'manual' => true]);

        $dividends = $this->getJson('/api/v1/portfolio-reports/dividends', $headers)->assertOk()->json('data');
        $this->assertEquals(40.0, $dividends['recent'][0]['amount']);
        $this->assertArrayHasKey('expected', $dividends);
        $this->assertArrayHasKey('yield_on_cost', $dividends);

        $taxes = $this->getJson("/api/v1/portfolio-reports/taxes?year={$year}", $headers)->assertOk()->json('data');
        $this->assertSame($year, $taxes['year']);
        $this->assertEquals(500.0, $taxes['share_gains']);
        $this->assertContains($year, $taxes['years']);
        $this->getJson('/api/v1/portfolio-reports/taxes?year=1900', $headers)->assertStatus(422);

        $this->getJson("/api/v1/portfolio-reports/dividends?portfolio={$portfolio->id}", $headers)->assertOk();
    }

    public function test_the_analysis_and_the_rebalancing_plan(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $headers = $this->headersFor($user);
        $portfolio = Portfolio::factory()->for($user)->create();
        PortfolioPosition::create(['portfolio_id' => $portfolio->id, 'symbol' => 'SAP.DE', 'name' => 'SAP', 'quantity' => 10, 'avg_buy_price' => 100, 'manual' => true]);

        $analysis = $this->getJson('/api/v1/portfolio-reports/analysis?range=1y', $headers)->assertOk()->json('data');
        $this->assertSame('1y', $analysis['range']);
        $this->assertArrayHasKey('summary', $analysis);
        $this->assertArrayHasKey('risk', $analysis);
        $this->getJson('/api/v1/portfolio-reports/analysis?range=forever', $headers)->assertStatus(422);

        $this->getJson('/api/v1/portfolio-reports/rebalancing', $headers)->assertOk()->assertJsonPath('data.has_targets', false);

        $this->putJson('/api/v1/portfolio-reports/targets', ['targets' => ['sap.de' => 60, 'VWCE.DE' => 40]], $headers)->assertOk()->assertJsonPath('data.saved', 2);
        $this->assertSame(2, TargetAllocation::where('user_id', $user->id)->count());
        $plan = $this->getJson('/api/v1/portfolio-reports/rebalancing?additional=1000', $headers)->assertOk()->json('data');
        $this->assertTrue($plan['has_targets']);
        $this->assertEquals(100.0, $plan['target_sum']);
        $this->assertContains('VWCE.DE', array_column($plan['plan'], 'symbol'));

        $this->putJson('/api/v1/portfolio-reports/targets', ['targets' => ['SAP.DE' => 80, 'VWCE.DE' => 40]], $headers)->assertStatus(422);
        $this->putJson('/api/v1/portfolio-reports/targets', ['targets' => ['VWCE.DE' => 0]], $headers)->assertOk();
        $this->assertSame(1, TargetAllocation::where('user_id', $user->id)->count());
    }

    public function test_the_reports_are_personal_and_need_the_portfolio_addon(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $otherHousehold->setAddon('portfolio', true);
        $foreign = Portfolio::factory()->for($other)->create();
        PortfolioTransaction::factory()->create(['portfolio_id' => $foreign->id, 'type' => 'dividend', 'quantity' => 1, 'price' => 99]);
        $headers = $this->headersFor($user);

        $this->getJson("/api/v1/portfolio-reports/dividends?portfolio={$foreign->id}", $headers)->assertOk()->assertJsonCount(0, 'data.recent');

        [$plain, $plainHousehold] = $this->memberWithHousehold();
        $plainHousehold->setAddon('portfolio', false);
        $this->getJson('/api/v1/portfolio-reports/dividends', $this->headersFor($plain))->assertNotFound();
    }
}
