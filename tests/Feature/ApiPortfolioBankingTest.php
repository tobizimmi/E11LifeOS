<?php

namespace Tests\Feature;

use App\Models\BankConnection;
use App\Models\BankLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

/**
 * Bank connections for the mobile app (behind the password confirmation).
 */
class ApiPortfolioBankingTest extends TestCase
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

    public function test_banking_stays_closed_until_the_password_is_confirmed_then_shows_and_changes_connections(): void
    {
        [$user, $household] = $this->memberWithHousehold();
        $household->setAddon('portfolio', true);
        $headers = $this->headersFor($user);
        $link = BankLink::create(['user_id' => $user->id, 'household_id' => $household->id, 'provider' => 'enablebanking', 'institution_id' => 'x', 'institution_name' => 'Testbank', 'reference' => 'r', 'status' => 'linked', 'account_ids' => ['a1'], 'auto_sync' => false]);
        $connection = BankConnection::create(['user_id' => $user->id, 'household_id' => $household->id, 'bank' => 'dkb', 'bank_name' => 'DKB', 'fints_url' => 'https://fints.dkb.de/fints', 'blz' => '12030000', 'login_name' => 'tobi', 'auto_sync' => false, 'accounts' => [['number' => '123', 'iban' => 'DE89370400440532013000', 'product' => 'Girokonto', 'type' => 'giro', 'balance' => 12.5, 'bookings' => true, 'holdings' => false]]]);

        $this->getJson('/api/v1/banking', $headers)->assertStatus(423);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $headers)->assertOk();

        $this->getJson('/api/v1/banking', $headers)->assertOk()
            ->assertJsonPath('data.links.0.name', 'Testbank')->assertJsonPath('data.connections.0.accounts.0.iban', 'DE89 •••• 3000')->assertJsonPath('data.connections.0.accounts.0.use_bookings', true);

        $this->putJson('/api/v1/banking/alerts', ['large_debit' => 900, 'salary' => false], $headers)->assertOk()->assertJsonPath('data.large_debit', 900)->assertJsonPath('data.salary', false);
        $this->postJson("/api/v1/banking/links/{$link->id}/auto-sync", [], $headers)->assertOk()->assertJsonPath('data.auto_sync', true);
        $this->postJson("/api/v1/banking/connections/{$connection->id}/account", ['number' => '123', 'field' => 'use_bookings'], $headers)->assertOk()->assertJsonPath('data.use_bookings', false);
        $this->postJson("/api/v1/banking/connections/{$connection->id}/account", ['number' => '999', 'field' => 'use_bookings'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/banking/links/{$link->id}", [], $headers)->assertOk();
        $this->assertNull(BankLink::find($link->id));

        [$other] = $this->memberWithHousehold();
        $otherHeaders = $this->headersFor($other);
        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'password'], $otherHeaders)->assertOk();
        $this->postJson("/api/v1/banking/connections/{$connection->id}/account", ['number' => '123', 'field' => 'use_bookings'], $otherHeaders)->assertNotFound();
    }
}
