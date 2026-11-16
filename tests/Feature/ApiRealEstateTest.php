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
}
