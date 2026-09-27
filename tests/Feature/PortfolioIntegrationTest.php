<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Portfolio;
use App\Models\PortfolioSnapshot;
use App\Models\PortfolioTransaction;
use App\Models\SavingsPlan;
use App\Models\Security;
use App\Models\User;
use App\Support\BrainSearch;
use App\Support\Portfolio\Positions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class PortfolioIntegrationTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private User $user;

    private int $householdId;

    private Portfolio $portfolio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-27 12:00'));
        Http::preventStrayRequests();

        [$this->user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $this->householdId = $household->id;
        $this->actingAs($this->user);

        $this->portfolio = Portfolio::factory()->for($this->user)->create(['name' => 'Trade Republic']);
        Security::factory()->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'last_price' => 120, 'previous_close' => 115, 'currency' => 'EUR', 'price_updated_at' => now()]);
        PortfolioTransaction::factory()->for($this->portfolio)->create(['quantity' => 10, 'price' => 100, 'fees' => 0, 'executed_on' => '2025-01-10']);
        PortfolioTransaction::factory()->for($this->portfolio)->create(['type' => 'dividend', 'quantity' => 10, 'price' => 2.35, 'fees' => 0, 'taxes' => 0, 'executed_on' => '2025-10-15']);
        Positions::recalculate($this->portfolio);
        PortfolioSnapshot::create(['portfolio_id' => $this->portfolio->id, 'taken_at' => now()->subDay(), 'total_value' => 1150, 'total_cost' => 1000]);
        PortfolioSnapshot::create(['portfolio_id' => $this->portfolio->id, 'taken_at' => now(), 'total_value' => 1200, 'total_cost' => 1000]);
        SavingsPlan::create(['portfolio_id' => $this->portfolio->id, 'symbol' => 'SAP.DE', 'name' => 'SAP SE', 'amount' => 50, 'frequency' => 'monthly', 'day_of_month' => 1, 'next_date' => '2026-10-01', 'active' => true]);
    }

    public function test_the_dashboard_and_calendar_show_value_movers_savings_plans_and_expected_dividends(): void
    {
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('1.200,00 €')
            ->assertSee('+50,00 €')
            ->assertSee('+4,35 %')
            ->assertSee('Sparplan: SAP SE');

        Volt::test('profile.calendar-feed-form')->call('createLink');
        $ics = $this->get(route('calendar.feed', ['token' => $this->user->fresh()->calendar_token]))->assertOk()->getContent();

        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261001', $ics);
        $this->assertStringContainsString('Erwartete Dividende: SAP SE (23\,50 €)', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261015', $ics);

        // Without the addon neither card nor calendar entries appear.
        $this->user->currentHousehold()->setAddon('portfolio', false);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Sparplan: SAP SE');
        $this->assertStringNotContainsString('SAP SE', $this->get(route('calendar.feed', ['token' => $this->user->fresh()->calendar_token]))->getContent());
    }

    public function test_securities_are_found_turned_into_tasks_and_given_to_the_ai(): void
    {
        Volt::test('search-palette')->set('query', 'sap')->assertSee('SAP SE · SAP.DE');

        Volt::test('portfolio.security', ['symbol' => 'SAP.DE'])->call('createTask', 'SAP.DE');
        $task = Item::where('type', Item::TYPE_TODO)->sole();
        $this->assertSame('SAP SE prüfen', $task->title);
        $this->assertTrue($task->isPrivate());
        $this->assertStringContainsString(route('portfolio.security', ['symbol' => 'SAP.DE']), $task->body());
        $this->assertSame(['portfolio'], $task->tags->pluck('name')->all());

        Volt::test('portfolio.security', ['symbol' => 'SAP.DE'])->assertSee('Erwähnt in')->assertSee('SAP SE prüfen');

        $documents = BrainSearch::documents('Wie läuft mein Depot?', $this->user, $this->householdId);
        $this->assertSame('portfolio:overview', $documents[0]['key']);
        $this->assertStringContainsString('SAP SE (SAP.DE), 10 Stück', $documents[0]['text']);
        $this->assertStringContainsString('Erwartete Dividende: SAP SE', $documents[0]['text']);

        $this->assertNotContains('portfolio:overview', array_column(BrainSearch::documents('Was muss ich einkaufen?', $this->user, $this->householdId), 'key'));
    }
}
