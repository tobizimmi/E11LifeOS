<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Transaction;
use App\Support\Finance\BalanceForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class BalanceForecastTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    public function test_the_balance_is_projected_from_recurring_bookings_and_other_spending(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
        [$user, $household] = $this->memberWithHousehold();
        $account = BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'Girokonto', 'balance' => 500, 'balance_on' => '2026-09-28']);
        $book = fn (string $date, float $amount, string $counterparty, string $category) => Transaction::factory()->create([
            'bank_account_id' => $account->id, 'booked_on' => $date, 'amount' => $amount, 'counterparty' => $counterparty, 'purpose' => null, 'category' => $category,
        ]);

        foreach (['07', '08', '09'] as $month) {
            $book("2026-$month-01", 3000, 'Arbeitgeber GmbH', 'salary');
            $book("2026-$month-03", -1200, 'Vermieter', 'rent');
            $book("2026-$month-15", -12.99, 'Netflix', 'subscriptions');
        }

        foreach (range(1, 10) as $index) {
            $book(Carbon::parse('2026-07-01')->addDays($index * 8)->toDateString(), -89, 'REWE', 'groceries');
        }

        $forecast = BalanceForecast::for(collect([$account]), 30);

        $this->assertSame(10.0, $forecast['variable_daily']);
        $this->assertSame([['2026-10-01', 3000.0], ['2026-10-03', -1200.0], ['2026-10-15', -12.99]], array_map(fn (array $event) => [$event['date'], $event['amount']], $forecast['events']));
        $this->assertSame(1987.01, $forecast['milestones'][30]);
        $this->assertSame(['date' => '2026-09-30', 'value' => 480.0], $forecast['lowest']);
        $this->assertNull($forecast['first_negative']);

        $this->actingAs($user);
        Volt::test('finance.index')->set('tab', 'forecast')->set('forecastDays', 30)
            ->assertSee('Kontostand-Prognose')
            ->assertSee('1.987,01 €')
            ->assertSee('Arbeitgeber GmbH')
            ->assertDontSee('unter null');

        $account->update(['balance' => 5]);
        $this->assertSame('2026-09-29', BalanceForecast::for(collect([$account->fresh()]), 30)['first_negative']['date']);
        Volt::test('finance.index')->set('tab', 'forecast')->assertSee('Girokonto rutscht voraussichtlich am Di., 29. Sep unter null');
    }

    public function test_without_a_balance_the_forecast_explains_how_to_get_one(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        BankAccount::factory()->create(['user_id' => $user->id, 'household_id' => $household->id]);
        $this->actingAs($user);

        Volt::test('finance.index')->set('tab', 'forecast')->assertSee('Noch kein Kontostand');
    }
}
