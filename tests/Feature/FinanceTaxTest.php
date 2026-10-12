<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\RealEstate;
use App\Models\Transaction;
use App\Support\Finance\TaxCategories;
use App\Support\Finance\TaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class FinanceTaxTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    private function booking(BankAccount $account, string $payee, float $amount, array $attributes = []): Transaction
    {
        return Transaction::factory()->create($attributes + ['bank_account_id' => $account->id, 'counterparty' => $payee, 'purpose' => '', 'amount' => $amount, 'booked_on' => '2026-03-15', 'category' => 'other']);
    }

    public function test_suggestions_come_from_payee_words_and_categories_but_are_not_assigned_automatically(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);

        $this->assertSame('childcare', TaxCategories::suggest($this->booking($account, 'Kita Sonnenschein', -320)));
        $this->assertSame('insurance', TaxCategories::suggest($this->booking($account, 'HUK', -45, ['category' => 'insurance'])));
        $this->assertSame('donations', TaxCategories::suggest($this->booking($account, 'Tafel e.V.', -50, ['category' => 'donations'])));
        $this->assertNull(TaxCategories::suggest($this->booking($account, 'Geschenk Oma', -50, ['category' => 'donations'])));
        $this->assertNull(TaxCategories::suggest($this->booking($account, 'Kita Sonnenschein', 320)));
        $this->assertSame(0, Transaction::whereNotNull('tax_category')->count());

        $this->actingAs($user);
        Volt::test('finance.index')->set('tab', 'tax')->set('taxYear', 2026)
            ->assertSee('3 Buchungen könnten steuerlich relevant sein')
            ->call('acceptTaxSuggestions');
        $this->assertSame(3, Transaction::whereNotNull('tax_category')->count());
    }

    public function test_the_tax_year_applies_flat_rates_caps_and_labour_costs(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $user->update(['tax_rate' => 40, 'tax_children' => 1]);
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $this->booking($account, 'Fachbuch', -1500, ['tax_category' => 'work']);
        $this->booking($account, 'Kita', -7000, ['tax_category' => 'childcare']);
        $this->booking($account, 'Tafel e.V.', -200, ['tax_category' => 'donations']);
        $this->booking($account, 'Sanitär Meier', -3000, ['tax_category' => 'craftsman', 'tax_amount' => 1800]);
        $this->booking($account, 'Putzhilfe', -2400, ['tax_category' => 'household_services']);
        $this->booking($account, 'Alte Spende', -100, ['tax_category' => 'donations', 'booked_on' => '2025-12-20']);
        $this->booking($account, 'Fremd', -999, ['tax_category' => 'donations', 'user_id' => $this->memberWithHousehold()[0]->id]);

        $tax = TaxYear::build($user->fresh(), $household->id, 2026);

        // Work: 1,500 − 1,230 flat rate = 270; childcare: 80 % of 7,000 = 5,600, capped at 4,800; donations 200.
        $this->assertSame(270.0, $tax['deductions']['work']['deductible']);
        $this->assertSame(4800.0, $tax['deductions']['childcare']['deductible']);
        $this->assertSame(200.0, $tax['deductions']['donations']['deductible']);
        $this->assertSame(5270.0, $tax['deductible']);
        $this->assertSame(2108.0, $tax['deduction_effect']);
        // § 35a: 20 % of 1,800 € labour = 360; 20 % of 2,400 € household services = 480.
        $this->assertSame(360.0, $tax['credits']['craftsman']['credit']);
        $this->assertSame(480.0, $tax['credits']['household_services']['credit']);
        $this->assertSame(2948.0, $tax['effect']);
    }

    public function test_craftsman_labour_from_properties_shares_the_cap(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $this->booking($account, 'Maler', -4000, ['tax_category' => 'craftsman', 'tax_amount' => 3000]);
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id, 'name' => 'Unser Haus']);
        $realEstate->invoices()->create(['vendor' => 'Elektro', 'invoice_date' => '2026-04-01', 'paid_on' => '2026-04-10', 'amount' => 8000, 'labor_amount' => 5000]);

        $tax = TaxYear::build($user, $household->id, 2026);

        $this->assertSame(8000.0, $tax['credits']['craftsman']['spent']);
        $this->assertSame(1200.0, $tax['credits']['craftsman']['credit']);
        $this->assertSame('Unser Haus', $tax['properties'][0]['model']->name);
    }

    public function test_bookings_are_tagged_with_labour_costs_and_exported(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $booking = $this->booking($account, 'Gartenbau Grün', -600);
        $this->actingAs($user);

        Volt::test('finance.index')->set('tab', 'tax')->set('taxYear', 2026)
            ->set('taxSearch', 'garten')->assertSee('Gartenbau Grün')
            ->call('setTaxCategory', $booking->id, 'household_services')
            ->call('setTaxAmount', $booking->id, '450,00')
            ->call('setTaxAmount', $booking->id, '700')->assertHasErrors('taxAmount.'.$booking->id)
            ->call('saveTaxSettings', '42', '2');

        $this->assertSame(['household_services', 450.0], [$booking->fresh()->tax_category, $booking->fresh()->tax_amount]);
        $this->assertSame([42.0, 2], [$user->fresh()->tax_rate, $user->fresh()->tax_children]);

        $csv = $this->get(route('finance.tax-export', 2026))->assertOk()->streamedContent();
        $this->assertStringContainsString('Gartenbau Grün', $csv);
        $this->assertStringContainsString('450,00', $csv);
        $this->assertStringContainsString('Haushaltsnahe Dienstleistungen (§ 35a)', $csv);

        // A counterparty that looks like a spreadsheet formula is defused (CSV formula injection).
        $this->booking($account, '=cmd|\'/c calc\'!A1', -10, ['tax_category' => 'household_services']);
        $csv = $this->get(route('finance.tax-export', 2026))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=cmd|'/c calc'!A1", $csv);
        $this->assertStringNotContainsString(';=cmd', $csv);

        Volt::test('finance.index')->call('setTaxCategory', $booking->id, '');
        $this->assertNull($booking->fresh()->tax_amount);
    }
}
