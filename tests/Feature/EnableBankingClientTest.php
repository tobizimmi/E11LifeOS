<?php

namespace Tests\Feature;

use App\Support\Banking\EnableBankingClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnableBankingClientTest extends TestCase
{
    private function client(): EnableBankingClient
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $pem);

        return new EnableBankingClient('app-123', $pem, 'https://api.enablebanking.com');
    }

    public function test_it_authorises_with_a_signed_jwt_and_parses_the_api(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/aspsps') => Http::response(['aspsps' => [['name' => 'Test Bank', 'country' => 'DE', 'logo' => 'https://logo', 'bic' => 'TESTDEFF']]]),
                str_contains($url, '/auth') => Http::response(['url' => 'https://auth.example/consent', 'authorization_id' => 'auth-1']),
                str_contains($url, '/sessions') => Http::response(['session_id' => 'sess-1', 'accounts' => [['uid' => 'acc-1', 'account_id' => ['iban' => 'DE123'], 'name' => 'Giro']]]),
                str_contains($url, '/balances') => Http::response(['balances' => [['balance_type' => 'CLBD', 'balance_amount' => ['amount' => '1234.56'], 'reference_date' => '2026-11-07']]]),
                str_contains($url, '/transactions') => Http::response(['transactions' => [
                    ['booking_date' => '2026-11-06', 'transaction_amount' => ['amount' => '10.00'], 'credit_debit_indicator' => 'DBIT', 'creditor' => ['name' => 'Shop'], 'remittance_information' => ['Rechnung', '5']],
                    ['booking_date' => '2026-11-05', 'transaction_amount' => ['amount' => '50.00'], 'credit_debit_indicator' => 'CRDT', 'debtor' => ['name' => 'Arbeitgeber'], 'remittance_information' => 'Gehalt'],
                ]]),
                default => Http::response([], 404),
            };
        });

        $client = $this->client();

        $banks = $client->institutions('de');
        $this->assertSame([['id' => 'Test Bank', 'name' => 'Test Bank', 'country' => 'DE', 'bic' => 'TESTDEFF', 'logo' => 'https://logo']], $banks);

        $auth = $client->startAuthorization('Test Bank', 'DE', 'https://zimmimail.de/cb', 'state-9');
        $this->assertSame('https://auth.example/consent', $auth['url']);

        $session = $client->createSession('the-code');
        $this->assertSame('acc-1', $session['accounts'][0]['uid']);
        $this->assertSame('DE123', $session['accounts'][0]['iban']);

        $this->assertSame(['amount' => 1234.56, 'date' => '2026-11-07'], $client->balance('acc-1'));

        $transactions = $client->transactions('acc-1', '2026-11-01');
        $this->assertSame(-10.0, $transactions[0]['amount']); // DBIT → negative
        $this->assertSame('Shop', $transactions[0]['counterparty']);
        $this->assertSame('Rechnung 5', $transactions[0]['purpose']);
        $this->assertSame(50.0, $transactions[1]['amount']); // CRDT → positive

        // Every call carries a Bearer RS256 JWT whose kid is the application id.
        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization')[0] ?? '';
            if (! str_starts_with($auth, 'Bearer ')) {
                return false;
            }
            $header = json_decode(base64_decode(strtr(explode('.', substr($auth, 7))[0], '-_', '+/')), true);

            return ($header['alg'] ?? null) === 'RS256' && ($header['kid'] ?? null) === 'app-123';
        });
    }

    public function test_it_reports_not_configured_without_credentials(): void
    {
        $this->assertFalse((new EnableBankingClient('', '', 'https://api.enablebanking.com'))->configured());
        $this->assertTrue((new EnableBankingClient('app', '-----BEGIN PRIVATE KEY-----x', 'https://api.enablebanking.com'))->configured());
    }
}
