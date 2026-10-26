<?php

namespace Tests\Feature;

use App\Models\FinanceBudget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Finance overview API: this month's income/expenses, budget status and recent bookings.
 */
class ApiFinancesTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    private function tokenFor(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');
    }

    public function test_finances_overview_returns_month_totals_budgets_and_bookings(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        Transaction::create(['household_id' => $household->id, 'user_id' => $user->id, 'booked_on' => now()->toDateString(), 'amount' => -50, 'counterparty' => 'Supermarkt', 'purpose' => 'Einkauf', 'category' => 'groceries', 'import_hash' => 'h1']);
        Transaction::create(['household_id' => $household->id, 'user_id' => $user->id, 'booked_on' => now()->toDateString(), 'amount' => 1000, 'counterparty' => 'Arbeitgeber', 'purpose' => 'Gehalt', 'category' => 'other', 'import_hash' => 'h2']);
        FinanceBudget::create(['user_id' => $user->id, 'household_id' => $household->id, 'category' => 'groceries', 'monthly_limit' => 200]);

        $data = $this->getJson('/api/v1/finances', $headers)->assertOk()->json('data');

        $this->assertEquals(1000, $data['income']);
        $this->assertEquals(50, $data['expenses']);
        $this->assertEquals(950, $data['balance']);
        $this->assertCount(2, $data['transactions']);
        $this->assertEquals('groceries', $data['budgets'][0]['category']);
        $this->assertEquals(50, $data['budgets'][0]['spent']);
        $this->assertEquals(200, $data['budgets'][0]['limit']);
    }

    public function test_finances_are_scoped_to_the_household(): void
    {
        [$user] = $this->memberWithHousehold();
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        Transaction::create(['household_id' => $otherHousehold->id, 'user_id' => $other->id, 'booked_on' => now()->toDateString(), 'amount' => -99, 'category' => 'groceries', 'import_hash' => 'h3']);

        $this->getJson('/api/v1/finances', $headers)->assertOk()->assertJsonCount(0, 'data.transactions');
    }
}
