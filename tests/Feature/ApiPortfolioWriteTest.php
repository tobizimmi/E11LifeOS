<?php

namespace Tests\Feature;

use App\Models\Portfolio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Portfolios and transactions for the mobile app; positions follow the transactions.
 */
class ApiPortfolioWriteTest extends TestCase
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

    public function test_depots_and_transactions_are_kept_and_positions_follow(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $headers = $this->headersFor($user);

        $depot = $this->postJson('/api/v1/portfolios', ['name' => 'Hauptdepot', 'broker' => 'Broker'], $headers)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/portfolios/{$depot}", ['name' => 'Langfristig'], $headers)->assertOk()->assertJsonPath('data.name', 'Langfristig');

        $buy = $this->postJson("/api/v1/portfolios/{$depot}/transactions", ['type' => 'buy', 'symbol' => 'sap.de', 'executed_on' => '2026-01-10', 'quantity' => 10, 'price' => 100, 'fees' => 1], $headers)->assertCreated()->assertJsonPath('data.symbol', 'SAP.DE')->json('data.id');
        $this->getJson("/api/v1/portfolios/{$depot}/positions", $headers)->assertOk()->assertJsonPath('data.0.quantity', 10)->assertJsonPath('data.0.symbol', 'SAP.DE');

        $this->postJson("/api/v1/portfolios/{$depot}/transactions", ['type' => 'sell', 'symbol' => 'SAP.DE', 'executed_on' => '2026-02-01', 'quantity' => 50, 'price' => 120], $headers)->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson("/api/v1/portfolios/{$depot}/transactions", ['type' => 'sell', 'symbol' => 'SAP.DE', 'executed_on' => '2026-02-01', 'quantity' => 4, 'price' => 120], $headers)->assertCreated();
        $this->getJson("/api/v1/portfolios/{$depot}/positions", $headers)->assertJsonPath('data.0.quantity', 6);

        $this->putJson("/api/v1/transactions-portfolio/{$buy}", ['type' => 'buy', 'symbol' => 'SAP.DE', 'executed_on' => '2026-01-10', 'quantity' => 20, 'price' => 100], $headers)->assertOk();
        $this->getJson("/api/v1/portfolios/{$depot}/positions", $headers)->assertJsonPath('data.0.quantity', 16);
        $this->getJson("/api/v1/portfolios/{$depot}/transactions", $headers)->assertOk()->assertJsonCount(2, 'data.transactions');

        $this->deleteJson("/api/v1/portfolios/{$depot}", [], $headers)->assertOk();
        $this->assertNull(Portfolio::find($depot));
    }

    public function test_foreign_depots_are_hidden(): void
    {
        [$owner] = $this->memberWithHousehold();
        [$other, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $depot = Portfolio::factory()->for($owner)->create();
        $headers = $this->headersFor($other);

        $this->postJson("/api/v1/portfolios/{$depot->id}/transactions", ['type' => 'buy', 'symbol' => 'X', 'executed_on' => '2026-01-01', 'quantity' => 1, 'price' => 1], $headers)->assertNotFound();
        $this->putJson("/api/v1/portfolios/{$depot->id}", ['name' => 'x'], $headers)->assertNotFound();
    }
}
