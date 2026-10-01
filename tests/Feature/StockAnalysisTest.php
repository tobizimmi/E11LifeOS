<?php

namespace Tests\Feature;

use App\Models\Security;
use App\Models\SecurityNote;
use App\Models\SecurityPrice;
use App\Support\Portfolio\KeyFigureCheck;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\TechnicalAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class StockAnalysisTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-01 12:00'));
    }

    public function test_cash_flow_figures_and_their_history_are_kept(): void
    {
        Http::fake([
            'fc.yahoo.com*' => Http::response(''),
            'query1.finance.yahoo.com/v1/test/getcrumb' => Http::response('crumb123'),
            'query2.finance.yahoo.com/v10/finance/quoteSummary/*' => Http::response(['quoteSummary' => ['result' => [[
                'summaryDetail' => ['trailingPE' => ['raw' => 20.0], 'marketCap' => ['raw' => 100e9], 'dividendYield' => ['raw' => 0.025]],
                'financialData' => ['totalRevenue' => ['raw' => 50e9], 'operatingMargins' => ['raw' => 0.2], 'ebitda' => ['raw' => 13e9], 'operatingCashflow' => ['raw' => 12.5e9], 'freeCashflow' => ['raw' => 6e9]],
                'defaultKeyStatistics' => ['enterpriseToEbitda' => ['raw' => 9.1]],
            ]]]]),
            '*' => Http::response([], 404),
        ]);

        $security = Security::factory()->create(['symbol' => 'SAP.DE', 'last_price' => 210.0, 'fundamentals' => ['history' => [['date' => '2026-08-03', 'price' => 190.0, 'pe_ratio' => 18.0]]]]);
        $figures = app(MarketData::class)->fundamentals($security);

        $this->assertSame([10e9, 13e9, 8.0, 9.1, 0.06], [$figures['ebit'], $figures['ebitda'], $figures['price_to_cashflow'], $figures['ev_to_ebitda'], $figures['free_cashflow_yield']]);
        $this->assertSame(['2026-08-03', '2026-10-01'], array_column($figures['history'], 'date'));
        $this->assertSame(8.0, $figures['history'][1]['price_to_cashflow']);
        $this->assertSame('green', collect(KeyFigureCheck::assess($figures))->firstWhere('key', 'price_to_cashflow')['level']);

        // A second update in the same week replaces this week's point.
        $again = app(MarketData::class)->fundamentals($security->fresh(), refresh: true);
        $this->assertCount(2, $again['history']);
    }

    public function test_chart_analysis_reads_trend_rsi_and_crosses(): void
    {
        // Falling for 230 days, then rising strongly: price above the 200-day line, overbought.
        $closes = [...array_map(fn (int $day) => 200 - $day * 0.3, range(0, 229)), ...array_map(fn (int $day) => 131 + $day * 3, range(1, 40))];
        $analysis = TechnicalAnalysis::analyse($closes);

        $this->assertSame('up', $analysis['trend']);
        $this->assertGreaterThan(70, $analysis['rsi']);
        $this->assertContains('200-Tage-Linie', array_column($analysis['signals'], 'label'));
        $this->assertSame(round(array_sum(array_slice($closes, -200)) / 200, 4), round($analysis['sma200'], 4));
        $this->assertNull(TechnicalAnalysis::analyse([1.0, 2.0]));
        $this->assertSame([null, null, 2.0, 3.0], TechnicalAnalysis::smaSeries([1.0, 2.0, 3.0, 4.0], 3));
    }

    public function test_security_page_shows_the_analysis_and_own_notes(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        Security::factory()->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'currency' => 'EUR', 'last_price' => 210.0, 'price_updated_at' => now(), 'fundamentals' => ['pe_ratio' => 20.0, 'price_to_cashflow' => 8.0, 'ebitda' => 13e9, 'history' => [['date' => '2026-08-03', 'pe_ratio' => 18.0], ['date' => '2026-09-28', 'pe_ratio' => 20.0]]], 'fundamentals_updated_at' => now()]);

        for ($day = 260; $day >= 1; $day--) {
            SecurityPrice::create(['symbol' => 'SAP.DE', 'date' => today()->subDays($day)->toDateString(), 'close' => 150 + (260 - $day) * 0.2]);
        }

        Volt::test('portfolio.security', ['symbol' => 'SAP.DE'])
            ->assertSee('Chartanalyse')->assertSee('Aufwärtstrend')->assertSee('13,0 Mrd.')
            ->assertSee('Kennzahlen im Zeitverlauf')
            ->set('note', 'Marge prüfen nach Q3')->call('addNote')->assertHasNoErrors()->assertSee('Marge prüfen nach Q3');

        $note = SecurityNote::sole();
        $this->assertSame('Marge prüfen nach Q3', $note->body);

        $other = $this->memberWithHousehold()[0];
        $this->actingAs($other);
        Volt::test('portfolio.security', ['symbol' => 'SAP.DE'])->assertDontSee('Marge prüfen nach Q3')->call('deleteNote', $note->id)->assertNotFound();
    }
}
