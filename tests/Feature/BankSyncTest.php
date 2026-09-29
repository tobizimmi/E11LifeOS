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
use Fhp\FinTs;
use Fhp\Model\SEPAAccount;
use Fhp\Options\Credentials;
use Fhp\Options\FinTsOptions;
use Fhp\Protocol\UPD;
use Fhp\Segment\Common\Kik;
use Fhp\Segment\Common\KtvV3;
use Fhp\Segment\HIUPD\ErlaubteGeschaeftsvorfaelleV2;
use Fhp\Segment\HIUPD\HIUPDv6;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    /**
     * A FinTS client whose account data (UPD) lists the given accounts.
     *
     * @param  list<array{0: string, 1: ?int, 2: list<string>, 3?: ?string, 4?: ?string}>  $accounts  number, account type, transactions, IBAN, product
     */
    private function fintsWithAccounts(array $accounts): FinTs
    {
        $options = new FinTsOptions;
        $options->url = 'https://fints.dkb.de/fints';
        $options->bankCode = '12030000';
        $options->productName = 'TEST';
        $options->productVersion = '1.0';
        $fints = FinTs::new($options, Credentials::create('tobi', '1111'));

        $upd = new UPD;
        $upd->hiupd = array_map(function (array $account): HIUPDv6 {
            $hiupd = new HIUPDv6;
            $hiupd->kontoverbindung = KtvV3::create($account[0], null, Kik::create('12030000'));
            $hiupd->kontoart = $account[1];
            $hiupd->iban = $account[3] ?? null;
            $hiupd->kontoproduktbezeichnung = $account[4] ?? null;
            $hiupd->name1 = 'Tobi Zimmi';
            $hiupd->erlaubteGeschaeftsvorfaelle = array_map(function (string $code) {
                $transaction = new ErlaubteGeschaeftsvorfaelleV2;
                $transaction->geschaeftsvorfall = $code;
                $transaction->anzahlBenoetigterSignaturen = 1;

                return $transaction;
            }, $account[2]);

            return $hiupd;
        }, $accounts);
        (fn () => $this->upd = $upd)->call($fints);

        return $fints;
    }

    public function test_securities_accounts_are_taken_from_the_account_data_of_the_bank(): void
    {
        $fints = $this->fintsWithAccounts([['1001', 1, ['HKKAZ']], ['5005', 30, ['HKWPD']], ['7007', 1, ['HKWPD']]]);

        $depots = (fn () => $this->depotAccounts($fints))->call(new FinTsClient);

        $this->assertSame(['5005', '7007'], array_map(fn ($account) => $account->getAccountNumber(), $depots));
        $this->assertSame('12030000', $depots[0]->getBlz());
    }

    public function test_the_accounts_are_taken_from_the_account_data_and_a_lost_dialog_is_recognised(): void
    {
        $fints = $this->fintsWithAccounts([['1001', 1, ['HKKAZ'], 'DE02120300000000001001'], ['5005', 30, ['HKWPD']]]);
        $client = new FinTsClient;

        $accounts = (fn () => $this->updAccounts($fints))->call($client);
        $this->assertSame([['1001', 'DE02120300000000001001'], ['5005', null]], array_map(fn ($account) => [$account->getAccountNumber(), $account->getIban()], $accounts));

        $this->assertTrue((fn () => $this->dialogLost(new \RuntimeException('Need to login (DialogInitialization) before executing other actions')))->call($client));
        $this->assertFalse((fn () => $this->dialogLost(new \RuntimeException('Something else')))->call($client));
    }

    public function test_the_accounts_of_a_login_are_listed_with_their_type(): void
    {
        $fints = $this->fintsWithAccounts([
            ['1001', 1, ['HKKAZ', 'HKCCS'], 'DE02120300000000001001', 'Girokonto'],
            ['5005', 30, ['HKWPD'], null, 'Depot'],
            ['8008', 50, [], 'DE02120300000000008008'],
        ]);
        $sepa = (new SEPAAccount)->setAccountNumber('9009')->setIban('DE02120300000000009009');

        $accounts = (fn () => $this->bankAccounts($fints, [$sepa]))->call(new FinTsClient);

        $this->assertSame(['1001', '5005', '8008', '9009'], array_column($accounts, 'number'));
        $this->assertSame(['giro', 'depot', 'credit_card', 'other'], array_column($accounts, 'type'));
        $this->assertSame([true, false, true, true], array_column($accounts, 'bookings'));
        $this->assertSame([false, true, false, false], array_column($accounts, 'holdings'));
        $this->assertSame(['Girokonto', 'Tobi Zimmi'], [$accounts[0]['product'], $accounts[0]['owner']]);
    }

    public function test_the_reported_accounts_are_shown_and_can_be_switched_off(): void
    {
        [$user, $household] = $this->owner();
        $connection = $this->connection($user, $household, ['pin' => '1111']);
        $reported = [
            ['number' => '1001', 'iban' => 'DE02120300000000001001', 'type' => 'giro', 'product' => 'Girokonto', 'owner' => 'Tobi Zimmi', 'currency' => 'EUR', 'bookings' => true, 'holdings' => false],
            ['number' => '5005', 'iban' => null, 'type' => 'depot', 'product' => null, 'owner' => 'Tobi Zimmi', 'currency' => 'EUR', 'bookings' => false, 'holdings' => true],
        ];

        $this->mock(FinTsClient::class, fn (MockInterface $mock) => $mock->shouldReceive('start')->once()->withArgs(fn ($c, $pin, $operation) => $operation === 'accounts')->andReturn([
            'status' => 'done', 'holdings' => [], 'accounts' => [], 'errors' => [], 'bank_accounts' => $reported,
        ]));

        Volt::test('portfolio.banking')
            ->assertSee('Konten abrufen')
            ->call('sync', $connection->id, 'accounts')
            ->assertHasNoErrors()
            ->assertSee('Girokonto')
            ->assertSee('DE02 •••• 1001')
            ->assertSee('Wertpapierdepot')
            ->call('toggleAccount', $connection->id, '1001', 'use_bookings');

        $connection->refresh();
        $this->assertSame('2 Konten gefunden.', $connection->sync_message);
        $this->assertSame(['1001'], $connection->skippedAccounts('statement'));
        $this->assertSame([], $connection->skippedAccounts('depot'));
        $this->assertStringNotContainsString('DE02120300000000001001', (string) DB::table('bank_connections')->value('accounts'));

        $connection->rememberAccounts($reported);
        $this->assertSame(['1001'], $connection->fresh()->skippedAccounts('statement'));
    }

    public function test_balances_reach_finances_and_each_depot_is_compared_with_its_own_portfolio(): void
    {
        [$user, $household] = $this->owner();
        $main = Portfolio::factory()->for($user)->create(['name' => 'Hauptdepot']);
        $kids = Portfolio::factory()->for($user)->create(['name' => 'Kinderdepot']);
        $connection = $this->connection($user, $household, ['pin' => '1111', 'portfolio_id' => $main->id]);
        $connection->rememberAccounts([
            ['number' => '1001', 'iban' => 'DE02120300000000001001', 'type' => 'giro', 'product' => 'Girokonto', 'owner' => null, 'currency' => 'EUR', 'bookings' => true, 'holdings' => false],
            ['number' => '5005', 'iban' => null, 'type' => 'depot', 'product' => null, 'owner' => null, 'currency' => 'EUR', 'bookings' => false, 'holdings' => true],
            ['number' => '6006', 'iban' => null, 'type' => 'depot', 'product' => 'Junior-Depot', 'owner' => null, 'currency' => 'EUR', 'bookings' => false, 'holdings' => true],
        ]);
        Security::factory()->create(['symbol' => 'SAP.DE', 'name' => 'SAP SE', 'isin' => 'DE0007164600', 'price_updated_at' => now()]);
        $holding = fn (string $account, float $quantity) => ['isin' => 'DE0007164600', 'wkn' => '716460', 'name' => 'SAP SE', 'quantity' => $quantity, 'acquisition_price' => 100.0, 'price' => 120.0, 'value' => $quantity * 120, 'currency' => 'EUR', 'price_date' => '2026-09-25', 'account_number' => $account];

        $this->mock(FinTsClient::class, fn (MockInterface $mock) => $mock->shouldReceive('start')->twice()->andReturn(
            ['status' => 'done', 'holdings' => [], 'accounts' => [], 'errors' => [], 'balances' => ['1001' => ['iban' => 'DE02120300000000001001', 'amount' => 1523.45, 'currency' => 'EUR', 'date' => '2026-09-29']]],
            ['status' => 'done', 'holdings' => [$holding('5005', 12), $holding('6006', 3)], 'accounts' => [], 'errors' => []],
        ));

        $page = Volt::test('portfolio.banking')
            ->call('sync', $connection->id, 'statement')
            ->assertSee('1.523,45 EUR');
        $account = BankAccount::sole();
        $this->assertSame(['DKB Girokonto 1001', 1523.45, '2026-09-29'], [$account->name, $account->balance, $account->balance_on->toDateString()]);

        $page->call('setAccountPortfolio', $connection->id, '6006', (string) $kids->id)
            ->call('sync', $connection->id, 'depot')
            ->assertSee('Junior-Depot')
            ->assertSee('in den verknüpften Depots übernehmen')
            ->call('applyToPortfolio', $connection->id);

        $this->assertSame(12.0, $main->positions()->sole()->quantity);
        $this->assertSame(3.0, $kids->positions()->sole()->quantity);
    }

    public function test_a_sync_pushes_salary_large_debits_a_low_balance_and_a_negative_forecast(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 10:00'));
        [$user, $household] = $this->owner();
        $connection = $this->connection($user, $household, ['pin' => '1111']);
        $iban = 'DE02120300000000001001';
        BankAccount::create(['user_id' => $user->id, 'household_id' => $household->id, 'name' => 'DKB Giro', 'iban' => $iban, 'balance' => 900, 'balance_on' => '2026-09-20']);

        $page = Volt::test('portfolio.banking')
            ->set('alerts.low_balance', '100')->set('alerts.large_debit', '400,50')->call('saveAlerts')->assertHasNoErrors()
            ->set('alerts.large_debit', 'viel')->call('saveAlerts')->assertHasErrors('alerts.large_debit')
            ->set('alerts.large_debit', '400,50')->call('saveAlerts')->assertHasNoErrors();
        $this->assertSame(['salary' => true, 'forecast' => true, 'large_debit' => 400.5, 'low_balance' => 100], $user->fresh()->bank_alerts);

        $this->mock(FinTsClient::class, fn (MockInterface $mock) => $mock->shouldReceive('start')->once()->andReturn([
            'status' => 'done', 'holdings' => [], 'errors' => [],
            'accounts' => [$iban => [
                ['date' => '2026-09-27', 'amount' => 2500.0, 'counterparty' => 'Arbeitgeber GmbH', 'purpose' => 'Gehalt September'],
                ['date' => '2026-09-28', 'amount' => -450.0, 'counterparty' => 'Möbelhaus', 'purpose' => 'Sofa'],
                ['date' => '2026-08-01', 'amount' => -900.0, 'counterparty' => 'Autohaus', 'purpose' => 'Reparatur'],
            ]],
            'balances' => ['1001' => ['iban' => $iban, 'amount' => 50.0, 'currency' => 'EUR', 'date' => '2026-09-28']],
        ]));

        $page->call('sync', $connection->id, 'statement')->assertHasNoErrors();

        $titles = $user->notifications()->get()->pluck('data.title')->sort()->values()->all();
        $this->assertSame(['⚠️ DKB Giro rutscht ins Minus', '💸 Große Abbuchung: -450,00 €', '💼 Gehalt eingegangen: +2.500,00 €', '🔻 Kontostand niedrig: 50,00 €'], $titles);
        $this->assertSame('2026-09-28', $user->fresh()->bank_alerts['forecast_alerted_on']);
    }
}
