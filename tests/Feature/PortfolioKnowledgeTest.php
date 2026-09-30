<?php

namespace Tests\Feature;

use App\Models\InvestmentThesis;
use App\Models\Security;
use App\Support\Portfolio\KeyFigureCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PortfolioKnowledgeTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-30 12:00'));
        Http::preventStrayRequests();
    }

    public function test_key_figures_are_explained_with_a_traffic_light(): void
    {
        $rows = collect(KeyFigureCheck::assess(['pe_ratio' => 32.5, 'forward_pe' => 25.1, 'return_on_equity' => 0.21, 'debt_to_equity' => 2.6, 'dividend_yield' => 0.085, 'operating_margins' => 0.03]))->keyBy('key');

        $this->assertSame('red', $rows['pe_ratio']['level']);
        $this->assertSame('green', $rows['forward_pe']['level']);
        $this->assertSame('green', $rows['return_on_equity']['level']);
        $this->assertSame('red', $rows['debt_to_equity']['level']);
        $this->assertSame('yellow', $rows['dividend_yield']['level']);
        $this->assertSame('8,5 %', $rows['dividend_yield']['value']);
        $this->assertStringContainsString('Ungewöhnlich hoch', $rows['dividend_yield']['note']);
        $this->assertSame(['green' => 2, 'rated' => 6], KeyFigureCheck::score($rows->values()->all()));
    }

    public function test_an_investment_thesis_is_written_on_the_security_and_listed_in_the_knowledge_area(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $this->actingAs($user);
        Security::factory()->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'last_price' => 120, 'previous_close' => 115, 'currency' => 'EUR', 'price_updated_at' => now(),
            'fundamentals' => ['pe_ratio' => 22.0, 'return_on_equity' => 0.18], 'fundamentals_updated_at' => now()]);

        Volt::test('portfolio.security', ['symbol' => 'SAP.DE'])
            ->assertSee('Was die Kennzahlen sagen')
            ->call('editThesis')->set('thesis.thesis', 'Cloud-Umstieg sorgt für wiederkehrende Umsätze.')->set('thesis.sell_if', 'Cloud-Wachstum unter 10 %')
            ->set('thesis.stock_type', 'stalwart')->set('thesis.buy_below', '125,50')->set('thesis.review_on', '2026-09-01')->call('saveThesis')->assertHasNoErrors()
            ->assertSee('Cloud-Umstieg sorgt')->assertSee('Zeit, deine These zu prüfen')->assertSee('unter deinem Kauflimit');

        $thesis = InvestmentThesis::sole();
        $this->assertSame(125.5, $thesis->buy_below);

        Volt::test('knowledge.index')->set('tab', 'theses')->assertSee('SAP SE')->assertSee('Überprüfung fällig');
        Volt::test('knowledge.index')->set('category', 'metrics')->assertSee('KGV (Kurs-Gewinn-Verhältnis)')->assertDontSee('Eigenkapital: Nebenkosten zuerst decken');
    }
}
