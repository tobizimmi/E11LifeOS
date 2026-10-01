<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\FinanceBudget;
use App\Models\HealthEntry;
use App\Models\HealthProfile;
use App\Models\Transaction;
use App\Support\Finance\Budgets;
use App\Support\Life\Warranties;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class HouseholdExtrasTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-20 12:00'));
    }

    public function test_a_budget_pot_carries_left_over_money_into_the_next_months(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        FinanceBudget::create(['user_id' => $user->id, 'household_id' => $household->id, 'category' => 'shopping', 'monthly_limit' => 50, 'rollover_from' => '2026-07-01']);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'category' => 'shopping', 'amount' => -20, 'booked_on' => '2026-07-10']);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'category' => 'shopping', 'amount' => -10, 'booked_on' => '2026-08-03']);
        Transaction::factory()->create(['bank_account_id' => $account->id, 'category' => 'shopping', 'amount' => -100, 'booked_on' => '2026-09-12']);

        // July and August: 2 × 50 − 30 = 70 carried; September: 50 + 70 = 120 available, 100 spent.
        $row = Budgets::status($user, $household->id)[0];
        $this->assertSame([70.0, 120.0, 20.0], [$row['carry'], $row['available'], $row['remaining']]);

        Volt::test('finance.index')->set('tab', 'budgets')->assertSee('100,00 € / 120,00 €')->assertSee('inkl. 70,00 € aus Vormonaten')
            ->call('toggleRollover', 'shopping');
        $this->assertNull(FinanceBudget::sole()->rollover_from);
        $this->assertSame(0.0, Budgets::status($user, $household->id)[0]['carry']);
    }

    public function test_appliances_are_added_with_their_warranty_and_listed_by_end_date(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('house.index', ['tab' => 'warranties'])
            ->set('device.title', 'Waschmaschine')->set('device.purchased_on', '2025-01-15')->set('device.years', '2')->set('device.price', '649')
            ->call('addDevice')->assertHasNoErrors()
            ->set('device.title', 'Toaster')->set('device.purchased_on', '2024-03-01')->set('device.until', '2026-03-01')
            ->call('addDevice')->assertHasNoErrors()
            ->set('device.title', 'Fernseher')->set('device.purchased_on', '2024-11-01')->set('device.years', '2')
            ->call('addDevice')
            ->assertSee('Waschmaschine')->assertSee('bis 15.01.2027')->assertSee('abgelaufen 01.03.2026');

        $list = Warranties::list($user, $household->id);
        $this->assertSame(['Fernseher', 'Waschmaschine', 'Toaster'], $list->map(fn (array $row) => $row['item']->title)->all());
        $this->assertSame(['soon', 'active', 'expired'], $list->pluck('status')->all());
        $this->assertSame(649.0, $list[1]['price']);
    }

    public function test_the_vaccination_record_is_a_pdf_only_for_its_owner(): void
    {
        [$user] = $this->memberWithHousehold();
        [$stranger] = $this->memberWithHousehold();
        $profile = HealthProfile::create(['user_id' => $user->id, 'name' => 'Tobi']);
        $profile->entries()->create(['kind' => HealthEntry::VACCINATION, 'title' => 'Tetanus', 'done_on' => '2020-05-01', 'next_due_on' => '2030-05-01']);

        $this->actingAs($user)->get(route('health.vaccinations', $profile))->assertRedirect(route('password.confirm'));
        $response = $this->withSession(['auth.password_confirmed_at' => time()])->get(route('health.vaccinations', $profile))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('impfpass-tobi.pdf', (string) $response->headers->get('Content-Disposition'));

        $this->actingAs($stranger)->withSession(['auth.password_confirmed_at' => time()])->get(route('health.vaccinations', $profile))->assertNotFound();
    }
}
