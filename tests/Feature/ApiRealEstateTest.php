<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for real estate: properties, budget by trade, invoices.
 */
class ApiRealEstateTest extends TestCase
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

    public function test_property_budget_and_invoices(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/real-estate', ['name' => 'Haus Linde', 'usage' => 'owner', 'state' => 'NW', 'purchase_price' => 400000], $headers)->assertCreated()->assertJsonPath('data.name', 'Haus Linde')->json('data.id');
        $this->postJson('/api/v1/real-estate', ['name' => 'X', 'usage' => 'nope', 'state' => 'NW'], $headers)->assertStatus(422);

        $detail = $this->postJson("/api/v1/real-estate/{$id}/items", ['title' => 'Dach', 'trade' => 'roof', 'planned_amount' => 20000], $headers)->assertCreated()->json('data');
        $this->assertCount(1, $detail['items']);
        $item = $detail['items'][0]['id'];
        $this->putJson("/api/v1/real-estate/{$id}/items/{$item}", ['status' => 'commissioned', 'contractor' => 'Dach GmbH'], $headers)->assertOk()->assertJsonPath('data.items.0.status', 'commissioned');

        $invoice = $this->postJson("/api/v1/real-estate/{$id}/invoices", ['vendor' => 'Dach GmbH', 'amount' => 21000, 'budget_item_id' => $item], $headers)->assertCreated()->assertJsonPath('data.items.0.over', true)->json('data.invoices.0.id');
        $this->postJson("/api/v1/real-estate/{$id}/invoices/{$invoice}/paid", [], $headers)->assertOk()->assertJsonPath('data.invoices.0.paid', true);
        $this->postJson("/api/v1/real-estate/{$id}/invoices", ['vendor' => 'X', 'amount' => 5, 'budget_item_id' => 999999], $headers)->assertNotFound();

        $this->putJson("/api/v1/real-estate/{$id}", ['living_area' => 140, 'monthly_costs' => 300], $headers)->assertOk()->assertJsonPath('data.living_area', 140);
        $this->getJson('/api/v1/real-estate', $headers)->assertOk()->assertJsonPath('data.properties.0.name', 'Haus Linde');
        $this->deleteJson("/api/v1/real-estate/{$id}/invoices/{$invoice}", [], $headers)->assertOk()->assertJsonCount(0, 'data.invoices');
        $this->deleteJson("/api/v1/real-estate/{$id}", [], $headers)->assertOk();
    }

    public function test_private_property_stays_private(): void
    {
        [$user] = $this->memberWithHousehold();
        $stranger = $this->memberWithHousehold()[0];
        $id = $this->postJson('/api/v1/real-estate', ['name' => 'Privat', 'usage' => 'owner', 'state' => 'NW', 'private' => true], $this->headersFor($user))->json('data.id');

        $this->getJson("/api/v1/real-estate/{$id}", $this->headersFor($stranger))->assertNotFound();
    }

    public function test_loans_subsidies_and_draws(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $id = $this->postJson('/api/v1/real-estate', ['name' => 'Haus Linde', 'usage' => 'owner', 'state' => 'NW', 'purchase_price' => 400000, 'purchase_date' => '2025-01-01'], $headers)->json('data.id');

        $loan = $this->postJson("/api/v1/real-estate/{$id}/loans", ['lender' => 'Bank', 'kind' => 'annuity', 'amount' => 240000, 'interest_rate' => 3.5, 'repayment_rate' => 2, 'fixed_years' => 10, 'start_on' => '2025-01-01'], $headers)->assertCreated()->assertJsonPath('data.loans.0.monthly_rate', 1100)->json('data.loans.0.id');
        $this->postJson("/api/v1/real-estate/{$id}/loans", ['lender' => 'Bank', 'kind' => 'nope', 'amount' => 1, 'interest_rate' => 1, 'repayment_rate' => 1, 'fixed_years' => 5], $headers)->assertStatus(422);
        $this->putJson("/api/v1/real-estate/{$id}/loans/{$loan}", ['lender' => 'Sparkasse', 'kind' => 'kfw', 'amount' => 100000, 'interest_rate' => 1, 'repayment_rate' => 2, 'fixed_years' => 10], $headers)->assertOk()->assertJsonPath('data.loans.0.lender', 'Sparkasse');

        $subsidy = $this->postJson("/api/v1/real-estate/{$id}/subsidies", ['program' => 'KfW 261', 'title' => 'Sanierung', 'kind' => 'grant', 'amount' => 15000, 'status' => 'idea'], $headers)->assertCreated()->assertJsonPath('data.secured_subsidies', 0)->json('data.subsidies.0.id');
        $this->postJson("/api/v1/real-estate/{$id}/subsidies/{$subsidy}/status", ['status' => 'approved'], $headers)->assertOk()->assertJsonPath('data.secured_subsidies', 15000)->assertJsonPath('data.subsidies.0.approved_on', today()->toDateString());
        $this->postJson("/api/v1/real-estate/{$id}/subsidies/{$subsidy}/status", ['status' => 'x'], $headers)->assertStatus(422);

        $draw = $this->postJson("/api/v1/real-estate/{$id}/draws", ['source' => 'loan', 'loan_id' => $loan, 'label' => 'Rate 1', 'planned_amount' => 50000], $headers)->assertCreated()->assertJsonPath('data.draws.0.utilisation', 0)->json('data.draws.0.id');
        $this->postJson("/api/v1/real-estate/{$id}/draws/{$draw}/done", [], $headers)->assertOk()->assertJsonPath('data.draws.0.utilisation', 100);
        $this->postJson("/api/v1/real-estate/{$id}/draws", ['source' => 'loan', 'loan_id' => 999999, 'label' => 'X'], $headers)->assertNotFound();

        $this->getJson("/api/v1/real-estate/{$id}/finance", $headers)->assertOk()->assertJsonCount(1, 'data.loans');
        $this->deleteJson("/api/v1/real-estate/{$id}/draws/{$draw}", [], $headers)->assertOk()->assertJsonCount(0, 'data.draws');
        $this->deleteJson("/api/v1/real-estate/{$id}/subsidies/{$subsidy}", [], $headers)->assertOk()->assertJsonCount(0, 'data.subsidies');
        $this->deleteJson("/api/v1/real-estate/{$id}/loans/{$loan}", [], $headers)->assertOk()->assertJsonCount(0, 'data.loans');
    }
}
