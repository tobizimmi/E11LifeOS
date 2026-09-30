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
        $this->assertSame('red', $result['verdict']['level']);
        $this->assertCount(15, $result['projection']);
        $this->assertLessThan($result['projection'][0]['debt'], $result['projection'][14]['debt']);

        $good = ProfitabilityAnalysis::calculate(['purchase_price' => 200000, 'rent' => 1000, 'equity' => 80000] + ProfitabilityAnalysis::defaults());
        $this->assertSame('green', $good['verdict']['level']);
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
