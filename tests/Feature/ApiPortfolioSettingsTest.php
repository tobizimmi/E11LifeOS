<?php

namespace Tests\Feature;

use App\Models\PortfolioSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Portfolio settings for the mobile app.
 */
class ApiPortfolioSettingsTest extends TestCase
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

    public function test_settings_are_read_changed_and_weighted(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/portfolio-settings', $headers)->assertOk()->assertJsonPath('data.strategy', 'moderate')->assertJsonPath('data.tax_allowance', 1000);

        $this->putJson('/api/v1/portfolio-settings', ['strategy' => 'aggressive', 'tax_allowance' => 801, 'benchmark_symbol' => '^gspc', 'pushover_user_key' => 'abc', 'pushover_app_token' => 'tok'], $headers)
            ->assertOk()->assertJsonPath('data.strategy', 'aggressive')->assertJsonPath('data.benchmark_symbol', '^GSPC')->assertJsonPath('data.pushover_token_set', true);
        $this->putJson('/api/v1/portfolio-settings', ['news_push_enabled' => true], $headers)->assertOk()->assertJsonPath('data.pushover_token_set', true)->assertJsonPath('data.strategy', 'aggressive');
        $this->putJson('/api/v1/portfolio-settings', ['remove_pushover_token' => true], $headers)->assertOk()->assertJsonPath('data.pushover_token_set', false);
        $this->putJson('/api/v1/portfolio-settings', ['strategy' => 'wild'], $headers)->assertUnprocessable();

        $this->putJson('/api/v1/portfolio-settings/score-weights', ['preset' => 'creative'], $headers)->assertOk()->assertJsonFragment(['key' => 'pe', 'weight' => 3]);
        $this->putJson('/api/v1/portfolio-settings/score-weights', ['weights' => ['pe' => 0]], $headers)->assertOk()->assertJsonFragment(['key' => 'pe', 'weight' => 0]);
        $this->putJson('/api/v1/portfolio-settings/score-weights', ['preset' => 'default'], $headers)->assertOk();
        $this->assertNull(PortfolioSetting::firstOrFail()->score_weights);
    }
}
