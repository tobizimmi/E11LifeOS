<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Mobile API for the small business: invoices, customers, time tracking.
 */
class ApiBusinessTest extends TestCase
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

    private function profile(User $user, bool $complete = true): void
    {
        BusinessProfile::create(['user_id' => $user->id, 'household_id' => $user->currentHousehold()?->id, 'company_name' => 'Muster', 'street' => 'Weg 1', 'postal_code' => '50667', 'city' => 'Köln', 'tax_number' => $complete ? '123/456/789' : null, 'small_business' => true, 'hourly_rate' => 80]);
    }

    public function test_it_needs_a_business_profile(): void
    {
        [$user] = $this->memberWithHousehold();

        $this->getJson('/api/v1/business', $this->headersFor($user))->assertNotFound();
    }

    public function test_invoice_lifecycle_with_customer_and_payment(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->profile($user);
        $headers = $this->headersFor($user);

        $customer = $this->postJson('/api/v1/business/customers', ['name' => 'Kunde GmbH', 'email' => 'k@example.com'], $headers)->assertCreated()->json('data.id');
        $draft = $this->postJson('/api/v1/business/invoices', ['customer_id' => $customer, 'recipient' => "Kunde GmbH\nStraße 2", 'subject' => 'Beratung', 'items' => [['description' => 'Beratung', 'quantity' => 2, 'unit' => 'h', 'unit_price' => 80]]], $headers)->assertCreated()->assertJsonPath('data.draft', true)->assertJsonPath('data.gross_total', 160)->json('data');
        $this->postJson("/api/v1/business/invoices/{$draft['id']}/issue", [], $headers)->assertStatus(422);

        $id = $this->postJson('/api/v1/business/invoices', ['recipient' => 'Kunde GmbH', 'service_period' => 'Nov 2026', 'issue' => true, 'items' => [['description' => 'Arbeit', 'quantity' => 1, 'unit_price' => 100]]], $headers)->assertCreated()->assertJsonPath('data.draft', false)->json('data.id');
        $this->assertNotNull($this->getJson("/api/v1/business/invoices/{$id}", $headers)->json('data.number'));
        $this->postJson("/api/v1/business/invoices/{$id}/pay", ['amount' => 40], $headers)->assertOk()->assertJsonPath('data.outstanding', 60);
        $this->postJson("/api/v1/business/invoices/{$id}/pay", [], $headers)->assertOk()->assertJsonPath('data.status', 'paid');

        $this->getJson('/api/v1/business', $headers)->assertOk()->assertJsonPath('data.complete', true)->assertJsonCount(2, 'data.invoices')->assertJsonCount(1, 'data.customers');
        $this->deleteJson("/api/v1/business/invoices/{$id}", [], $headers)->assertStatus(422);
        $this->deleteJson("/api/v1/business/invoices/{$draft['id']}", [], $headers)->assertOk();
        $this->putJson("/api/v1/business/customers/{$customer}", ['city' => 'Bonn'], $headers)->assertOk()->assertJsonPath('data.city', 'Bonn');
    }

    public function test_incomplete_profile_cannot_issue_and_others_cannot_see_invoices(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->profile($user, false);
        $headers = $this->headersFor($user);

        $this->postJson('/api/v1/business/invoices', ['recipient' => 'X', 'service_period' => 'Nov', 'issue' => true, 'items' => [['description' => 'A', 'quantity' => 1, 'unit_price' => 1]]], $headers)->assertStatus(422);
        $id = $this->postJson('/api/v1/business/invoices', ['recipient' => 'X', 'items' => [['description' => 'A', 'quantity' => 1, 'unit_price' => 1]]], $headers)->assertCreated()->json('data.id');

        [$stranger] = $this->memberWithHousehold();
        $this->profile($stranger);
        $this->getJson("/api/v1/business/invoices/{$id}", $this->headersFor($stranger))->assertNotFound();
    }

    public function test_time_tracking(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->profile($user);
        $headers = $this->headersFor($user);

        $this->postJson('/api/v1/business/time/start', ['description' => 'Konzept'], $headers)->assertOk()->assertJsonPath('data.running.description', 'Konzept');
        $this->postJson('/api/v1/business/time/start', ['description' => 'Umsetzung'], $headers)->assertOk()->assertJsonPath('data.running.description', 'Umsetzung')->assertJsonCount(1, 'data.entries');
        $stopped = $this->postJson('/api/v1/business/time/stop', [], $headers)->assertOk()->assertJsonPath('data.running', null)->assertJsonCount(2, 'data.entries')->json('data.entries.0.id');
        $this->deleteJson("/api/v1/business/time/{$stopped}", [], $headers)->assertOk()->assertJsonCount(1, 'data.entries');
    }
}
