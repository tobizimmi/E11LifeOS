<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_products_manual_times_billing_and_recurring_templates(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->profile($user);
        $headers = $this->headersFor($user);

        $this->postJson('/api/v1/business/products', ['name' => 'Beratung', 'unit' => 'h', 'unit_price' => 90, 'vat_rate' => 0], $headers)->assertCreated()->assertJsonPath('data.0.name', 'Beratung');
        $product = $this->getJson('/api/v1/business/products', $headers)->assertOk()->json('data.0.id');
        $this->putJson("/api/v1/business/products/{$product}", ['name' => 'Beratung', 'unit_price' => 95, 'vat_rate' => 0], $headers)->assertOk()->assertJsonPath('data.0.unit_price', 95);

        $customer = $this->postJson('/api/v1/business/customers', ['name' => 'Kunde GmbH'], $headers)->json('data.id');
        $this->postJson('/api/v1/business/time', ['description' => 'Konzept', 'customer_id' => $customer, 'date' => '2026-11-10', 'duration' => '1:30'], $headers)->assertCreated();
        $this->postJson('/api/v1/business/time', ['description' => 'Falsch', 'date' => '2026-11-10', 'duration' => 'abc'], $headers)->assertStatus(422);
        $entry = $this->getJson('/api/v1/business/time', $headers)->assertJsonCount(1, 'data.entries')->json('data.entries.0.id');
        $this->putJson("/api/v1/business/time/{$entry}", ['description' => 'Konzept', 'customer_id' => $customer, 'date' => '2026-11-10', 'duration' => '2'], $headers)->assertOk();

        $this->postJson('/api/v1/business/time/bill', ['entries' => []], $headers)->assertStatus(422);
        $invoice = $this->postJson('/api/v1/business/time/bill', ['entries' => [$entry]], $headers)->assertCreated()->json('data.invoice_id');
        $this->getJson("/api/v1/business/invoices/{$invoice}", $headers)->assertOk()->assertJsonPath('data.gross_total', 160);
        $this->putJson("/api/v1/business/time/{$entry}", ['description' => 'x', 'date' => '2026-11-10', 'duration' => '1'], $headers)->assertNotFound();

        $this->postJson("/api/v1/business/invoices/{$invoice}/recurring", ['frequency' => 'monthly'], $headers)->assertCreated()->assertJsonPath('data.0.frequency', 'monthly');
        $template = $this->getJson('/api/v1/business/recurring', $headers)->assertJsonCount(1, 'data')->json('data.0.id');
        $this->putJson("/api/v1/business/recurring/{$template}", ['active' => false, 'auto_issue' => true], $headers)->assertOk()->assertJsonPath('data.0.active', false);
        $this->postJson("/api/v1/business/recurring/{$template}/run", [], $headers)->assertCreated();
        $this->deleteJson("/api/v1/business/recurring/{$template}", [], $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/v1/business/products/{$product}", [], $headers)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_bookings_with_receipt_and_the_yearly_report(): void
    {
        Storage::fake('secure');
        [$user] = $this->memberWithHousehold();
        $this->profile($user);
        $headers = $this->headersFor($user);
        $year = today()->year;

        $this->postJson('/api/v1/business/bookings', ['kind' => 'expense', 'booked_on' => today()->toDateString(), 'description' => 'Papier', 'category' => 'sales', 'amount' => 20], $headers)->assertStatus(422);
        $id = $this->post('/api/v1/business/bookings', ['kind' => 'expense', 'booked_on' => today()->toDateString(), 'description' => 'Papier', 'counterparty' => 'Büro AG', 'category' => 'office', 'amount' => 23.80, 'vat_amount' => 3.80, 'receipt' => UploadedFile::fake()->image('beleg.jpg')], $headers + ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.has_receipt', true)->assertJsonPath('data.vat_amount', 0)->json('data.id');
        $this->postJson('/api/v1/business/bookings', ['kind' => 'income', 'booked_on' => today()->toDateString(), 'description' => 'Marktstand', 'category' => 'sales', 'amount' => 150], $headers)->assertCreated();

        $this->get("/api/v1/business/bookings/{$id}/receipt", $headers)->assertOk();
        $this->getJson('/api/v1/business/bookings?kind=missing', $headers)->assertOk()->assertJsonCount(0, 'data.entries');
        $this->getJson('/api/v1/business/bookings?q=papier', $headers)->assertOk()->assertJsonCount(1, 'data.entries')->assertJsonPath('data.expense_sum', 23.8)->assertJsonPath('data.income_sum', 0);

        $this->postJson("/api/v1/business/bookings/{$id}", ['kind' => 'expense', 'booked_on' => today()->toDateString(), 'description' => 'Papier', 'category' => 'office', 'amount' => 30, 'remove_receipt' => true], $headers)->assertOk()->assertJsonPath('data.has_receipt', false);
        $this->getJson('/api/v1/business/bookings?kind=missing', $headers)->assertJsonCount(1, 'data.entries');

        $invoice = $this->postJson('/api/v1/business/invoices', ['recipient' => 'Kunde', 'service_period' => 'Nov', 'issue' => true, 'items' => [['description' => 'Arbeit', 'quantity' => 1, 'unit_price' => 100]]], $headers)->json('data.id');
        $this->postJson("/api/v1/business/invoices/{$invoice}/pay", [], $headers)->assertOk();

        $report = $this->getJson("/api/v1/business/report?year={$year}", $headers)->assertOk()->assertJsonPath('data.small_business', true)->assertJsonPath('data.income_total', 250)->assertJsonPath('data.expense_total', 30)->assertJsonPath('data.profit', 220)->assertJsonPath('data.vat_periods', [])->assertJsonCount(12, 'data.monthly')->json('data');
        $this->assertSame(1, $report['missing_receipts']);
        $this->assertSame('ok', $report['limit_status']);
        $this->assertSame(250.0, (float) collect($report['lines'])->where('section', 'income')->sum('amount'));

        $this->get("/api/v1/business/report/download?year={$year}&format=csv", $headers)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->get("/api/v1/business/report/download?year={$year}&format=pdf", $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('/api/v1/business/report/download?year=1999&format=pdf', $headers)->assertStatus(422);

        $this->deleteJson("/api/v1/business/bookings/{$id}", [], $headers)->assertOk();
        $stranger = $this->memberWithHousehold()[0];
        $this->profile($stranger);
        $this->postJson('/api/v1/business/bookings/'.$id, ['kind' => 'expense', 'booked_on' => today()->toDateString(), 'description' => 'x', 'category' => 'office', 'amount' => 1], $this->headersFor($stranger))->assertNotFound();
    }

    public function test_vat_advance_returns_for_a_business_that_charges_vat(): void
    {
        [$user] = $this->memberWithHousehold();
        BusinessProfile::create(['user_id' => $user->id, 'household_id' => $user->currentHousehold()?->id, 'company_name' => 'Muster', 'street' => 'Weg 1', 'postal_code' => '50667', 'city' => 'Köln', 'tax_number' => '123/456/789', 'small_business' => false, 'default_vat_rate' => 19, 'hourly_rate' => 80]);
        $headers = $this->headersFor($user);
        $year = today()->year;

        $id = $this->postJson('/api/v1/business/invoices', ['recipient' => 'Kunde', 'service_period' => 'Nov', 'issue' => true, 'items' => [['description' => 'Arbeit', 'quantity' => 1, 'unit_price' => 1000, 'vat_rate' => 19]]], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/business/invoices/{$id}/pay", [], $headers)->assertOk();
        $this->postJson('/api/v1/business/bookings', ['kind' => 'expense', 'booked_on' => today()->toDateString(), 'description' => 'Laptop', 'category' => 'low_value_assets', 'amount' => 595, 'vat_amount' => 95], $headers)->assertCreated()->assertJsonPath('data.vat_amount', 95);

        $report = $this->getJson("/api/v1/business/report?year={$year}&interval=quarter", $headers)->assertOk()->assertJsonCount(4, 'data.vat_periods')->json('data');
        $quarter = collect($report['vat_periods'])->first(fn ($period) => $period['from'] <= today()->toDateString() && $period['to'] >= today()->toDateString());
        $this->assertSame(1000.0, (float) $quarter['kz81']);
        $this->assertSame(190.0, (float) $quarter['kz81_vat']);
        $this->assertSame(95.0, (float) $quarter['kz66']);
        $this->assertSame(95.0, (float) $quarter['kz83']);
        $this->getJson("/api/v1/business/report?year={$year}&interval=month", $headers)->assertJsonCount(12, 'data.vat_periods');
    }
}
