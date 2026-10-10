<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ExternalLink;
use App\Models\HouseholdMember;
use App\Models\Item;
use App\Models\RealEstate;
use App\Models\RealEstateInvoice;
use App\Models\Transaction;
use App\Support\RealEstate\RealEstatePlan;
use App\Support\RealEstate\RealEstateTax;
use App\Support\RealEstate\SubsidyPrograms;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class RealEstateTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_a_property_is_created_and_the_purchase_costs_are_calculated(): void
    {
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('real-estate.index')
            ->set('form.name', 'Haus Lindenstraße')->set('form.state', 'NW')->set('form.purchase_price', '400000')
            ->call('create')
            ->assertRedirect();

        $realEstate = RealEstate::sole();
        $realEstate->update(['broker_rate' => 3.57]);
        $purchase = RealEstatePlan::purchase($realEstate->fresh());

        // NRW: 6.5 % transfer tax, 1.5 % notary, 0.5 % land registry, 3.57 % broker.
        $this->assertSame([26000.0, 6000.0, 2000.0, 14280.0, 48280.0, 448280.0], [
            $purchase['transfer_tax'], $purchase['notary'], $purchase['land_registry'], $purchase['broker'], $purchase['closing'], $purchase['total'],
        ]);

        $this->get(route('real-estate.index'))->assertOk()->assertSee('Haus Lindenstraße');
        $this->get(route('real-estate.show', $realEstate))->assertOk()->assertSee('Wohin das Geld geht');
    }

    public function test_budget_invoices_financing_and_the_funding_gap(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id, 'purchase_price' => 300000, 'state' => 'BY', 'equity' => 60000]);

        $component = Volt::test('real-estate.show', ['realEstate' => $realEstate->id])
            ->set('tab', 'budget')
            ->set('item.trade', 'bathroom')->set('item.planned_amount', '20000')->call('saveItem')
            ->set('item.trade', 'heating')->set('item.planned_amount', '15000')->call('saveItem')
            ->assertHasNoErrors();

        $bathroom = $realEstate->budgetItems()->where('trade', 'bathroom')->sole();
        $heating = $realEstate->budgetItems()->where('trade', 'heating')->sole();
        $this->assertSame(['Bad', false, true], [$bathroom->title, $bathroom->energy_measure, $heating->energy_measure]);

        $component->set('tab', 'invoices')
            ->set('invoice.vendor', 'Sanitär Meier')->set('invoice.budget_item_id', (string) $bathroom->id)->set('invoice.amount', '23000')->set('invoice.labor_amount', '9000')
            ->call('saveInvoice')->assertHasNoErrors();

        $component->set('tab', 'purchase')
            ->set('loan.lender', 'Sparkasse')->set('loan.amount', '270000')->set('loan.interest_rate', '3.6')->set('loan.repayment_rate', '2')
            ->call('addLoan')->assertHasNoErrors();

        $summary = RealEstatePlan::summary($realEstate->fresh());
        // Price 300,000 + 5.5 % closing costs in Bavaria = 316,500; bathroom over plan (23,000), heating as planned (15,000).
        $this->assertSame(316500.0, $summary['purchase']['total']);
        $this->assertSame(354500.0, $summary['expected_total']);
        $this->assertTrue($summary['items']->firstWhere('item.id', $bathroom->id)['over']);
        $this->assertSame(1260.0, $summary['monthly']['loan']);
        $this->assertSame(24500.0, $summary['funding']['gap']);

        Volt::test('real-estate.show', ['realEstate' => $realEstate->id])->assertSee('Finanzierungslücke')->assertSee('über Budget');
    }

    public function test_the_loan_schedule_ends_when_the_loan_is_repaid(): void
    {
        $realEstate = RealEstate::factory()->create();
        $loan = $realEstate->loans()->create(['lender' => 'Bank', 'amount' => 100000, 'interest_rate' => 3, 'repayment_rate' => 3, 'fixed_years' => 10]);

        $this->assertSame(500.0, $loan->monthlyRate());
        $schedule = RealEstatePlan::schedule($loan, 40);
        $this->assertSame(0.0, end($schedule)['balance']);
        $this->assertLessThan(25, count($schedule));
        $this->assertEqualsWithDelta(2961, $schedule[0]['interest'], 5);
    }

    public function test_letting_depreciation_interest_and_the_15_percent_rule(): void
    {
        $realEstate = RealEstate::factory()->rental(1000)->create([
            'purchase_price' => 200000, 'purchase_date' => '2025-01-15', 'state' => 'BY', 'land_share' => 25, 'construction_year' => 1990,
            'broker_rate' => 0, 'monthly_costs' => 50, 'tax_rate' => 40,
        ]);
        $realEstate->invoices()->create(['vendor' => 'Maler', 'invoice_date' => '2026-03-01', 'paid_on' => '2026-03-10', 'amount' => 5950]);

        $tax = RealEstateTax::forYear($realEstate->fresh(), 2026);

        // Building costs: (200,000 + 5.5 % closing costs in Bavaria) × 75 % = 158,250; 2 % depreciation = 3,165.
        $this->assertSame(3165.0, $tax['rental']['depreciation']);
        $this->assertSame(5950.0, $tax['rental']['maintenance']);
        $this->assertSame(12000.0, $tax['rental']['rent']);
        $this->assertSame(12000.0 - 3165 - 5950 - 600, $tax['rental']['result']);
        $this->assertSame(round(-(12000.0 - 3165 - 5950 - 600) * 0.4, 2), $tax['rental']['effect']);
        $this->assertNull($tax['owner']);

        // 29,750 € (net 25,000 > 15 % of 158,250) within three years: added to the depreciation base.
        $realEstate->invoices()->create(['vendor' => 'Dach GmbH', 'invoice_date' => '2026-05-01', 'paid_on' => '2026-05-10', 'amount' => 23800]);
        $tax = RealEstateTax::forYear($realEstate->fresh(), 2026);
        $this->assertTrue($tax['capitalized']);
        $this->assertSame(0.0, $tax['rental']['maintenance']);
        $this->assertSame(round((158250 + 29750) * 0.02, 2), $tax['rental']['depreciation']);
        $this->assertNotEmpty($tax['warnings']);
    }

    public function test_living_in_it_craftsman_services_and_energy_renovation(): void
    {
        $realEstate = RealEstate::factory()->create(['purchase_price' => 300000, 'purchase_date' => '2025-06-01', 'construction_year' => 1970]);
        $bathroom = $realEstate->budgetItems()->create(['trade' => 'bathroom', 'title' => 'Bad']);
        $windows = $realEstate->budgetItems()->create(['trade' => 'windows', 'title' => 'Fenster', 'energy_measure' => true]);
        $heating = $realEstate->budgetItems()->create(['trade' => 'heating', 'title' => 'Wärmepumpe', 'energy_measure' => true]);
        $realEstate->subsidies()->create(['program' => 'kfw_458', 'title' => 'KfW 458', 'kind' => 'grant', 'amount' => 10000, 'status' => 'approved', 'budget_item_id' => $heating->id]);

        $realEstate->invoices()->create(['vendor' => 'Sanitär', 'budget_item_id' => $bathroom->id, 'invoice_date' => '2026-02-01', 'paid_on' => '2026-02-10', 'amount' => 20000, 'labor_amount' => 8000]);
        $realEstate->invoices()->create(['vendor' => 'Fensterbau', 'budget_item_id' => $windows->id, 'invoice_date' => '2026-04-01', 'paid_on' => '2026-04-10', 'amount' => 30000, 'labor_amount' => 6000]);
        $realEstate->invoices()->create(['vendor' => 'Heizung', 'budget_item_id' => $heating->id, 'invoice_date' => '2026-05-01', 'paid_on' => '2026-05-10', 'amount' => 25000, 'labor_amount' => 5000]);
        $realEstate->invoices()->create(['vendor' => 'Bar bezahlt', 'budget_item_id' => $bathroom->id, 'invoice_date' => '2026-06-01', 'amount' => 500, 'labor_amount' => 500]);

        $tax = RealEstateTax::forYear($realEstate->fresh(), 2026);

        // § 35a: 20 % of 8,000 € labour (bathroom) capped at 1,200 €; the heating is subsidised.
        $this->assertSame(1200.0, $tax['owner']['craftsman']);
        // § 35c: 7 % of 30,000 € windows in 2026, 7 % in 2027, 6 % in 2028.
        $this->assertSame(2100.0, $tax['owner']['energy']);
        $this->assertSame(2100.0, RealEstateTax::forYear($realEstate->fresh(), 2027)['owner']['energy']);
        $this->assertSame(1800.0, RealEstateTax::forYear($realEstate->fresh(), 2028)['owner']['energy']);
        $this->assertSame(0.0, RealEstateTax::forYear($realEstate->fresh(), 2029)['owner']['energy']);
        $this->assertNull($tax['rental']);
        $this->assertStringContainsString('gefördert', implode(' ', $tax['warnings']));
    }

    public function test_subsidy_suggestions_depend_on_usage_and_energy_measures(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $realEstate = RealEstate::factory()->rental()->for($household)->create(['created_by' => $user->id]);

        $keys = array_keys(SubsidyPrograms::suggestionsFor($realEstate->load('budgetItems', 'subsidies')));
        $this->assertNotContains('kfw_124', $keys);
        $this->assertNotContains('bafa_beg_em', $keys);

        $realEstate->budgetItems()->create(['trade' => 'insulation', 'title' => 'Dämmung', 'energy_measure' => true]);
        $this->assertContains('bafa_beg_em', array_keys(SubsidyPrograms::suggestionsFor($realEstate->fresh()->load('budgetItems', 'subsidies'))));

        Volt::test('real-estate.show', ['realEstate' => $realEstate->id])
            ->set('tab', 'subsidies')
            ->call('useProgram', 'bafa_beg_em')->set('subsidy.amount', '4000')->set('subsidy.status', 'applied')
            ->call('saveSubsidy')->assertHasNoErrors()
            ->assertDontSee('BAFA BEG Einzelmaßnahmen – ')
            ->call('setSubsidyStatus', $realEstate->subsidies()->sole()->id, 'approved');

        $subsidy = $realEstate->subsidies()->sole();
        $this->assertSame(['approved', '2026-09-28'], [$subsidy->status, $subsidy->approved_on->toDateString()]);
        $this->assertSame(4000.0, RealEstatePlan::summary($realEstate->fresh())['funding']['grants']);

        Volt::test('real-estate.show', ['realEstate' => $realEstate->id])->call('createTask', 'subsidy', $subsidy->id);
        $this->assertStringContainsString('BAFA', Item::where('type', Item::TYPE_TODO)->sole()->title);
    }

    public function test_invoices_with_receipts_and_bank_bookings(): void
    {
        Storage::fake('local');
        Storage::fake('secure');
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id]);
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $booking = Transaction::factory()->create(['bank_account_id' => $account->id, 'amount' => -1785, 'booked_on' => '2026-09-20', 'counterparty' => 'ELEKTRO SCHMIDT']);

        Volt::test('real-estate.show', ['realEstate' => $realEstate->id])
            ->set('tab', 'invoices')
            ->set('invoice.vendor', 'Elektro Schmidt')->set('invoice.invoice_date', '2026-09-15')->set('invoice.amount', '1785')
            ->set('receipt', UploadedFile::fake()->create('rechnung.pdf', 100, 'application/pdf'))
            ->call('saveInvoice')->assertHasNoErrors()
            ->assertSee('Mit Buchung verknüpfen')
            ->call('linkTransaction', RealEstateInvoice::sole()->id, $booking->id);

        $invoice = RealEstateInvoice::sole();
        $this->assertSame([$booking->id, '2026-09-20'], [$invoice->transaction_id, $invoice->paid_on->toDateString()]);
        Storage::disk('secure')->assertExists($invoice->receipt_path);
        $this->get(route('real-estate.receipt', $invoice))->assertOk();

        $invoice->delete();
        Storage::disk('secure')->assertMissing($invoice->receipt_path);
    }

    public function test_private_properties_other_households_and_the_addon_switch(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $partner = $this->addMemberTo($household);
        $private = RealEstate::factory()->for($household)->create(['created_by' => $user->id, 'visibility' => Item::VISIBILITY_PRIVATE, 'name' => 'Geheim']);
        $invoice = RealEstateInvoice::factory()->create(['real_estate_id' => $private->id, 'receipt_path' => 'x.pdf']);
        $foreign = RealEstate::factory()->create();

        $this->actingAs($partner);
        $this->get(route('real-estate.index'))->assertOk()->assertDontSee('Geheim');
        $this->get(route('real-estate.show', $private))->assertNotFound();
        $this->get(route('real-estate.show', $foreign))->assertNotFound();
        $this->get(route('real-estate.receipt', $invoice))->assertNotFound();

        HouseholdMember::where('user_id', $partner->id)->update(['permissions' => json_encode(['real_estate' => false])]);
        $this->get(route('real-estate.index'))->assertNotFound();

        $household->setAddon('real_estate', false);
        $this->actingAs($user)->get(route('real-estate.index'))->assertNotFound();
    }

    public function test_trades_are_moved_and_stretched_in_the_schedule(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id]);
        $roof = $realEstate->budgetItems()->create(['trade' => 'roof', 'title' => 'Dach', 'start_on' => '2026-10-01', 'end_on' => '2026-10-10']);
        $foreign = RealEstate::factory()->create()->budgetItems()->create(['trade' => 'roof', 'title' => 'Fremd', 'start_on' => '2026-10-01']);

        $component = Volt::test('real-estate.show', ['realEstate' => $realEstate->id])
            ->set('tab', 'budget')
            ->assertSee('Balken ziehen verschiebt das Gewerk')
            ->call('shiftItem', $roof->id, 7);
        $this->assertSame(['2026-10-08', '2026-10-17'], [$roof->fresh()->start_on->toDateString(), $roof->fresh()->end_on->toDateString()]);

        $component->call('shiftItem', $roof->id, -30, true);
        $this->assertSame(['2026-10-08', '2026-10-08'], [$roof->fresh()->start_on->toDateString(), $roof->fresh()->end_on->toDateString()]);

        try {
            $component->call('shiftItem', $foreign->id, 3);
        } catch (ModelNotFoundException) {
        }

        $this->assertSame('2026-10-01', $foreign->fresh()->start_on->toDateString());
    }

    public function test_a_new_invoice_stays_open_for_further_documents(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id]);

        $component = Volt::test('real-estate.show', ['realEstate' => $realEstate->id])
            ->set('tab', 'invoices')
            ->assertSee('Nach dem Speichern kannst du beliebig viele weitere Dokumente anhängen')
            ->set('invoice.vendor', 'Dachdecker Kurz')->set('invoice.invoice_date', '2026-09-01')->set('invoice.amount', '5000')
            ->call('saveInvoice')
            ->assertHasNoErrors();

        $invoice = $realEstate->invoices()->sole();
        $component->assertSet('editingInvoiceId', $invoice->id)->assertSeeLivewire('documents-panel');

        foreach (['Abschlagsrechnung 1', 'Abschlagsrechnung 2'] as $title) {
            ExternalLink::create(['kind' => ExternalLink::MAIL, 'household_id' => $household->id, 'linkable_type' => 'real_estate_invoice', 'linkable_id' => $invoice->id, 'title' => $title, 'url' => 'https://example.com/'.urlencode($title), 'created_by' => $user->id]);
        }

        Volt::test('real-estate.show', ['realEstate' => $realEstate->id])->set('tab', 'invoices')->assertSee('📄 2');
    }

    public function test_financing_controlling_tracks_equity_and_loan_draws(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $realEstate = RealEstate::factory()->for($household)->create(['created_by' => $user->id, 'purchase_price' => 300000, 'state' => 'BY', 'equity' => 80000]);
        $loan = $realEstate->loans()->create(['lender' => 'Sparkasse', 'kind' => 'annuity', 'amount' => 250000, 'interest_rate' => 3.6, 'repayment_rate' => 2]);

        $component = Volt::test('real-estate.show', ['realEstate' => $realEstate->id])
            ->set('tab', 'controlling')
            // Equity contribution: fully paid.
            ->set('draw.source', 'equity')->set('draw.label', 'Eigenkapital Notar')->set('draw.planned_amount', '80000')->set('draw.actual_amount', '80000')->set('draw.actual_on', '2026-03-01')
            ->call('saveDraw')->assertHasNoErrors()
            // First loan draw: requested 100,000, only 60,000 disbursed so far.
            ->set('draw.source', 'loan')->set('draw.loan_id', (string) $loan->id)->set('draw.label', '1. Abruf Rohbau')->set('draw.planned_amount', '100000')->set('draw.actual_amount', '60000')
            ->call('saveDraw')->assertHasNoErrors();

        $equity = $realEstate->draws()->where('source', 'equity')->sole();
        $loanDraw = $realEstate->draws()->where('source', 'loan')->sole();
        $this->assertSame($loan->id, $loanDraw->loan_id);
        $this->assertSame(100, $equity->utilisation());
        $this->assertSame(60, $loanDraw->utilisation());

        // A running renovation invoice feeds the by-month costs.
        $realEstate->invoices()->create(['vendor' => 'Rohbau GmbH', 'invoice_date' => '2026-04-15', 'amount' => 40000]);

        $controlling = RealEstatePlan::controlling($realEstate->fresh()->load(['draws', 'invoices', 'budgetItems.invoices', 'loans', 'subsidies']), RealEstatePlan::summary($realEstate->fresh()));
        $this->assertSame(180000.0, $controlling['planned_total']);
        $this->assertSame(140000.0, $controlling['actual_total']);
        $this->assertSame(40000.0, $controlling['remaining']);
        $this->assertSame(60000.0, $controlling['sources']['loan']['actual']);
        $this->assertSame(80000.0, $controlling['sources']['equity']['actual']);
        $this->assertCount(1, $controlling['renovation_by_month']);
        $this->assertSame(40000.0, $controlling['renovation_by_month'][0]['amount']);

        // Marking the loan draw done tops it up to the requested amount.
        $component->call('markDrawDone', $loanDraw->id);
        $this->assertSame(100000.0, $loanDraw->fresh()->actual_amount);

        Volt::test('real-estate.show', ['realEstate' => $realEstate->id])->set('tab', 'controlling')->assertSee('1. Abruf Rohbau')->assertSee('Eigenkapital Notar');
    }
}
