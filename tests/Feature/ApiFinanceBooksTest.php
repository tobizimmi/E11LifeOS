<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\ShoppingList;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Banking\BankSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Finance housekeeping for the app (report options, editing bookings, review, own categories, accounts)
 * and the shopping notes and purchase price of imported positions.
 */
class ApiFinanceBooksTest extends TestCase
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

    private function booking(User $user, int $householdId, string $name, float $amount, string $date, string $account = 'Giro'): Transaction
    {
        $bank = BankAccount::firstOrCreate(['user_id' => $user->id, 'household_id' => $householdId, 'name' => $account], ['balance' => 0]);

        return Transaction::create(['user_id' => $user->id, 'household_id' => $householdId, 'bank_account_id' => $bank->id, 'booked_on' => $date, 'counterparty' => $name, 'purpose' => $name, 'amount' => $amount, 'category' => 'other', 'import_hash' => md5($name.$date.$amount.$account)]);
    }

    public function test_the_report_with_a_free_period_comparison_filters_and_review(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('finance', true);
        $this->booking($user, $household->id, 'Gehalt', 3000, '2026-10-05');
        $this->booking($user, $household->id, 'Rewe', -80, '2026-10-10');
        $this->booking($user, $household->id, 'Rewe', -50, '2026-09-10');
        $this->booking($user, $household->id, 'Umbuchung', -500, '2026-10-12');
        $this->booking($user, $household->id, 'Umbuchung', 500, '2026-10-12', 'Tages');
        $headers = $this->headersFor($user);

        $response = $this->getJson('/api/v1/finance/report?period=custom&from=2026-10-01&to=2026-10-31&compare=1', $headers)->assertOk()
            ->assertJsonPath('data.report.from', '2026-10-01')->assertJsonPath('data.comparison.to', '2026-09-30')->assertJsonCount(3, 'data.deltas');
        $this->assertSame(1, count($response->json('data.transfers')));
        $this->assertNotEmpty($response->json('data.report.byAccount'));
        $this->assertCount(2, $response->json('data.accounts'));

        $this->getJson('/api/v1/finance/report?period=custom&from=2026-10-01&to=2026-10-31&category=other', $headers)->assertOk();

        $pair = $response->json('data.transfers.0');
        $this->postJson('/api/v1/finance/review/transfers/confirm', ['out' => $pair['out']['id'], 'in' => $pair['in']['id']], $headers)->assertOk();
        $this->assertSame('transfer', Transaction::find($pair['out']['id'])->category);
        $this->getJson('/api/v1/finance/report?period=custom&from=2026-10-01&to=2026-10-31', $headers)->assertJsonCount(0, 'data.transfers')->assertJsonPath('data.report.income', 3000);
        $this->getJson('/api/v1/finance/report?period=custom&from=2026-10-01&to=2026-10-31&transfers=1', $headers)->assertJsonPath('data.report.income', 3500);
    }

    public function test_editing_deleting_duplicates_and_own_categories(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('finance', true);
        $first = $this->booking($user, $household->id, 'Strom', -60, '2026-10-02');
        $second = $this->booking($user, $household->id, 'Strom', -60, '2026-10-02', 'Zweitkonto');
        $headers = $this->headersFor($user);

        $this->putJson("/api/v1/finance/bookings/{$first->id}", ['booked_on' => '2026-10-03', 'counterparty' => 'Stadtwerke', 'purpose' => '', 'amount' => -61.5], $headers)->assertOk();
        $this->assertSame([-61.5, 'Stadtwerke', null], [(float) $first->refresh()->amount, $first->counterparty, $first->purpose]);
        $this->postJson('/api/v1/finance/review/duplicates/confirm', ['keep' => $first->id, 'duplicate' => $second->id], $headers)->assertOk();
        $this->assertSame($first->id, $second->refresh()->duplicate_of_id);
        $this->postJson('/api/v1/finance/review/duplicates/confirm', ['keep' => $first->id, 'duplicate' => $first->id], $headers)->assertStatus(422);
        $this->deleteJson("/api/v1/finance/bookings/{$second->id}", [], $headers)->assertOk();
        $this->assertNull(Transaction::find($second->id));

        $key = $this->postJson('/api/v1/finance/custom-categories', ['label' => 'Haustier', 'icon' => '🐶', 'group' => 'needs'], $headers)->assertCreated()->json('data.key');
        $this->getJson('/api/v1/finance/custom-categories', $headers)->assertOk()->assertJsonPath('data.0.label', 'Haustier');
        $this->postJson('/api/v1/finance/custom-categories', ['label' => 'X', 'group' => 'luxury'], $headers)->assertStatus(422);
        $first->update(['category' => $key]);
        $this->deleteJson("/api/v1/finance/custom-categories/{$key}", [], $headers)->assertOk();
        $this->assertSame('other', $first->refresh()->category);
        $this->deleteJson('/api/v1/finance/custom-categories/transport', [], $headers)->assertNotFound();

        [$stranger] = $this->memberWithHousehold();
        $this->deleteJson("/api/v1/finance/bookings/{$first->id}", [], $this->headersFor($stranger))->assertNotFound();
    }

    public function test_the_accounts_overview_and_setting_a_balance(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('finance', true);
        $this->booking($user, $household->id, 'Miete', -500, now()->subDay()->toDateString());
        $account = BankAccount::first();
        $headers = $this->headersFor($user);

        $this->putJson("/api/v1/finance/accounts/{$account->id}/balance", ['balance' => '1.250,50'], $headers)->assertOk();
        $this->getJson('/api/v1/finance/accounts', $headers)->assertOk()->assertJsonPath('data.accounts.0.balance', 1250.5)->assertJsonPath('data.accounts_total', 1250.5);
        $this->putJson("/api/v1/finance/accounts/{$account->id}/balance", ['balance' => ''], $headers)->assertOk();
        $this->getJson('/api/v1/finance/accounts', $headers)->assertJsonPath('data.accounts_missing', 1);
    }

    public function test_pinned_shopping_notes(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $headers = $this->headersFor($user);

        $id = $this->postJson('/api/v1/shopping/notes', ['text' => 'Pfandflaschen mitnehmen'], $headers)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/shopping/notes', $headers)->assertOk()->assertJsonPath('data.0.text', 'Pfandflaschen mitnehmen')->assertJsonPath('data.0.done', false);
        $this->postJson("/api/v1/shopping/notes/{$id}/toggle", [], $headers)->assertOk()->assertJsonPath('data.done', true);
        $this->postJson('/api/v1/shopping/notes', ['text' => ''], $headers)->assertStatus(422);

        [$stranger] = $this->memberWithHousehold();
        $this->deleteJson("/api/v1/shopping/notes/{$id}", [], $this->headersFor($stranger))->assertNotFound();
        $this->deleteJson("/api/v1/shopping/notes/{$id}", [], $headers)->assertOk();
        $this->assertSame(0, ShoppingList::defaultFor($household->id)->reminders()->count());
    }

    public function test_the_portfolio_overview_sums_up_all_depots(): void
    {
        Http::fake();
        [$user] = $this->memberWithHousehold();
        $a = Portfolio::create(['user_id' => $user->id, 'name' => 'Depot A', 'currency' => 'EUR']);
        $b = Portfolio::create(['user_id' => $user->id, 'name' => 'Depot B', 'currency' => 'EUR']);
        PortfolioPosition::create(['portfolio_id' => $a->id, 'symbol' => 'AAA', 'quantity' => 10, 'avg_buy_price' => 10, 'manual' => true]);
        PortfolioPosition::create(['portfolio_id' => $b->id, 'symbol' => 'BBB', 'quantity' => 5, 'avg_buy_price' => 20, 'manual' => true]);
        $headers = $this->headersFor($user);

        $this->getJson('/api/v1/portfolio-overview?range=1m', $headers)->assertOk()
            ->assertJsonPath('data.summary.count', 2)
            ->assertJsonCount(2, 'data.depots')
            ->assertJsonPath('data.depots.0.name', 'Depot A')
            ->assertJsonCount(2, 'data.positions')
            ->assertJsonStructure(['data' => ['chart', 'allocation', 'positions' => [['symbol', 'weight', 'portfolio']]]]);

        [$stranger] = $this->memberWithHousehold();
        $this->getJson('/api/v1/portfolio-overview', $this->headersFor($stranger))->assertOk()->assertJsonPath('data.summary.count', 0);
    }

    public function test_the_purchase_price_of_an_imported_position_can_be_corrected(): void
    {
        [$user] = $this->memberWithHousehold();
        $portfolio = Portfolio::create(['user_id' => $user->id, 'name' => 'Depot', 'currency' => 'EUR']);
        $imported = PortfolioPosition::create(['portfolio_id' => $portfolio->id, 'symbol' => 'NFLX', 'quantity' => 10, 'avg_buy_price' => 5, 'manual' => false, 'cost_basis_estimated' => true]);
        $headers = $this->headersFor($user);

        $this->getJson("/api/v1/portfolios/{$portfolio->id}/positions", $headers)->assertOk()->assertJsonPath('data.0.needs_cost', true)->assertJsonPath('data.0.manual', false);
        $this->putJson("/api/v1/positions/{$imported->id}", ['avg_buy_price' => 480, 'quantity' => 999, 'fees' => 10], $headers)->assertOk()->assertJsonPath('data.avg_buy_price', 481)->assertJsonPath('data.needs_cost', false);
        $imported->refresh();
        $this->assertSame([10.0, 481.0], [(float) $imported->quantity, (float) $imported->cost_basis_override]);
        $this->getJson("/api/v1/portfolios/{$portfolio->id}/positions", $headers)->assertJsonPath('data.0.avg_buy_price', 481);
    }

    public function test_applying_bank_holdings_checks_the_mode_and_the_owner(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $connection = BankConnection::create(['user_id' => $user->id, 'household_id' => $household->id, 'bank' => 'dkb', 'bank_name' => 'DKB', 'fints_url' => 'https://fints.dkb.de/fints', 'blz' => '12030000', 'login_name' => 'tobi', 'auto_sync' => false, 'accounts' => []]);
        $headers = $this->headersFor($user);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $headers)->assertOk();

        $this->postJson("/api/v1/banking/connections/{$connection->id}/apply", ['mode' => 'wipe'], $headers)->assertStatus(422);

        [$other] = $this->memberWithHousehold();
        $otherHeaders = $this->headersFor($other);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $otherHeaders)->assertOk();
        $this->postJson("/api/v1/banking/connections/{$connection->id}/apply", ['mode' => 'add'], $otherHeaders)->assertNotFound();
    }

    public function test_a_fints_fetch_with_pin_and_tan_from_the_app(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $connection = BankConnection::create(['user_id' => $user->id, 'household_id' => $household->id, 'bank' => 'dkb', 'bank_name' => 'DKB', 'fints_url' => 'https://fints.dkb.de/fints', 'blz' => '12030000', 'login_name' => 'tobi', 'auto_sync' => false, 'accounts' => []]);
        $headers = $this->headersFor($user);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $headers)->assertOk();

        $this->postJson("/api/v1/banking/connections/{$connection->id}/fetch", ['operation' => 'depot'], $headers)->assertOk()->assertJsonPath('data.status', 'pin_required');
        $this->postJson("/api/v1/banking/connections/{$connection->id}/fetch", ['operation' => 'wipe', 'pin' => '1'], $headers)->assertStatus(422);

        $this->mock(BankSync::class, function (MockInterface $sync) {
            $sync->shouldReceive('run')->once()->with(Mockery::type(BankConnection::class), 'depot', '12345')->andReturn(['status' => 'tan_required', 'challenge' => 'Bitte in der App bestätigen', 'decoupled' => true]);
            $sync->shouldReceive('checkDecoupled')->once()->andReturn(null);
            $sync->shouldReceive('checkDecoupled')->once()->andReturn(['status' => 'done', 'message' => '3 Positionen abgerufen']);
            $sync->shouldReceive('submitTan')->once()->with(Mockery::type(BankConnection::class), '999999')->andThrow(new RuntimeException('Die TAN ist falsch.'));
        });
        $this->postJson("/api/v1/banking/connections/{$connection->id}/fetch", ['operation' => 'depot', 'pin' => '12345'], $headers)->assertOk()->assertJsonPath('data.status', 'tan_required')->assertJsonPath('data.decoupled', true);
        $this->postJson("/api/v1/banking/connections/{$connection->id}/decoupled", [], $headers)->assertOk()->assertJsonPath('data.status', 'waiting');
        $this->postJson("/api/v1/banking/connections/{$connection->id}/decoupled", [], $headers)->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.message', '3 Positionen abgerufen');
        $this->postJson("/api/v1/banking/connections/{$connection->id}/tan", ['tan' => '999999'], $headers)->assertStatus(422)->assertJsonValidationErrors('bank');
    }
}
