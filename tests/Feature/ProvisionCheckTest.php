<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\RealEstate;
use App\Models\Transaction;
use App\Support\Finance\InsuranceCheck;
use App\Support\Finance\RetirementPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class ProvisionCheckTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
    }

    public function test_insurances_are_recognised_and_gaps_follow_the_situation(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $collection = Collection::factory()->create(['household_id' => $household->id, 'created_by' => $user->id, 'name' => 'Versicherungen']);
        $cost = $collection->properties()->create(['name' => 'Monatliche Kosten (€)', 'type' => CollectionProperty::TYPE_NUMBER, 'position' => 1]);

        foreach (['Privathaftpflicht' => 5, 'Hausratversicherung' => 8, 'Handyversicherung' => 9, 'Zweite Haftpflicht' => 4] as $title => $monthly) {
            Item::factory()->create(['household_id' => $household->id, 'collection_id' => $collection->id, 'created_by' => $user->id, 'type' => Item::TYPE_ENTRY, 'title' => $title, 'properties' => [$cost->id => $monthly]]);
        }

        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'booked_on' => '2026-09-01', 'amount' => 3000, 'category' => 'salary']);
        $house = RealEstate::factory()->for($household)->create(['created_by' => $user->id, 'name' => 'Eigenheim']);
        $house->loans()->create(['lender' => 'Bank', 'kind' => 'annuity', 'amount' => 200000, 'interest_rate' => 3, 'repayment_rate' => 2]);

        $check = InsuranceCheck::for($user);
        $statuses = collect($check['rows'])->mapWithKeys(fn (array $row) => [$row['key'] => [$row['level'], $row['status']]])->all();

        $this->assertSame(['essential', 'duplicate'], $statuses['liability']);
        $this->assertSame(['essential', 'missing'], $statuses['disability']);
        $this->assertSame(['essential', 'missing'], $statuses['term_life']);
        $this->assertSame(['essential', 'missing'], $statuses['building']);
        $this->assertSame(['recommended', 'ok'], $statuses['contents']);
        $this->assertArrayNotHasKey('car', $statuses);
        $this->assertSame(3, $check['gaps']);
        $this->assertSame(['Handyversicherung'], array_column($check['unnecessary'], 'title'));

        $this->actingAs($user);
        Volt::test('provision.index')
            ->assertSee('Berufsunfähigkeitsversicherung')
            ->assertSee('Oft unnötig')
            ->call('answer', 'building', InsuranceCheck::HAVE)
            ->call('answer', 'term_life', InsuranceCheck::NOT_NEEDED);

        $this->assertSame(1, InsuranceCheck::for($user->fresh())['gaps']);
        Volt::test('provision.index')->call('answer', 'unknown', 'have')->assertNotFound();
    }

    public function test_the_pension_gap_and_financial_freedom_are_projected(): void
    {
        $inputs = ['birth_year' => 1991, 'retirement_age' => 67, 'desired_income' => 3000.0, 'statutory_pension' => 1500.0, 'other_pension' => 0.0, 'assets' => 50000.0, 'monthly_savings' => 500.0, 'return' => 5.0, 'inflation' => 2.0, 'withdrawal' => 4.0];
        $result = RetirementPlan::calculate($inputs);

        $this->assertSame(35, $result['age']);
        $this->assertSame(32, $result['years']);
        $this->assertSame(1500.0, $result['gap']);
        $this->assertSame(450000.0, $result['capital_needed']);
        $this->assertSame(900000.0, $result['freedom_capital']);
        $this->assertEqualsWithDelta(2.94, $result['real_return'], 0.01);
        $this->assertEqualsWithDelta(438222.9, $result['capital_projected'], 1);
        $this->assertSame(97, $result['coverage']);
        $this->assertGreaterThan(500, $result['savings_needed']);
        $this->assertLessThan(530, $result['savings_needed']);
        $this->assertNotNull($result['freedom_age']);

        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('provision.index', ['tab' => 'retirement'])
            ->set('plan.birth_year', '1991')->set('plan.statutory_pension', '1500')->set('plan.desired_income', '3000')->set('plan.assets', '0')->set('plan.monthly_savings', '100')
            ->assertHasNoErrors()
            ->assertSee('Rentenlücke pro Monat')
            ->assertSee('1.500 €')
            ->assertSee('müsstest du etwa')
            ->set('plan.return', '99')
            ->assertHasErrors('plan.return');

        $this->assertSame('1991', (string) $user->fresh()->retirement_plan['birth_year']);
    }
}
