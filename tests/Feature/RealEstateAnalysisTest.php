<?php

namespace Tests\Feature;

use App\Models\RealEstate;
use App\Models\RealEstateAnalysis;
use App\Support\RealEstate\ProfitabilityAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class RealEstateAnalysisTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_purchase_costs_financing_cashflow_and_tax_are_calculated(): void
    {
        $result = ProfitabilityAnalysis::calculate(ProfitabilityAnalysis::defaults());

        $this->assertSame(27210.0, $result['closing']);
        $this->assertSame(267210.0, $result['loan']);
        $this->assertSame(1291.51, $result['monthly_rate']);
        $this->assertSame(9540.0, $result['noi']);
        $this->assertSame(25.0, $result['price_factor']);
        $this->assertSame(4.0, $result['gross_yield']);
        $this->assertSame(4908.15, $result['depreciation']);
        $this->assertSame(-5958.18, $result['cashflow_pre_tax']);
        $this->assertSame(-2319.29, $result['tax']);
        $this->assertSame(-3638.89, $result['cashflow_after_tax']);
        $this->assertSame('yellow', $result['verdict']['level']);
        $this->assertSame(-613.98, $result['operating_cashflow']);
        $this->assertCount(15, $result['projection']);
        $this->assertLessThan($result['projection'][0]['debt'], $result['projection'][14]['debt']);

        $good = ProfitabilityAnalysis::calculate(['purchase_price' => 200000, 'rent' => 1000, 'equity' => 80000] + ProfitabilityAnalysis::defaults());
        $this->assertSame('green', $good['verdict']['level']);
    }

    public function test_the_sale_scenario_speculation_tax_total_return_and_irr_are_calculated(): void
    {
        $base = ['purchase_price' => 200000, 'rent' => 1000, 'equity' => 80000] + ProfitabilityAnalysis::defaults();

        // Sold after 15 years: outside the ten-year period, so the gain is tax-free.
        $late = ProfitabilityAnalysis::calculate(['years' => 15] + $base);
        $this->assertFalse($late['speculation_period']);
        $this->assertSame(0.0, $late['speculation_tax']);
        $this->assertGreaterThan(0, $late['net_sale_proceeds']);
        $this->assertNotNull($late['irr']);
        // Total profit = cash flows + net sale proceeds − equity.
        $this->assertEqualsWithDelta($late['total_cashflow'] + $late['net_sale_proceeds'] - $late['equity'], $late['total_profit'], 0.01);

        // Sold after 8 years: still within the speculation period, so tax falls due.
        $early = ProfitabilityAnalysis::calculate(['years' => 8] + $base);
        $this->assertTrue($early['speculation_period']);
        $this->assertGreaterThan(0, $early['speculation_tax']);
    }

    public function test_the_let_vs_own_use_comparison_is_calculated(): void
    {
        $result = ProfitabilityAnalysis::calculate(['purchase_price' => 200000, 'rent' => 1000, 'equity' => 80000, 'years' => 15] + ProfitabilityAnalysis::defaults());
        $ownUse = $result['own_use'];

        // Saved cold rent in year one = 12 × 1,000 €.
        $this->assertSame(12000.0, $ownUse['saved_rent_first']);
        // Letting is the baseline total profit.
        $this->assertSame($result['total_profit'], $ownUse['let_total']);
        // The owner-occupier pays no speculation tax, so its exit matches the pre-tax proceeds.
        $this->assertGreaterThan(0, $ownUse['own_total']);
        $this->assertContains($ownUse['better'], ['let', 'own']);
        $this->assertSame(round(abs($ownUse['own_total'] - $ownUse['let_total']), 2), $ownUse['difference']);
    }

    public function test_a_subsidy_and_a_cheaper_loan_lower_the_financing(): void
    {
        $plain = ProfitabilityAnalysis::calculate(ProfitabilityAnalysis::defaults());
        $funded = ProfitabilityAnalysis::calculate(['subsidy' => 20000, 'kfw_loan' => 50000, 'kfw_rate' => 1.5] + ProfitabilityAnalysis::defaults());

        $this->assertSame(round($plain['loan'] - 20000, 2), $funded['loan']);
        $this->assertSame(20000.0, $funded['subsidy']);
        $this->assertLessThan($plain['blended_rate'], $funded['blended_rate']);
        $this->assertLessThan($plain['monthly_rate'], $funded['monthly_rate']);
    }

    public function test_renovation_items_are_summed_saved_and_a_checklist_is_shown(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('real-estate.analysis')
            ->assertSee('Vor dem Kauf')
            ->call('addRenoItem')->set('renoItems.0.label', 'Neue Heizung')->set('renoItems.0.amount', '25.000')
            ->call('addRenoItem')->set('renoItems.1.label', 'Bad', 'amount')->set('renoItems.1.amount', '15.000')
            ->assertSet('in.renovation', '40000')
            ->set('title', 'Sanierungsobjekt')->call('save')->assertHasNoErrors();

        $analysis = RealEstateAnalysis::sole();
        $this->assertSame(40000.0, (float) $analysis->inputs['renovation']);
        $this->assertCount(2, $analysis->inputs['renovation_items']);
        $this->assertSame('Neue Heizung', $analysis->inputs['renovation_items'][0]['label']);
    }

    public function test_a_calculation_is_entered_saved_and_prefilled_from_a_property(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('real-estate.analysis')->set('in.purchase_price', '250.000')->set('in.rent', '950')->set('in.local_rent_per_sqm', '11')
            ->set('in.state', 'NW')->assertSet('in.transfer_tax_rate', '6,5')
            ->assertSee('über dem Mietspiegel')
            ->set('title', 'ETW Köln')->call('save')->assertHasNoErrors();

        $analysis = RealEstateAnalysis::sole();
        $this->assertEquals(250000, $analysis->inputs['purchase_price']);
        $this->assertSame('NW', $analysis->inputs['state']);
        $this->get(route('real-estate.analysis', ['id' => $analysis->id]))->assertOk()->assertSee('ETW Köln');

        $property = RealEstate::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Haus Lindenstraße', 'purchase_price' => 420000, 'monthly_rent' => 1500, 'living_area' => 110]);
        $this->get(route('real-estate.analysis', ['real_estate' => $property->id]))->assertOk()
            ->assertSee('Haus Lindenstraße')->assertSee('420.000 €')->assertSee('Kaufpreisfaktor');
    }
}
