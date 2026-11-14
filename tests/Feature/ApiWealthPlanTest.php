<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for the wealth plan.
 */
class ApiWealthPlanTest extends TestCase
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

    public function test_the_plan_is_projected_from_the_given_assumptions(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $plan = $this->getJson('/api/v1/wealth-plan?monthly_savings=1000&monthly_expenses=2000&return=5&withdrawal=4&target=100000', $headers)->assertOk()->json('data');

        $this->assertEquals(1000, $plan['monthly_savings']);
        $this->assertEquals(100000, $plan['target']);
        $this->assertEquals(50, $plan['horizon_years']);
        $this->assertCount(51, $plan['series']);
        $this->assertGreaterThan(0, $plan['years_to_target']);
        $this->assertLessThan(10, $plan['years_to_target']);
        $this->assertEquals(round(100000 * 0.04 / 12, 2), $plan['passive_at_target']);
        $this->assertContains($plan['years_to_target'], array_column($plan['milestones'], 'year'));

        // Without a target the financial-independence number (expenses ÷ withdrawal rate) is used.
        $fire = $this->getJson('/api/v1/wealth-plan?monthly_savings=0&monthly_expenses=2000&withdrawal=4', $headers)->assertOk()->json('data');
        $this->assertEquals(600000, $fire['fire_number']);
        $this->assertEquals(600000, $fire['target']);
        $this->assertNull($fire['years_to_target']);

        $this->getJson('/api/v1/wealth-plan?return=50', $headers)->assertStatus(422);
        $this->getJson('/api/v1/wealth-plan?withdrawal=0', $headers)->assertStatus(422);
        $this->getJson('/api/v1/wealth-plan')->assertUnauthorized();
    }
}
