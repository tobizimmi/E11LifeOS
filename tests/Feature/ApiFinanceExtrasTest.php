<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\FinanceRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Bookings, cash expenses, accounts and rules for the mobile app.
 */
class ApiFinanceExtrasTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => $user->email, 'password' => 'password', 'device_name' => 'iPhone'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_bookings_are_filtered_and_cash_expenses_can_be_added(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $account = BankAccount::create(['household_id' => $household->id, 'user_id' => $user->id, 'name' => 'Giro']);
        Transaction::create(['household_id' => $household->id, 'user_id' => $user->id, 'bank_account_id' => $account->id, 'booked_on' => now()->toDateString(), 'amount' => -50, 'counterparty' => 'Supermarkt', 'purpose' => 'Einkauf', 'category' => 'groceries', 'import_hash' => 'h1']);
        Transaction::create(['household_id' => $household->id, 'user_id' => $user->id, 'bank_account_id' => $account->id, 'booked_on' => now()->toDateString(), 'amount' => 1000, 'counterparty' => 'Arbeitgeber', 'purpose' => 'Gehalt', 'category' => 'other_income', 'import_hash' => 'h2']);

        $this->getJson('/api/v1/finance/bookings', $headers)->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.income', 1000)->assertJsonPath('data.expenses', 50)->assertJsonPath('data.accounts.0.name', 'Giro');
        $this->getJson('/api/v1/finance/bookings?q=gehalt', $headers)->assertJsonCount(1, 'data.items');
        $this->getJson('/api/v1/finance/bookings?category=groceries', $headers)->assertJsonCount(1, 'data.items');

        $body = ['booked_on' => now()->toDateString(), 'counterparty' => 'Bäcker', 'amount' => 4.5, 'category' => 'groceries'];
        $this->postJson('/api/v1/finance/bookings', $body, $headers)->assertCreated()->assertJsonPath('data.item.amount', -4.5);
        $this->postJson('/api/v1/finance/bookings', ['amount' => 50, 'counterparty' => 'Markt'] + $body, $headers)->assertOk()->assertJsonPath('data.saved', false)->assertJsonPath('data.match.counterparty', 'Supermarkt');
        $this->postJson('/api/v1/finance/bookings', ['amount' => 50, 'counterparty' => 'Markt', 'mode' => 'assign'] + $body, $headers)->assertOk()->assertJsonPath('data.saved', true);
        $this->assertSame(3, Transaction::count());
    }

    public function test_accounts_rules_and_remembering_a_payee(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);
        $account = BankAccount::create(['household_id' => $household->id, 'user_id' => $user->id, 'name' => 'Giro']);
        $first = Transaction::create(['household_id' => $household->id, 'user_id' => $user->id, 'bank_account_id' => $account->id, 'booked_on' => now()->toDateString(), 'amount' => -20, 'counterparty' => 'Fitnessstudio Muster', 'purpose' => 'Beitrag', 'category' => 'groceries', 'category_locked' => true, 'import_hash' => 'a']);
        $second = Transaction::create(['household_id' => $household->id, 'user_id' => $user->id, 'bank_account_id' => $account->id, 'booked_on' => now()->subMonth()->toDateString(), 'amount' => -20, 'counterparty' => 'Fitnessstudio Muster', 'purpose' => 'Beitrag', 'category' => 'other', 'import_hash' => 'b']);

        $this->postJson("/api/v1/finance/bookings/{$first->id}/remember", [], $headers)->assertOk()->assertJsonPath('data.changed', 1);
        $this->assertSame('groceries', $second->fresh()->category);
        $rules = $this->getJson('/api/v1/finance/rules', $headers)->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->postJson('/api/v1/finance/rules/apply', [], $headers)->assertOk();
        $this->deleteJson('/api/v1/finance/rules/'.$rules[0]['id'], [], $headers)->assertOk();
        $this->assertSame(0, FinanceRule::count());

        $this->putJson("/api/v1/finance/accounts/{$account->id}", ['name' => 'Girokonto'], $headers)->assertOk()->assertJsonPath('data.0.name', 'Girokonto');
        $this->deleteJson("/api/v1/finance/accounts/{$account->id}", [], $headers)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, Transaction::count());
    }
}
