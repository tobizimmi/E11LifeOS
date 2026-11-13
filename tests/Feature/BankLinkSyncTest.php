<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankLink;
use App\Models\Transaction;
use App\Support\Banking\BankLinkSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\Concerns\CreatesHouseholdMembers;
use Tests\TestCase;

class BankLinkSyncTest extends TestCase
{
    use CreatesHouseholdMembers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.gocardless.secret_id', 'test-id');
        config()->set('services.gocardless.secret_key', 'test-key');
        config()->set('services.gocardless.base_url', 'https://bankaccountdata.gocardless.com/api/v2');

        Http::preventStrayRequests();
        Http::fake(['api.pushover.net/*' => Http::response(['status' => 1])]);
    }

    private function fakeGoCardless(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/token/new/') => Http::response(['access' => 'access-token', 'refresh' => 'refresh-token']),
                str_contains($url, '/requisitions/') && $request->method() === 'POST' => Http::response(['id' => 'req-1', 'link' => 'https://ob.gocardless.com/psd2/start/req-1/SANDBOX']),
                str_contains($url, '/requisitions/req-1/') => Http::response(['status' => 'LN', 'accounts' => ['acc-1']]),
                str_contains($url, '/accounts/acc-1/details/') => Http::response(['account' => ['iban' => 'DE89370400440532013000', 'name' => 'Girokonto', 'currency' => 'EUR', 'ownerName' => 'Max Muster']]),
                str_contains($url, '/accounts/acc-1/balances/') => Http::response(['balances' => [['balanceAmount' => ['amount' => '1234.56', 'currency' => 'EUR'], 'balanceType' => 'closingBooked', 'referenceDate' => '2026-10-05']]]),
                str_contains($url, '/accounts/acc-1/transactions/') => Http::response(['transactions' => ['booked' => [
                    ['bookingDate' => '2026-10-04', 'transactionAmount' => ['amount' => '-12.34', 'currency' => 'EUR'], 'creditorName' => 'REWE', 'remittanceInformationUnstructured' => 'Einkauf'],
                    ['bookingDate' => '2026-10-03', 'transactionAmount' => ['amount' => '2000.00', 'currency' => 'EUR'], 'debtorName' => 'Arbeitgeber GmbH', 'remittanceInformationUnstructured' => 'Gehalt'],
                ]]]),
                str_contains($url, '/accounts/acc-1/') => Http::response(['iban' => 'DE89370400440532013000', 'institution_id' => 'SANDBOX']),
                str_contains($url, '/institutions/SANDBOX/') => Http::response(['id' => 'SANDBOX', 'name' => 'Sandbox Bank', 'logo' => 'https://logo.test/sandbox.png']),
                str_contains($url, '/institutions/') => Http::response([['id' => 'SANDBOX', 'name' => 'Sandbox Bank', 'bic' => 'SFRTDE20', 'logo' => null]]),
                default => Http::response([], 404),
            };
        });
    }

    public function test_connect_complete_and_sync_imports_balances_and_bookings(): void
    {
        $this->fakeGoCardless();
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $sync = app(BankLinkSync::class);

        $link = $sync->connect($user, 'SANDBOX', 'Sandbox Bank', null, 'https://app.test/callback');
        $this->assertStringContainsString('gocardless.com', $link);

        $model = BankLink::sole();
        $this->assertSame(['SANDBOX', 'req-1', BankLink::STATUS_CREATED], [$model->institution_id, $model->requisition_id, $model->status]);

        $this->assertTrue($sync->complete($model));
        $model->refresh();
        $this->assertSame([BankLink::STATUS_LINKED, ['acc-1']], [$model->status, $model->account_ids]);
        $this->assertTrue($model->consent_expires_at->isFuture());

        $result = $sync->sync($model);
        $this->assertSame(['imported' => 2, 'accounts' => 1], $result);

        $account = BankAccount::where('user_id', $user->id)->where('iban', 'DE89370400440532013000')->sole();
        $this->assertSame(1234.56, (float) $account->balance);
        $this->assertSame(2, $account->transactions()->count());

        // Dedupe: a second sync of the same bookings adds nothing.
        $this->assertSame(0, $sync->sync($model->fresh())['imported']);
        $this->assertSame(2, Transaction::where('bank_account_id', $account->id)->count());
    }

    private function configureEnableBanking(): void
    {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
        config()->set('services.enablebanking.application_id', 'app-123');
        config()->set('services.enablebanking.private_key', $pem);
        config()->set('services.enablebanking.base_url', 'https://api.enablebanking.com');
    }

    private function fakeEnableBanking(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/aspsps') => Http::response(['aspsps' => [['name' => 'Test Bank', 'country' => 'DE', 'logo' => 'https://logo.test/eb.png']]]),
                str_contains($url, '/auth') => Http::response(['url' => 'https://auth.enablebanking.com/consent/abc', 'authorization_id' => 'authz-1']),
                str_contains($url, '/sessions') => Http::response(['session_id' => 'sess-1', 'accounts' => [['uid' => 'eb-acc-1', 'account_id' => ['iban' => 'DE89370400440532013000'], 'name' => 'Girokonto']]]),
                str_contains($url, '/accounts/eb-acc-1/balances') => Http::response(['balances' => [['balance_type' => 'CLBD', 'balance_amount' => ['amount' => '987.65', 'currency' => 'EUR'], 'reference_date' => '2026-11-06']]]),
                str_contains($url, '/accounts/eb-acc-1/transactions') => Http::response(['transactions' => [
                    ['booking_date' => '2026-11-05', 'transaction_amount' => ['amount' => '20.00', 'currency' => 'EUR'], 'credit_debit_indicator' => 'DBIT', 'creditor' => ['name' => 'REWE'], 'remittance_information' => ['Einkauf']],
                    ['booking_date' => '2026-11-01', 'transaction_amount' => ['amount' => '2500.00', 'currency' => 'EUR'], 'credit_debit_indicator' => 'CRDT', 'debtor' => ['name' => 'Arbeitgeber'], 'remittance_information' => 'Gehalt'],
                ]]),
                default => Http::response([], 404),
            };
        });
    }

    public function test_enable_banking_connect_complete_and_sync_imports_bookings(): void
    {
        $this->configureEnableBanking();
        $this->fakeEnableBanking();
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        $sync = app(BankLinkSync::class);

        $url = $sync->connectEnableBanking($user, 'Test Bank', 'DE', null, 'https://app.test/callback');
        $this->assertStringContainsString('auth.enablebanking.com', $url);

        $model = BankLink::sole();
        $this->assertSame([BankLink::PROVIDER_ENABLEBANKING, 'Test Bank', 'authz-1', BankLink::STATUS_CREATED], [$model->provider, $model->institution_id, $model->requisition_id, $model->status]);

        $this->assertTrue($sync->complete($model, 'the-code'));
        $model->refresh();
        $this->assertSame([BankLink::STATUS_LINKED, ['eb-acc-1']], [$model->status, $model->account_ids]);

        $result = $sync->sync($model);
        $this->assertSame(['imported' => 2, 'accounts' => 1], $result);

        $account = BankAccount::where('user_id', $user->id)->where('iban', 'DE89370400440532013000')->sole();
        $this->assertSame(987.65, (float) $account->balance);
        $this->assertSame(2, $account->transactions()->count());
    }

    public function test_the_connect_panel_offers_enable_banking_and_no_longer_gocardless(): void
    {
        // GoCardless is set up in setUp(), but the connect panel must not offer it anymore.
        $this->configureEnableBanking();
        Http::fake(fn (Request $request) => str_contains($request->url(), '/aspsps')
            ? Http::response(['aspsps' => [['name' => 'Test Bank', 'country' => 'DE', 'logo' => null]]])
            : Http::response([], 404));

        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        Volt::test('portfolio.banking')
            ->set('showConnect', true)
            ->assertOk()
            ->assertSee('Test Bank')
            ->assertDontSee('GoCardless');
    }

    public function test_an_expired_consent_is_flagged_and_not_synced(): void
    {
        $this->fakeGoCardless();
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $link = BankLink::create([
            'user_id' => $user->id, 'household_id' => $user->currentHousehold()->id,
            'institution_id' => 'SANDBOX', 'institution_name' => 'Sandbox Bank', 'reference' => 'ref-x',
            'status' => BankLink::STATUS_LINKED, 'account_ids' => ['acc-1'], 'consent_expires_at' => now()->subDay(),
        ]);

        $result = app(BankLinkSync::class)->sync($link);
        $this->assertSame(['imported' => 0, 'accounts' => 0], $result);
        $this->assertSame(BankLink::STATUS_EXPIRED, $link->fresh()->status);
    }

    public function test_the_connect_route_needs_the_feature_configured(): void
    {
        config()->set('services.gocardless.secret_id', null);
        config()->set('services.gocardless.secret_key', null);

        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);
        // Past the sensitive-area password confirmation.
        $this->withSession(['auth.password_confirmed_at' => time()]);

        $this->get(route('portfolio.banking.link.connect', ['institution' => 'SANDBOX']))->assertNotFound();
    }

    public function test_the_callback_completes_the_connection_and_redirects_to_banking(): void
    {
        $this->fakeGoCardless();
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        $link = app(BankLinkSync::class)->connect($user, 'SANDBOX', 'Sandbox Bank', null, 'https://app.test/callback');
        $reference = BankLink::sole()->reference;

        // No password confirmation in the session: the external return must still complete
        // (the callback route is not behind password.confirm).
        $this->get(route('portfolio.banking.link.callback', ['ref' => $reference]))
            ->assertRedirect(route('portfolio.banking'));

        $this->assertSame(BankLink::STATUS_LINKED, BankLink::sole()->status);
        $this->assertSame(1, BankAccount::where('user_id', $user->id)->count());

        // The banking page shows the connected bank with a "Sync now" action.
        Volt::test('portfolio.banking')
            ->assertSee('Sandbox Bank')
            ->assertSee(__('Sync now'));
    }

    public function test_a_transient_bank_error_on_the_callback_is_shown_as_retryable(): void
    {
        $this->configureEnableBanking();
        $this->fakeEnableBanking();
        [$user] = $this->memberWithHousehold();
        $this->actingAs($user);

        app(BankLinkSync::class)->connectEnableBanking($user, 'DKB', 'DE', null, 'https://app.test/callback');
        $link = BankLink::sole();

        // The bank returns ?error=server_error (and no code) – a temporary bank-side problem.
        $this->get(route('portfolio.banking.link.callback', ['state' => $link->reference, 'error' => 'server_error']))
            ->assertRedirect(route('portfolio.banking'));

        $link->refresh();
        $this->assertSame(BankLink::STATUS_ERROR, $link->status);
        // Classified as temporary and retryable, not a hard decline (locale-independent via __()).
        $this->assertSame(
            __('The bank reported a temporary problem (:error). This is on the bank\'s side – please start the connection again in a few minutes.', ['error' => 'server_error']),
            $link->sync_message,
        );
    }
}
