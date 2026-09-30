<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Household;
use App\Models\RealEstate;
use App\Models\RealEstateApplicant;
use App\Models\RealEstateRentPayment;
use App\Models\RealEstateTenant;
use App\Models\RealEstateUtilityStatement;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\NewRentalApplicant;
use App\Support\RealEstate\RealEstateTax;
use App\Support\RealEstate\RentalAccounting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class RentalTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    /**
     * @return array{0: User, 1: Household, 2: RealEstate}
     */
    private function rental(): array
    {
        [$user, $household] = $this->memberWithHousehold();
        $realEstate = RealEstate::factory()->rental(900)->for($household)->create([
            'created_by' => $user->id, 'name' => 'Lindenhaus', 'street' => 'Lindenstraße 5', 'postal_code' => '80331', 'city' => 'München',
            'living_area' => 100, 'purchase_price' => 300000, 'purchase_date' => '2024-03-01',
        ]);

        return [$user, $household, $realEstate];
    }

    public function test_tenants_are_managed_and_the_lease_is_generated(): void
    {
        [$user, $household, $realEstate] = $this->rental();
        $this->actingAs($user);

        Volt::test('real-estate.rental', ['realEstateId' => $realEstate->id])
            ->call('newTenant')
            ->assertSet('tenant.cold_rent', '900')
            ->set('tenant.name', 'Maria Muster')
            ->set('tenant.unit', 'EG links')
            ->set('tenant.move_in_on', '2026-10-01')
            ->set('tenant.utilities_advance', '180,50')
            ->set('tenant.deposit', '2.800')
            ->set('tenant.rent_adjustment', 'index')
            ->call('saveTenant')
            ->assertHasErrors('tenant.deposit')
            ->set('tenant.deposit', '2.700')
            ->call('saveTenant')
            ->assertHasNoErrors()
            ->assertSee('Maria Muster')
            ->assertSee('900,00 € kalt + 180,50 € Vorauszahlung = 1.080,50 €')
            ->set('landlord.landlord_iban', 'DE89 3704 0044 0532 0130 00')
            ->set('landlord.landlord_address', 'Hauptstraße 1, 80331 München')
            ->call('saveLandlord')
            ->assertHasNoErrors();

        $tenant = RealEstateTenant::sole();
        $this->assertSame(['EG links', 900.0, 180.5, 2700.0, 'index'], [$tenant->unit, $tenant->cold_rent, $tenant->utilities_advance, $tenant->deposit, $tenant->rent_adjustment]);
        $this->assertSame('DE89370400440532013000', $realEstate->fresh()->landlord_iban);

        $pdf = $this->get(route('real-estate.lease', $tenant))->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);

        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger)->get(route('real-estate.lease', $tenant))->assertNotFound();
        Volt::test('real-estate.rental', ['realEstateId' => $realEstate->id])->assertNotFound();
    }

    public function test_rent_is_tracked_matched_with_bank_bookings_and_used_for_the_tax(): void
    {
        [$user, $household, $realEstate] = $this->rental();
        $tenant = RealEstateTenant::factory()->create(['real_estate_id' => $realEstate->id, 'name' => 'Max Muster', 'move_in_on' => '2026-06-01', 'cold_rent' => 800, 'utilities_advance' => 200]);
        $account = BankAccount::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Giro']);
        foreach ([['2026-07-02', 1000, 'Miete Juli'], ['2026-07-30', 1000, 'Miete August'], ['2026-08-03', 1000, 'Rechnung'], ['2026-09-01', 950, 'Miete September']] as $index => [$date, $amount, $purpose]) {
            Transaction::create(['household_id' => $household->id, 'user_id' => $user->id, 'bank_account_id' => $account->id, 'booked_on' => $date, 'amount' => $amount,
                'counterparty' => $purpose === 'Rechnung' ? 'Jemand anderes' : 'MAX MUSTER', 'purpose' => $purpose, 'category' => 'other_income', 'import_hash' => 'h'.$index]);
        }
        $this->actingAs($user);

        $component = Volt::test('real-estate.rental', ['realEstateId' => $realEstate->id])
            ->set('section', 'rent')
            ->call('matchPayments')
            ->assertSee('2 Mietzahlungen in deinen Bankbuchungen gefunden.');

        $this->assertSame(['2026-07-01', '2026-08-01'], RealEstateRentPayment::orderBy('month')->get()->map(fn ($payment) => $payment->month->toDateString())->all());
        $this->assertNotNull(RealEstateRentPayment::first()->transaction_id);

        // June is marked by hand; September (950 €, not the agreed rent) stays open.
        $component->call('togglePayment', $tenant->id, 6);
        $overview = RentalAccounting::rentOverview($realEstate, 2026);
        $this->assertSame(['paid', 'paid', 'paid', 'open', 'future', 'none'], [
            $overview['tenants'][0]['months'][6]['status'], $overview['tenants'][0]['months'][7]['status'], $overview['tenants'][0]['months'][8]['status'],
            $overview['tenants'][0]['months'][9]['status'], $overview['tenants'][0]['months'][10]['status'], $overview['tenants'][0]['months'][5]['status'],
        ]);
        $this->assertSame([7000.0, 3000.0, 1000.0], [$overview['expected'], $overview['paid'], $overview['arrears']]);

        // Undo, and the rent received is the income for Anlage V.
        $component->call('togglePayment', $tenant->id, 6);
        $this->assertSame(2, RealEstateRentPayment::count());
        $this->assertSame(2000.0, RealEstateTax::forYear($realEstate->fresh(), 2026)['rental']['rent']);
        $component->call('togglePayment', $tenant->id, 3)->assertStatus(422);
    }

    public function test_the_utility_statement_splits_costs_by_key_and_time(): void
    {
        [$user, $household, $realEstate] = $this->rental();
        $first = RealEstateTenant::factory()->create(['real_estate_id' => $realEstate->id, 'name' => 'Anna Alt', 'move_in_on' => '2020-01-01', 'area' => 60, 'persons' => 1, 'utilities_advance' => 100]);
        $second = RealEstateTenant::factory()->create(['real_estate_id' => $realEstate->id, 'name' => 'Ben Neu', 'move_in_on' => '2026-07-01', 'area' => 40, 'persons' => 3, 'utilities_advance' => 100]);
        $this->actingAs($user);

        Volt::test('real-estate.rental', ['realEstateId' => $realEstate->id])
            ->set('section', 'utilities')
            ->set('statement.costs', [
                ['type' => 'property_tax', 'label' => 'Grundsteuer', 'amount' => '1.000,00', 'key' => 'area'],
                ['type' => 'water', 'label' => 'Wasser', 'amount' => '800', 'key' => 'persons'],
            ])
            ->call('saveStatement')
            ->assertHasNoErrors()
            ->assertSee('Ben Neu')
            ->assertSee('Guthaben 281,57 €')
            ->call('markStatementSent');

        $statement = RealEstateUtilityStatement::sole();
        $this->assertSame([2026, 1800.0, '2026-12-31'], [$statement->year, $statement->totalCosts(), $statement->period_end->toDateString()]);
        $this->assertNotNull($statement->sent_on);

        $result = RentalAccounting::utilityStatement($statement);
        ['Anna Alt' => $anna, 'Ben Neu' => $ben] = collect($result['tenants'])->keyBy(fn (array $row) => $row['tenant']->name)->all();
        $this->assertSame([365, 918.43, 1200.0, -281.57], [$anna['days'], $anna['costs'], $anna['advances'], $anna['balance']]);
        // Ben: 184 of 365 days → area 40 % × 184/365, water by person days 552 / 917.
        $this->assertSame([184, 201.64, 481.57, 600.0], [$ben['days'], $ben['lines'][0]['amount'], $ben['lines'][1]['amount'], $ben['advances']]);
        $this->assertSame(198.36, $result['landlord']);

        $this->assertStringStartsWith('%PDF', $this->get(route('real-estate.utility-statement', [$statement, $second]))->assertOk()->getContent());
        $other = RealEstateTenant::factory()->create();
        $this->get(route('real-estate.utility-statement', [$statement, $other]))->assertNotFound();
    }

    public function test_applicants_apply_with_a_self_disclosure_and_become_tenants(): void
    {
        Notification::fake();
        [$user, $household, $realEstate] = $this->rental();
        $this->actingAs($user);

        Volt::test('real-estate.rental', ['realEstateId' => $realEstate->id])
            ->set('section', 'applicants')
            ->set('applicantIntro', '3 Zimmer, ab Dezember')
            ->set('applicantRent', '1.120,00')
            ->call('openApplications');
        $token = $realEstate->fresh()->applicant_token;
        $this->assertSame(32, strlen($token));
        $this->assertStringStartsWith('%PDF', $this->get(route('real-estate.self-disclosure-form', $realEstate))->assertOk()->getContent());

        auth()->logout();
        $this->get(route('rental.apply', $token))->assertOk()->assertSee('3 Zimmer, ab Dezember')->assertSee('Selbstauskunft');
        $this->get(route('rental.apply', str_repeat('x', 32)))->assertNotFound();

        $form = Volt::test('rental.apply', ['token' => $token])
            ->set('form.name', 'Clara Neu')->set('form.email', 'clara@example.com')->set('form.current_address', 'Altweg 3, 80333 München')
            ->set('form.net_income', '3.200')->set('form.persons', '2')->set('form.credit_report', true)->set('form.desired_move_in', '2026-12-01')
            ->call('submit')
            ->assertHasErrors('form.consent')
            ->set('form.consent', true)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('Vielen Dank');

        $applicant = RealEstateApplicant::sole();
        $this->assertSame(['Clara Neu', 3200.0, 2, true, 'new'], [$applicant->name, $applicant->net_income, $applicant->persons, $applicant->credit_report, $applicant->status]);
        $this->assertNotNull($applicant->consent_at);
        Notification::assertSentTo($user, NewRentalApplicant::class);

        // Bots filling the hidden field are ignored.
        Volt::test('rental.apply', ['token' => $token])->set('website', 'spam')->set('form.name', 'Bot')->set('form.email', 'bot@example.com')
            ->set('form.current_address', 'x')->set('form.consent', true)->call('submit')->assertSee('Vielen Dank');
        $this->assertSame(1, RealEstateApplicant::count());

        $this->actingAs($user);
        $component = Volt::test('real-estate.rental', ['realEstateId' => $realEstate->id])
            ->set('section', 'applicants')
            ->assertSee('Clara Neu')
            ->assertSee('Miete 35 % des Einkommens')
            ->call('toggleApplicant', $applicant->id)
            ->assertSee('Altweg 3, 80333 München')
            ->call('rateApplicant', $applicant->id, 4)
            ->call('applicantToTenant', $applicant->id)
            ->assertSet('section', 'tenants')
            ->assertSet('tenant.name', 'Clara Neu')
            ->assertSet('tenant.move_in_on', '2026-12-01')
            ->call('saveTenant')
            ->assertHasNoErrors();
        $this->assertSame(['accepted', 4], [$applicant->fresh()->status, $applicant->fresh()->rating]);
        $this->assertSame('Clara Neu', RealEstateTenant::sole()->name);
        $this->assertStringStartsWith('%PDF', $this->get(route('real-estate.applicant-pdf', $applicant))->assertOk()->getContent());

        $component->set('section', 'applicants')->call('closeApplications');
        $this->assertNull($realEstate->fresh()->applicant_token);
        $this->get(route('rental.apply', $token))->assertNotFound();

        [$stranger] = $this->memberWithHousehold();
        $this->actingAs($stranger)->get(route('real-estate.applicant-pdf', $applicant))->assertNotFound();
    }

    public function test_rejected_applicants_are_deleted_after_six_months(): void
    {
        $old = RealEstateApplicant::factory()->rejected()->create(['updated_at' => now()->subDays(181)]);
        $recent = RealEstateApplicant::factory()->rejected()->create(['updated_at' => now()->subDays(30)]);
        $waiting = RealEstateApplicant::factory()->create(['updated_at' => now()->subDays(400)]);

        $this->artisan('real-estate:purge-applicants')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertModelExists($waiting);
    }

    public function test_the_value_development_shows_gain_and_equity(): void
    {
        [$user, $household, $realEstate] = $this->rental();
        $realEstate->loans()->create(['lender' => 'Bank', 'kind' => 'annuity', 'amount' => 200000, 'interest_rate' => 3.5, 'repayment_rate' => 2, 'start_on' => '2026-09-01']);
        $this->actingAs($user);

        Volt::test('real-estate.valuations', ['realEstateId' => $realEstate->id])
            ->set('valuation.value', '330.000')
            ->set('valuation.valued_on', '2026-09-01')
            ->call('addValuation')
            ->assertHasNoErrors()
            ->assertSee('330.000 €')
            ->assertSee('+30.000 €')
            ->assertSee('3.300 €');

        $this->assertSame(330000.0, $realEstate->valuations()->sole()->value);
    }

    public function test_applicants_are_moved_between_status_columns_on_the_board(): void
    {
        [$user, , $realEstate] = $this->rental();
        $applicant = RealEstateApplicant::factory()->create(['real_estate_id' => $realEstate->id, 'name' => 'Erika Muster']);
        $foreign = RealEstateApplicant::factory()->create(['name' => 'Fremd']);
        $this->actingAs($user);

        Volt::test('real-estate.rental', ['realEstateId' => $realEstate->id])
            ->set('section', 'applicants')
            ->set('applicantView', 'board')
            ->assertSee('Erika Muster')
            ->assertSee('Karten ziehen, um den Status zu ändern')
            ->call('setApplicantStatus', $applicant->id, 'viewing')
            ->call('openApplicant', $applicant->id)
            ->assertSet('applicantView', 'list')
            ->assertSet('openApplicantId', $applicant->id)
            ->call('setApplicantStatus', $foreign->id, 'viewing')
            ->assertNotFound();

        $this->assertSame('viewing', $applicant->fresh()->status);
        $this->assertSame('new', $foreign->fresh()->status);
    }
}
