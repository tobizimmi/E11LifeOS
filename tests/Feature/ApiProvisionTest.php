<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for the provision check (insurances and retirement plan).
 */
class ApiProvisionTest extends TestCase
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

    public function test_insurance_check_answers_and_new_insurance(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $first = $this->getJson('/api/v1/provision/insurance', $headers)->assertOk()->json('data');
        $liability = collect($first['rows'])->firstWhere('key', 'liability');
        $this->assertSame('missing', $liability['status']);

        $this->postJson('/api/v1/provision/answer', ['type' => 'liability', 'answer' => 'have'], $headers)->assertOk();
        $this->assertSame('ok', collect($this->getJson('/api/v1/provision/insurance', $headers)->json('data.rows'))->firstWhere('key', 'liability')['status']);
        $this->postJson('/api/v1/provision/answer', ['type' => 'nope', 'answer' => 'have'], $headers)->assertStatus(422);
        $this->postJson('/api/v1/provision/answer', ['type' => 'liability', 'answer' => null], $headers)->assertOk();

        $this->postJson('/api/v1/provision/insurance', ['type' => 'liability', 'provider' => 'HUK', 'monthly' => 4.5], $headers)->assertOk();
        $rows = $this->getJson('/api/v1/provision/insurance', $headers)->json('data.rows');
        $this->assertCount(1, collect($rows)->firstWhere('key', 'liability')['contracts']);
    }

    public function test_retirement_plan_is_calculated_and_saved(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/provision/retirement', $headers)->assertOk()->assertJsonStructure(['data' => ['inputs' => ['birth_year', 'retirement_age'], 'result' => ['gap', 'capital_needed', 'coverage']]]);
        $plan = ['birth_year' => now()->year - 40, 'retirement_age' => 67, 'desired_income' => 3000, 'statutory_pension' => 1200, 'other_pension' => 0, 'assets' => 20000, 'monthly_savings' => 500, 'return' => 5, 'inflation' => 2, 'withdrawal' => 4];
        $this->putJson('/api/v1/provision/retirement', $plan, $headers)->assertOk()->assertJsonPath('data.inputs.desired_income', 3000)->assertJsonPath('data.result.gap', 1800);
        $this->putJson('/api/v1/provision/retirement', ['retirement_age' => 20] + $plan, $headers)->assertStatus(422);
        $this->deleteJson('/api/v1/provision/retirement', [], $headers)->assertOk()->assertJsonPath('data.inputs.retirement_age', 67);
    }
}
