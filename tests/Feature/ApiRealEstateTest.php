<?php

namespace Tests\Feature;

use App\Models\RealEstateApplicant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_profitability_analyses_can_be_calculated_and_saved(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/real-estate/analyses', $headers)->assertOk()->assertJsonPath('data.defaults.purchase_price', 300000)->assertJsonCount(0, 'data.saved');
        $result = $this->postJson('/api/v1/real-estate/analyses/calculate', ['inputs' => ['purchase_price' => 250000, 'rent' => 900, 'living_area' => 60]], $headers)->assertOk()->assertJsonStructure(['data' => ['total', 'loan', 'monthly_rate', 'gross_yield', 'verdict' => ['level', 'text'], 'hints', 'projection']])->json('data');
        $this->assertSame(250000.0, (float) $result['price']);

        $id = $this->postJson('/api/v1/real-estate/analyses', ['title' => 'Wohnung Köln', 'inputs' => ['purchase_price' => 250000, 'rent' => 900, 'state' => 'XX', 'years' => 99]], $headers)->assertCreated()->assertJsonPath('data.title', 'Wohnung Köln')->assertJsonPath('data.inputs.years', 40)->assertJsonPath('data.inputs.state', 'BY')->json('data.id');
        $this->putJson("/api/v1/real-estate/analyses/{$id}", ['title' => 'Neu', 'inputs' => ['rent' => 1100]], $headers)->assertOk()->assertJsonPath('data.inputs.rent', 1100)->assertJsonPath('data.title', 'Neu');
        $this->postJson('/api/v1/real-estate/analyses', ['inputs' => []], $headers)->assertStatus(422);

        $property = $this->postJson('/api/v1/real-estate', ['name' => 'Haus', 'usage' => 'owner', 'state' => 'NW', 'purchase_price' => 400000], $headers)->json('data.id');
        $this->getJson("/api/v1/real-estate/analyses/prefill/{$property}", $headers)->assertOk()->assertJsonPath('data.title', 'Haus')->assertJsonPath('data.inputs.purchase_price', 400000);

        $stranger = $this->memberWithHousehold()[0];
        $this->getJson("/api/v1/real-estate/analyses/{$id}", $this->headersFor($stranger))->assertNotFound();
        $this->deleteJson("/api/v1/real-estate/analyses/{$id}", [], $headers)->assertOk();
        $this->getJson("/api/v1/real-estate/analyses/{$id}", $headers)->assertNotFound();
    }

    public function test_tenants_and_the_rent_check(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $id = $this->postJson('/api/v1/real-estate', ['name' => 'Mehrfamilienhaus', 'usage' => 'rental', 'state' => 'NW', 'purchase_price' => 500000], $headers)->json('data.id');
        $year = today()->year;

        $this->postJson("/api/v1/real-estate/{$id}/tenants", ['name' => 'Familie Beispiel', 'move_in_on' => '2024-01-01', 'cold_rent' => 800, 'utilities_advance' => 200, 'deposit' => 5000], $headers)->assertStatus(422);
        $tenant = $this->postJson("/api/v1/real-estate/{$id}/tenants", ['name' => 'Familie Beispiel', 'unit' => 'EG', 'move_in_on' => '2024-01-01', 'cold_rent' => 800, 'utilities_advance' => 200, 'deposit' => 2400], $headers)->assertCreated()->assertJsonPath('data.tenants.0.monthly_total', 1000)->json('data.tenants.0.id');

        $this->postJson("/api/v1/real-estate/{$id}/tenants/{$tenant}/payments", ['year' => $year, 'month' => 1], $headers)->assertOk()->assertJsonPath('data.tenants.0.months.0.paid', true)->assertJsonPath('data.received', 1000);
        $this->postJson("/api/v1/real-estate/{$id}/tenants/{$tenant}/payments", ['year' => $year, 'month' => 1], $headers)->assertOk()->assertJsonPath('data.tenants.0.months.0.paid', false)->assertJsonPath('data.received', 0);
        $this->postJson("/api/v1/real-estate/{$id}/tenants/{$tenant}/payments", ['year' => 2020, 'month' => 1], $headers)->assertStatus(422);

        $this->putJson("/api/v1/real-estate/{$id}/tenants/{$tenant}", ['name' => 'Familie Muster', 'move_in_on' => '2024-01-01', 'move_out_on' => '2024-06-30', 'cold_rent' => 850], $headers)->assertOk()->assertJsonPath('data.tenants.0.name', 'Familie Muster')->assertJsonPath('data.tenants.0.active', false);
        $this->getJson("/api/v1/real-estate/{$id}/rental?year=2024", $headers)->assertOk()->assertJsonPath('data.year', 2024)->assertJsonPath('data.tenants.0.months.0.lives', true)->assertJsonPath('data.tenants.0.months.11.lives', false);
        $this->deleteJson("/api/v1/real-estate/{$id}/tenants/{$tenant}", [], $headers)->assertOk()->assertJsonCount(0, 'data.tenants');
    }

    public function test_utility_statement_index_rent_portal_and_pdfs(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $id = $this->postJson('/api/v1/real-estate', ['name' => 'MFH', 'usage' => 'rental', 'state' => 'NW', 'purchase_price' => 500000, 'living_area' => 100], $headers)->json('data.id');
        $tenant = $this->postJson("/api/v1/real-estate/{$id}/tenants", ['name' => 'Familie Beispiel', 'move_in_on' => '2024-01-01', 'cold_rent' => 800, 'utilities_advance' => 200, 'area' => 100, 'persons' => 2, 'index_base' => 100, 'rent_adjustment' => 'index'], $headers)->assertCreated()->assertJsonPath('data.tenants.0.index_base', 100)->assertJsonPath('data.tenants.0.has_portal', false)->json('data.tenants.0.id');

        $this->getJson("/api/v1/real-estate/{$id}/utility?year=2025", $headers)->assertOk()->assertJsonPath('data.exists', false)->assertJsonCount(4, 'data.costs');
        $this->putJson("/api/v1/real-estate/{$id}/utility", ['year' => 2025, 'period_start' => '2025-01-01', 'period_end' => '2026-06-30', 'costs' => []], $headers)->assertStatus(422);
        $saved = $this->putJson("/api/v1/real-estate/{$id}/utility", ['year' => 2025, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31', 'total_area' => 100, 'costs' => [['type' => 'property_tax', 'label' => 'Grundsteuer', 'amount' => 1200, 'key' => 'area'], ['type' => 'water', 'label' => 'Wasser', 'amount' => 600, 'key' => 'persons']]], $headers)->assertOk()->assertJsonPath('data.exists', true)->assertJsonPath('data.total', 1800)->json('data');
        $this->assertSame($tenant, $saved['result']['tenants'][0]['tenant_id']);
        $this->assertEqualsWithDelta(1800.0, (float) $saved['result']['tenants'][0]['costs'], 0.01);
        $this->assertEqualsWithDelta(2400.0, (float) $saved['result']['tenants'][0]['advances'], 0.01);
        $this->assertEqualsWithDelta(-600.0, (float) $saved['result']['tenants'][0]['balance'], 0.01);
        $this->postJson("/api/v1/real-estate/{$id}/utility/2025/sent", [], $headers)->assertOk()->assertJsonPath('data.sent_on', today()->toDateString());
        $this->get("/api/v1/real-estate/{$id}/utility/2025/tenants/{$tenant}/pdf", $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->postJson("/api/v1/real-estate/{$id}/tenants/{$tenant}/index-rent/preview", ['index' => 105], $headers)->assertOk()->assertJsonPath('data.new', 840)->assertJsonPath('data.percent', 5)->assertJsonPath('data.allowed', true);
        $this->get("/api/v1/real-estate/{$id}/tenants/{$tenant}/index-rent/pdf?index=105", $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/v1/real-estate/{$id}/tenants/{$tenant}/index-rent/apply", ['index' => 105, 'month' => '2026-10'], $headers)->assertOk()->assertJsonPath('data.new', 840);
        $this->postJson("/api/v1/real-estate/{$id}/tenants/{$tenant}/index-rent/apply", ['index' => 110], $headers)->assertStatus(422);
        $this->getJson("/api/v1/real-estate/{$id}/rental", $headers)->assertJsonPath('data.tenants.0.cold_rent', 840)->assertJsonPath('data.tenants.0.index_base', 105);

        $url = $this->postJson("/api/v1/real-estate/{$id}/tenants/{$tenant}/portal", [], $headers)->assertCreated()->json('data.url');
        $this->assertStringContainsString('/tenant/', $url);
        $this->getJson("/api/v1/real-estate/{$id}/tenants/{$tenant}/portal", $headers)->assertJsonPath('data.url', $url);
        $this->deleteJson("/api/v1/real-estate/{$id}/tenants/{$tenant}/portal", [], $headers)->assertOk();
        $this->getJson("/api/v1/real-estate/{$id}/tenants/{$tenant}/portal", $headers)->assertJsonPath('data.url', null);

        $this->get("/api/v1/real-estate/{$id}/tenants/{$tenant}/lease", $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get("/api/v1/real-estate/{$id}/tenants/{$tenant}/dunning?year=2025&level=1", $headers)->assertOk();
    }

    public function test_handover_protocols_with_photos(): void
    {
        Storage::fake('secure');
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $id = $this->postJson('/api/v1/real-estate', ['name' => 'MFH', 'usage' => 'rental', 'state' => 'NW', 'purchase_price' => 500000], $headers)->json('data.id');
        $tenant = $this->postJson("/api/v1/real-estate/{$id}/tenants", ['name' => 'Familie Beispiel', 'move_in_on' => '2025-01-01', 'cold_rent' => 800], $headers)->json('data.tenants.0.id');

        $body = ['tenant_id' => $tenant, 'kind' => 'move_in', 'handed_over_on' => '2025-01-01', 'notes' => 'Alles sauber', 'meters' => [['label' => 'Strom', 'number' => '123', 'reading' => '4711'], ['label' => '', 'number' => '', 'reading' => '']], 'keys' => [['label' => 'Haustür', 'count' => 2]], 'rooms' => [['label' => 'Küche', 'ok' => false, 'notes' => 'Kratzer']]];
        $this->postJson("/api/v1/real-estate/{$id}/handovers", ['kind' => 'nope'] + $body, $headers)->assertStatus(422);
        $this->postJson("/api/v1/real-estate/{$id}/handovers", ['tenant_id' => 999999] + $body, $headers)->assertStatus(422);
        $handover = $this->postJson("/api/v1/real-estate/{$id}/handovers", $body, $headers)->assertCreated()->assertJsonCount(1, 'data.handovers.0.meters')->assertJsonPath('data.handovers.0.tenant', 'Familie Beispiel')->json('data.id');

        $this->post("/api/v1/real-estate/{$id}/handovers/{$handover}/photos", ['photo' => UploadedFile::fake()->image('raum.jpg')], $headers + ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.handovers.0.photos', 1);
        $this->get("/api/v1/real-estate/{$id}/handovers/{$handover}/photos/0", $headers)->assertOk();
        $this->get("/api/v1/real-estate/{$id}/handovers/{$handover}/pdf", $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->putJson("/api/v1/real-estate/{$id}/handovers/{$handover}", ['kind' => 'move_out'] + $body, $headers)->assertOk()->assertJsonPath('data.handovers.0.kind', 'move_out');
        $this->deleteJson("/api/v1/real-estate/{$id}/handovers/{$handover}/photos/0", [], $headers)->assertOk()->assertJsonPath('data.handovers.0.photos', 0);
        $this->deleteJson("/api/v1/real-estate/{$id}/handovers/{$handover}", [], $headers)->assertOk()->assertJsonCount(0, 'data.handovers');
    }

    public function test_applicants_and_the_application_form(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $id = $this->postJson('/api/v1/real-estate', ['name' => 'MFH', 'usage' => 'rental', 'state' => 'NW', 'purchase_price' => 500000], $headers)->json('data.id');

        $this->getJson("/api/v1/real-estate/{$id}/applicants", $headers)->assertOk()->assertJsonPath('data.open', false)->assertJsonPath('data.url', null);
        $url = $this->postJson("/api/v1/real-estate/{$id}/applicants/open", ['intro' => 'Schöne 3-Zimmer-Wohnung', 'rent' => 1200], $headers)->assertOk()->assertJsonPath('data.open', true)->assertJsonPath('data.rent', 1200)->json('data.url');
        $this->assertStringContainsString('/apply/', $url);

        $applicant = RealEstateApplicant::create(['real_estate_id' => $id, 'name' => 'Max Bewerber', 'email' => 'max@example.com', 'net_income' => 4000, 'persons' => 2, 'status' => 'new', 'desired_move_in' => '2026-12-01']);
        $this->getJson("/api/v1/real-estate/{$id}/applicants", $headers)->assertJsonPath('data.applicants.0.name', 'Max Bewerber')->assertJsonPath('data.applicants.0.rent_to_income', 30);
        $this->putJson("/api/v1/real-estate/{$id}/applicants/{$applicant->id}", ['status' => 'viewing', 'rating' => 4, 'notes' => 'Netter Eindruck'], $headers)->assertOk()->assertJsonPath('data.applicants.0.status', 'viewing')->assertJsonPath('data.applicants.0.rating', 4);
        $this->putJson("/api/v1/real-estate/{$id}/applicants/{$applicant->id}", ['status' => 'nope'], $headers)->assertStatus(422);
        $this->get("/api/v1/real-estate/{$id}/applicants/{$applicant->id}/pdf", $headers)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/v1/real-estate/{$id}/applicants/{$applicant->id}/accept", [], $headers)->assertOk()->assertJsonPath('data.name', 'Max Bewerber')->assertJsonPath('data.move_in_on', '2026-12-01');
        $this->getJson("/api/v1/real-estate/{$id}/applicants", $headers)->assertJsonPath('data.applicants.0.status', 'accepted');

        $this->postJson("/api/v1/real-estate/{$id}/applicants/close", [], $headers)->assertOk()->assertJsonPath('data.open', false);
        $this->deleteJson("/api/v1/real-estate/{$id}/applicants/{$applicant->id}", [], $headers)->assertOk()->assertJsonCount(0, 'data.applicants');
    }
}
