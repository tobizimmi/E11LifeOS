<?php

namespace App\Support\Banking;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the Enable Banking API (PSD2 account aggregation, the open successor after
 * GoCardless stopped onboarding new accounts). Authentication is a self-signed RS256 JWT whose
 * `kid` is the application id; Enable Banking is the licensed AISP, so no eIDAS certificate of our
 * own is needed. Depots are not covered by PSD2 and stay with FinTS.
 *
 * @see https://enablebanking.com/docs/api/quick-start/
 */
class EnableBankingClient
{
    public function __construct(
        private ?string $applicationId = null,
        private ?string $privateKey = null,
        private ?string $baseUrl = null,
    ) {
        $this->applicationId ??= (string) config('services.enablebanking.application_id');
        $this->privateKey ??= (string) config('services.enablebanking.private_key');
        $this->baseUrl ??= rtrim((string) config('services.enablebanking.base_url'), '/');
    }

    public function configured(): bool
    {
        return filled($this->applicationId) && filled($this->privateKey);
    }

    /**
     * Banks (ASPSPs) of a country (ISO code, e.g. "DE"), cached for a day. The id is the ASPSP name,
     * which together with the country identifies the bank for the authorization call.
     *
     * @return list<array{id: string, name: string, country: string, bic: ?string, logo: ?string}>
     */
    public function institutions(string $country): array
    {
        $country = strtoupper(substr($country, 0, 2));

        return Cache::remember("enablebanking.aspsps.{$country}", now()->addDay(), function () use ($country) {
            $response = $this->request()->get('/aspsps', ['country' => $country])->throw()->json();
            $banks = $response['aspsps'] ?? (is_array($response) ? $response : []);

            return collect(is_array($banks) ? $banks : [])
                ->map(fn (array $bank) => [
                    'id' => (string) ($bank['name'] ?? ''),
                    'name' => (string) ($bank['name'] ?? ''),
                    'country' => (string) ($bank['country'] ?? $country),
                    'bic' => $bank['bic'] ?? null,
                    'logo' => $bank['logo'] ?? null,
                ])
                ->filter(fn (array $bank) => $bank['id'] !== '')
                ->values()
                ->all();
        });
    }

    /**
     * Starts a consent (authorization). Returns the hosted URL the user is sent to, plus the
     * authorization id. `state` is echoed back on the redirect so the callback can find our record.
     *
     * @return array{url: string, authorization_id: string}
     */
    public function startAuthorization(string $aspspName, string $country, string $redirect, string $state, int $validDays = 90): array
    {
        $response = $this->request()->post('/auth', [
            'access' => ['valid_until' => now()->addDays($validDays)->toIso8601String()],
            'aspsp' => ['name' => $aspspName, 'country' => strtoupper(substr($country, 0, 2))],
            'state' => $state,
            'redirect_url' => $redirect,
            'psu_type' => 'personal',
        ])->throw()->json();

        return [
            'url' => (string) ($response['url'] ?? ''),
            'authorization_id' => (string) ($response['authorization_id'] ?? ''),
        ];
    }

    /**
     * Exchanges the `code` from the redirect for a session and returns the authorized accounts.
     *
     * @return array{session_id: string, accounts: list<array{uid: string, iban: ?string, name: ?string, currency: ?string}>}
     */
    public function createSession(string $code): array
    {
        $response = $this->request()->post('/sessions', ['code' => $code])->throw()->json();

        $accounts = collect($response['accounts'] ?? [])
            ->map(function (mixed $account): array {
                // Enable Banking returns either a plain account UID string or a detailed object.
                if (is_string($account)) {
                    return ['uid' => $account, 'iban' => null, 'name' => null, 'currency' => null];
                }

                $account = (array) $account;

                return [
                    'uid' => (string) ($account['uid'] ?? $account['account_uid'] ?? ''),
                    'iban' => $account['account_id']['iban'] ?? $account['iban'] ?? null,
                    'name' => $account['name'] ?? $account['product'] ?? null,
                    'currency' => $account['currency'] ?? null,
                ];
            })
            ->filter(fn (array $account) => $account['uid'] !== '')
            ->values()
            ->all();

        // The bank returned accounts, but in a shape we did not recognise – surface it for diagnosis.
        if ($accounts === [] && ($response['accounts'] ?? []) !== []) {
            report(new RuntimeException('Enable Banking session returned accounts in an unexpected shape: '.json_encode(array_slice((array) $response['accounts'], 0, 1))));
        }

        return ['session_id' => (string) ($response['session_id'] ?? ''), 'accounts' => $accounts];
    }

    /**
     * The most relevant balance (closing booked, else the first reported) in euros.
     *
     * @return array{amount: float, date: ?string}|null
     */
    public function balance(string $accountUid): ?array
    {
        $balances = $this->request()->get("/accounts/{$accountUid}/balances")->throw()->json()['balances'] ?? [];

        if ($balances === []) {
            return null;
        }

        $preferred = collect($balances)->firstWhere('balance_type', 'CLBD')
            ?? collect($balances)->firstWhere('balance_type', 'XPCD')
            ?? $balances[0];

        return [
            'amount' => (float) ($preferred['balance_amount']['amount'] ?? 0),
            'date' => $preferred['reference_date'] ?? null,
        ];
    }

    /**
     * Booked transactions as import rows {date, amount, counterparty, purpose}. The amount sign is
     * derived from credit_debit_indicator (Enable Banking reports a positive amount plus a CRDT/DBIT
     * flag).
     *
     * @return list<array{date: string, amount: float, counterparty: ?string, purpose: ?string}>
     */
    public function transactions(string $accountUid, ?string $dateFrom = null): array
    {
        $query = $dateFrom ? ['date_from' => $dateFrom] : [];
        $rows = [];
        $continuationKey = null;
        $firstKeys = null;
        $pages = 0;

        // Follow pagination (continuation_key) so later pages are not missed. The page cap and
        // filled() guard stop the loop even if the bank keeps returning an empty/constant key.
        do {
            $params = filled($continuationKey) ? $query + ['continuation_key' => $continuationKey] : $query;
            $response = $this->request()->get("/accounts/{$accountUid}/transactions", $params)->throw()->json();
            $firstKeys ??= array_keys($response);

            $list = $response['transactions'] ?? [];

            // Berlin Group shape: {booked: [...], pending: [...]} instead of a flat list.
            if (is_array($list) && (array_key_exists('booked', $list) || array_key_exists('pending', $list))) {
                $list = array_merge($list['booked'] ?? [], $list['pending'] ?? []);
            }

            $rows = array_merge($rows, array_values(is_array($list) ? $list : []));
            $continuationKey = $response['continuation_key'] ?? null;
            $pages++;
        } while (filled($continuationKey) && $pages < 50 && count($rows) < 5000);

        // Nothing parsed: surface what the bank actually returned so we can tell "empty" from "wrong shape".
        if ($rows === []) {
            report(new RuntimeException('Enable Banking: 0 transactions for '.$accountUid.' (from '.($dateFrom ?? 'n/a').'). Response keys: '.(implode(',', $firstKeys) ?: 'none')));
        }

        return collect($rows)->map(function (mixed $transaction): array {
            $transaction = (array) $transaction;
            $amount = (float) ($transaction['transaction_amount']['amount'] ?? 0);

            if (($transaction['credit_debit_indicator'] ?? '') === 'DBIT') {
                $amount = -abs($amount);
            }

            $counterparty = $transaction['creditor']['name'] ?? $transaction['debtor']['name'] ?? null;
            $remittance = $transaction['remittance_information'] ?? null;
            $purpose = is_array($remittance) ? implode(' ', $remittance) : $remittance;

            return [
                'date' => (string) ($transaction['booking_date'] ?? $transaction['value_date'] ?? now()->toDateString()),
                'amount' => round($amount, 2),
                'counterparty' => $counterparty !== null ? trim((string) $counterparty) : null,
                'purpose' => $purpose !== null ? trim(preg_replace('/\s+/u', ' ', (string) $purpose)) : null,
            ];
        })->values()->all();
    }

    private function request(): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('Enable Banking is not configured.');
        }

        return Http::baseUrl($this->baseUrl)
            ->withToken($this->jwt())
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    /**
     * A short-lived RS256 JWT signed with the application's private key, cached just under its hour.
     */
    private function jwt(): string
    {
        return Cache::remember('enablebanking.jwt', now()->addMinutes(55), function () {
            $header = ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $this->applicationId];
            $now = now();
            $body = [
                'iss' => 'enablebanking.com',
                'aud' => 'api.enablebanking.com',
                'iat' => $now->timestamp,
                'exp' => $now->copy()->addHour()->timestamp,
            ];

            $signingInput = $this->base64Url(json_encode($header)).'.'.$this->base64Url(json_encode($body));
            $key = openssl_pkey_get_private($this->privateKeyContents());

            if ($key === false) {
                throw new RuntimeException('The Enable Banking private key could not be read.');
            }

            $signature = '';
            openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

            return $signingInput.'.'.$this->base64Url($signature);
        });
    }

    /**
     * The private key, whether it is given inline as PEM or as a path to a .pem file.
     */
    private function privateKeyContents(): string
    {
        $key = (string) $this->privateKey;

        if (! str_contains($key, 'BEGIN') && is_file($key) && is_readable($key)) {
            return (string) file_get_contents($key);
        }

        // Env files keep multi-line PEMs with literal "\n"; turn those back into real newlines.
        return str_contains($key, '\n') ? str_replace('\n', "\n", $key) : $key;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
