<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\CashflowEntry;
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

        $account = BankAccount::create(['household_id' => $household->id, 'user_id' => $user->id, 'name' => 'Giro']);
        Transaction::create(['household_id' => $household->id, 'user_id' => $user->id, 'bank_account_id' => $account->id, 'booked_on' => now()->toDateString(), 'amount' => -50, 'counterparty' => 'Supermarkt', 'purpose' => 'Einkauf', 'category' => 'groceries', 'import_hash' => 'h1']);
        Transaction::create(['household_id' => $household->id, 'user_id' => $user->id, 'bank_account_id' => $account->id, 'booked_on' => now()->toDateString(), 'amount' => 1000, 'counterparty' => 'Arbeitgeber', 'purpose' => 'Gehalt', 'category' => 'other', 'import_hash' => 'h2']);
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

        $account = BankAccount::create(['household_id' => $otherHousehold->id, 'user_id' => $other->id, 'name' => 'Giro']);
        Transaction::create(['household_id' => $otherHousehold->id, 'user_id' => $other->id, 'bank_account_id' => $account->id, 'booked_on' => now()->toDateString(), 'amount' => -99, 'category' => 'groceries', 'import_hash' => 'h3']);

        $this->getJson('/api/v1/finances', $headers)->assertOk()->assertJsonCount(0, 'data.transactions');
    }

    public function test_a_booking_can_be_recategorised(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $account = BankAccount::create(['household_id' => $household->id, 'user_id' => $user->id, 'name' => 'Giro']);
        $tx = Transaction::create(['household_id' => $household->id, 'user_id' => $user->id, 'bank_account_id' => $account->id, 'booked_on' => now()->toDateString(), 'amount' => -50, 'category' => 'other', 'import_hash' => 'r1']);

        $this->putJson("/api/v1/transactions/{$tx->id}", ['category' => 'groceries'], $headers)->assertOk()
            ->assertJsonPath('data.category', 'groceries');
        $this->assertSame('groceries', $tx->fresh()->category);
        $this->assertTrue($tx->fresh()->category_locked);

        // Unknown category is rejected.
        $this->putJson("/api/v1/transactions/{$tx->id}", ['category' => 'nope'], $headers)->assertStatus(422);

        // A booking of another household cannot be touched.
        [$other, $otherHousehold] = $this->memberWithHousehold();
        $otherAccount = BankAccount::create(['household_id' => $otherHousehold->id, 'user_id' => $other->id, 'name' => 'Giro']);
        $foreign = Transaction::create(['household_id' => $otherHousehold->id, 'user_id' => $other->id, 'bank_account_id' => $otherAccount->id, 'booked_on' => now()->toDateString(), 'amount' => -9, 'category' => 'other', 'import_hash' => 'r2']);
        $this->putJson("/api/v1/transactions/{$foreign->id}", ['category' => 'groceries'], $headers)->assertNotFound();
    }

    public function test_budgets_can_be_set_updated_and_removed(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $this->putJson('/api/v1/budgets', ['category' => 'groceries', 'monthly_limit' => 250], $headers)->assertOk()
            ->assertJsonPath('data.category', 'groceries');
        $this->assertEquals(250.0, FinanceBudget::where(['user_id' => $user->id, 'household_id' => $household->id, 'category' => 'groceries'])->value('monthly_limit'));
        $this->getJson('/api/v1/budgets', $headers)->assertOk()->assertJsonCount(1, 'data');

        // Setting the same category again updates in place (unique key).
        $this->putJson('/api/v1/budgets', ['category' => 'groceries', 'monthly_limit' => 300], $headers)->assertOk();
        $this->assertDatabaseCount('finance_budgets', 1);

        // A limit of 0 removes the budget.
        $this->putJson('/api/v1/budgets', ['category' => 'groceries', 'monthly_limit' => 0], $headers)->assertOk();
        $this->assertDatabaseCount('finance_budgets', 0);
    }

    public function test_manual_cashflow_entries_crud(): void
    {
        [$user] = $this->memberWithHousehold();
        $headers = ['Authorization' => 'Bearer '.$this->tokenFor($user)];

        $id = $this->postJson('/api/v1/cashflow', ['kind' => 'income', 'category' => 'earned', 'label' => 'Gehalt', 'amount' => 3000], $headers)
            ->assertCreated()->assertJsonPath('data.label', 'Gehalt')->json('data.id');

        // A category that does not belong to the kind is rejected.
        $this->postJson('/api/v1/cashflow', ['kind' => 'income', 'category' => 'housing', 'label' => 'X', 'amount' => 1], $headers)->assertStatus(422);

        $this->putJson("/api/v1/cashflow/{$id}", ['amount' => 3200], $headers)->assertOk()->assertJsonPath('data.amount', 3200);
        $this->assertEquals(3200.0, CashflowEntry::find($id)->amount);
        $this->getJson('/api/v1/cashflow', $headers)->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/cashflow/{$id}", [], $headers)->assertOk();
        $this->assertDatabaseCount('cashflow_entries', 0);

        // Another member's entry cannot be edited or deleted.
        [$other] = $this->memberWithHousehold();
        $foreign = CashflowEntry::create(['user_id' => $other->id, 'kind' => 'income', 'category' => 'earned', 'label' => 'Fremd', 'amount' => 1, 'position' => 0]);
        $this->putJson("/api/v1/cashflow/{$foreign->id}", ['amount' => 2], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/cashflow/{$foreign->id}", [], $headers)->assertNotFound();
    }
}
