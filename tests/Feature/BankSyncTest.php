<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\Household;
use App\Models\Portfolio;
use App\Models\Security;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Banking\FinTsClient;
use Fhp\CurlException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Mockery\MockInterface;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class BankSyncTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @return array{0: User, 1: Household}
     */
    private function owner(): array
    {
        [$user, $household] = $this->memberWithHousehold();
        $this->actingAs($user);

        return [$user, $household];
    }

    private function connection(User $user, Household $household, array $attributes = []): BankConnection
    {
        return BankConnection::create($attributes + [
            'user_id' => $user->id, 'household_id' => $household->id, 'bank' => 'dkb', 'bank_name' => 'DKB',
            'fints_url' => 'https://fints.dkb.de/fints', 'blz' => '12030000', 'login_name' => 'tobi',
        ]);
    }

    public function test_connections_are_saved_with_an_encrypted_pin_only_when_allowed(): void
    {
        [$user] = $this->owner();
        $portfolio = Portfolio::factory()->for($user)->create();

        Volt::test('portfolio.banking')
            ->call('newConnection')
            ->set('form.bank', 'comdirect')
            ->assertSet('form.blz', '20041155')
            ->set('form.login_name', '12345678')
            ->set('form.portfolio_id', (string) $portfolio->id)
            ->set('form.auto_sync', true)
            ->call('saveConnection')
            ->assertHasNoErrors();

        $connection = BankConnection::sole();
        $this->assertSame(['comdirect', null, false, $portfolio->id], [$connection->bank, $connection->pin, $connection->auto_sync, $connection->portfolio_id]);

        Volt::test('portfolio.banking')->call('editConnection', $connection->id)->set('form.save_pin', true)->set('form.pin', '9876')->set('form.auto_sync', true)->call('saveConnection');
        $connection->refresh();
        $this->assertSame(['9876', true], [$connection->pin, $connection->auto_sync]);
        $this->assertNotSame('9876', DB::table('bank_connections')->value('pin'));

        Volt::test('portfolio.banking')->call('newConnection')->set('form.blz', '123')->set('form.fints_url', 'http://insecure')->call('saveConnection')->assertHasErrors(['form.blz', 'form.fints_url', 'form.login_name']);
    }

    public function test_holdings_are_fetched_compared_and_adopted_into_the_portfolio(): void
    {
        [$user, $household] = $this->owner();
        $portfolio = Portfolio::factory()->for($user)->create(['name' => 'DKB-Depot']);
        $connection = $this->connection($user, $household, ['pin' => '1111', 'portfolio_id' => $portfolio->id]);
        Security::factory()->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'isin' => 'DE0007164600', 'price_updated_at' => now()]);

        $this->mock(FinTsClient::class, fn (MockInterface $mock) => $mock->shouldReceive('start')->once()->withArgs(fn ($c, $pin, $operation) => $pin === '1111' && $operation === 'depot')->andReturn([
            'status' => 'done', 'accounts' => [], 'errors' => [],
            'holdings' => [['isin' => 'DE0007164600', 'wkn' => '716460', 'name' => 'SAP SE', 'quantity' => 12.0, 'acquisition_price' => 98.5, 'price' => 120.0, 'value' => 1440.0, 'currency' => 'EUR', 'price_date' => '2026-09-25']],
        ]));

        $page = Volt::test('portfolio.banking')->call('sync', $connection->id, 'depot')->assertHasNoErrors()->assertSee('SAP SE');
        $this->assertSame(['success', 1], [$connection->fresh()->sync_status, $connection->depotPositions()->count()]);

        $page->call('applyToPortfolio', $connection->id);
        $transaction = $portfolio->transactions()->sole();
        $this->assertSame(['transfer_in', 'SAP.DE', 12.0, 98.5], [$transaction->type, $transaction->symbol, $transaction->quantity, $transaction->price]);
        $this->assertSame(12.0, $portfolio->positions()->sole()->quantity);
    }

    public function test_bookings_need_the_pin_and_a_tan_and_land_in_finances(): void
    {
        [$user, $household] = $this->owner();
        $connection = $this->connection($user, $household);
        $bookings = [
            ['date' => '2026-09-20', 'amount' => -45.9, 'counterparty' => 'REWE Markt', 'purpose' => 'Einkauf'],
            ['date' => '2026-09-21', 'amount' => 2500.0, 'counterparty' => 'Arbeitgeber GmbH', 'purpose' => 'Gehalt September'],
        ];

        $this->mock(FinTsClient::class, function (MockInterface $mock) use ($bookings) {
            $mock->shouldReceive('start')->twice()->withArgs(fn ($c, $pin) => $pin === '4711')->andReturn(
                ['status' => 'tan_required', 'decoupled' => false, 'challenge' => 'TAN aus der App eingeben', 'medium' => null, 'state' => ['fints' => 'x', 'action' => 'y', 'operation' => 'statement']],
                ['status' => 'done', 'holdings' => [], 'errors' => [], 'accounts' => ['DE02120300000000202051' => $bookings]],
            );
            $mock->shouldReceive('submitTan')->once()->withArgs(fn ($c, $pin, $state, $tan) => $pin === '4711' && $tan === '123456' && $state['operation'] === 'statement')
                ->andReturn(['status' => 'done', 'holdings' => [], 'errors' => [], 'accounts' => ['DE02120300000000202051' => $bookings]]);
        });

        $page = Volt::test('portfolio.banking')->call('sync', $connection->id, 'statement')->assertSet('pinFor', $connection->id);
        $page->set('pin', '4711')->call('submitPin')->assertSet('tanFor', $connection->id)->assertSee('TAN aus der App eingeben');
        $this->assertTrue($connection->fresh()->hasPendingTan());
        $this->assertSame('4711', $connection->fresh()->pending_state['pin']);

        $page->set('tan', '123456')->call('submitTan')->assertSet('tanFor', null)->assertHasNoErrors();

        $account = BankAccount::where('user_id', $user->id)->sole();
        $this->assertSame(['DE02120300000000202051', 'DKB 2051'], [$account->iban, $account->name]);
        $this->assertSame(2, Transaction::where('bank_account_id', $account->id)->count());
        $this->assertNull($connection->fresh()->pending_state);

        // A second sync does not duplicate bookings.
        Volt::test('portfolio.banking')->call('sync', $connection->id, 'statement')->set('pin', '4711')->call('submitPin');
        $this->assertSame(2, Transaction::where('bank_account_id', $account->id)->count());
    }

    public function test_app_confirmation_is_polled_and_the_daily_sync_reports_needed_tans(): void
    {
        [$user, $household] = $this->owner();
        $connection = $this->connection($user, $household, ['pin' => '1111', 'auto_sync' => true]);
        $tan = ['status' => 'tan_required', 'decoupled' => true, 'challenge' => 'Bitte in der DKB-App bestätigen', 'medium' => null, 'state' => ['fints' => 'x', 'action' => 'y', 'operation' => 'statement']];

        $this->mock(FinTsClient::class, function (MockInterface $mock) use ($tan) {
            $mock->shouldReceive('start')->andReturn($tan);
            $mock->shouldReceive('checkDecoupled')->twice()->andReturn(null, ['status' => 'done', 'holdings' => [], 'errors' => [], 'accounts' => []]);
        });

        $page = Volt::test('portfolio.banking')->call('sync', $connection->id, 'statement')->assertSet('decoupled', true);
        $page->call('checkDecoupled')->assertSet('tanFor', $connection->id);
        $page->call('checkDecoupled')->assertSet('tanFor', null);
        $this->assertSame('success', $connection->fresh()->sync_status);

        $this->artisan('banking:sync')->expectsOutputToContain('completed: 0')->assertSuccessful();
        $this->assertSame('tan_required', $connection->fresh()->sync_status);
    }

    public function test_connections_are_private(): void
    {
        [$user, $household] = $this->owner();
        $foreign = $this->connection($this->addMemberTo($household), $household, ['bank_name' => 'Fremdbank']);

        Volt::test('portfolio.banking')->assertDontSee('Fremdbank')->call('editConnection', $foreign->id)->assertNotFound();
        Volt::test('portfolio.banking')->call('sync', $foreign->id, 'depot')->assertNotFound();
        $this->assertNotContains('pin', array_keys($foreign->toArray()));
    }

    public function test_an_http_error_of_the_bank_is_explained(): void
    {
        [$user, $household] = $this->owner();
        $connection = $this->connection($user, $household, ['pin' => '1111']);

        $this->mock(FinTsClient::class, fn (MockInterface $mock) => $mock->shouldReceive('start')->once()
            ->andThrow(new CurlException('Bad response with status code 403', 'Access Denied', 403)));

        Volt::test('portfolio.banking')->call('sync', $connection->id, 'depot');

        $message = $connection->fresh()->sync_message;
        $this->assertSame('error', $connection->fresh()->sync_status);
        $this->assertStringContainsString('Der Bankserver fints.dkb.de hat die Anfrage abgelehnt (HTTP 403).', $message);
        $this->assertStringContainsString('Antwort der Bank: „Access Denied“', $message);
    }

    public function test_a_web_banking_address_instead_of_fints_is_recognised(): void
    {
        [$user, $household] = $this->owner();
        $connection = $this->connection($user, $household, ['pin' => '1111', 'fints_url' => 'https://banking.dkb.de/']);

        $this->mock(FinTsClient::class, fn (MockInterface $mock) => $mock->shouldReceive('start')->once()
            ->andThrow(new CurlException('Bad response with status code 403', 'Invalid csrf token', 403)));

        Volt::test('portfolio.banking')->call('sync', $connection->id, 'depot');

        $message = $connection->fresh()->sync_message;
        $this->assertStringContainsString('Die Adresse https://banking.dkb.de/ führt zur Webseite der Bank, nicht zu ihrer FinTS-Schnittstelle.', $message);
        $this->assertStringContainsString('Die bekannte FinTS-Adresse dieser Bank ist https://fints.dkb.de/fints.', $message);
    }
}
