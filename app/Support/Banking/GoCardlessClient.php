<?php

namespace App\Support\Banking;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for the GoCardless Bank Account Data API (formerly Nordigen): PSD2 account
 * aggregation. The access token is minted from the secret pair and cached; nothing secret is
 * stored in the database or logged.
 *
 * @see https://developer.gocardless.com/bank-account-data/
 */
class GoCardlessClient
{
    private const TOKEN_CACHE_KEY = 'gocardless.access_token';

    public function __construct(
        private ?string $secretId = null,
        private ?string $secretKey = null,
        private ?string $baseUrl = null,
    ) {
        $this->secretId ??= config('services.gocardless.secret_id');
        $this->secretKey ??= config('services.gocardless.secret_key');
        $this->baseUrl ??= rtrim((string) config('services.gocardless.base_url'), '/');
    }

    public function configured(): bool
    {
        return filled($this->secretId) && filled($this->secretKey);
    }

    /**
     * Banks of a country (ISO code, e.g. "DE"), cached for a day.
     *
     * @return list<array{id: string, name: string, bic: ?string, logo: ?string, transaction_total_days: ?string}>
     */
    public function institutions(string $country): array
    {
        $country = strtoupper(substr($country, 0, 2));

        return Cache::remember("gocardless.institutions.{$country}", now()->addDay(), function () use ($country) {
            $response = $this->request()->get('/institutions/', ['country' => $country])->throw()->json();

            return collect(is_array($response) ? $response : [])
                ->map(fn (array $bank) => [
                    'id' => (string) ($bank['id'] ?? ''),
                    'name' => (string) ($bank['name'] ?? ''),
                    'bic' => $bank['bic'] ?? null,
                    'logo' => $bank['logo'] ?? null,
                    'transaction_total_days' => $bank['transaction_total_days'] ?? null,
                ])
                ->filter(fn (array $bank) => $bank['id'] !== '')
                ->values()
                ->all();
        });
    }

    /**
     * One bank by its id (name and logo for display), or null when unknown.
     *
     * @return array{id: string, name: string, logo: ?string}|null
     */
    public function institution(string $id): ?array
    {
        try {
            $bank = $this->request()->get('/institutions/'.$id.'/')->throw()->json();
        } catch (\Throwable) {
            return null;
        }

        return isset($bank['id']) ? ['id' => (string) $bank['id'], 'name' => (string) ($bank['name'] ?? $bank['id']), 'logo' => $bank['logo'] ?? null] : null;
    }

    /**
     * Starts a consent: returns the requisition id plus the hosted link the user is sent to.
     *
     * @return array{id: string, link: string}
     */
    public function createRequisition(string $institutionId, string $redirect, string $reference): array
    {
        $response = $this->request()->post('/requisitions/', [
            'institution_id' => $institutionId,
            'redirect' => $redirect,
            'reference' => $reference,
            'user_language' => 'DE',
        ])->throw()->json();

        return ['id' => (string) ($response['id'] ?? ''), 'link' => (string) ($response['link'] ?? '')];
    }

    /**
     * @return array{status: string, accounts: list<string>}
     */
    public function requisition(string $id): array
    {
        $response = $this->request()->get("/requisitions/{$id}/")->throw()->json();

        return [
            'status' => (string) ($response['status'] ?? ''),
            'accounts' => array_values(array_map('strval', $response['accounts'] ?? [])),
        ];
    }

    /**
     * @return array{iban: ?string, name: ?string, currency: ?string, owner: ?string}
     */
    public function accountDetails(string $accountId): array
    {
        $meta = $this->request()->get("/accounts/{$accountId}/")->throw()->json();
        $details = ($this->request()->get("/accounts/{$accountId}/details/")->throw()->json()['account'] ?? []);

        return [
            'iban' => $details['iban'] ?? $meta['iban'] ?? null,
            'name' => $details['name'] ?? $details['product'] ?? null,
            'currency' => $details['currency'] ?? null,
            'owner' => $details['ownerName'] ?? null,
        ];
    }

    /**
     * The most relevant balance (closing booked, else the first reported) in euros.
     *
     * @return array{amount: float, date: ?string}|null
     */
    public function balance(string $accountId): ?array
    {
        $balances = $this->request()->get("/accounts/{$accountId}/balances/")->throw()->json()['balances'] ?? [];

        if ($balances === []) {
            return null;
        }

        $preferred = collect($balances)->firstWhere('balanceType', 'closingBooked')
            ?? collect($balances)->firstWhere('balanceType', 'expected')
            ?? $balances[0];

        return [
            'amount' => (float) ($preferred['balanceAmount']['amount'] ?? 0),
            'date' => $preferred['referenceDate'] ?? null,
        ];
    }

    /**
     * Booked transactions as import rows {date, amount, counterparty, purpose}.
     *
     * @return list<array{date: string, amount: float, counterparty: ?string, purpose: ?string}>
     */
    public function transactions(string $accountId, ?string $dateFrom = null): array
    {
        $query = $dateFrom ? ['date_from' => $dateFrom] : [];
        $booked = $this->request()->get("/accounts/{$accountId}/transactions/", $query)->throw()->json()['transactions']['booked'] ?? [];

        return collect($booked)->map(function (array $transaction) {
            $counterparty = $transaction['creditorName'] ?? $transaction['debtorName'] ?? null;
            $purpose = $transaction['remittanceInformationUnstructured']
                ?? (isset($transaction['remittanceInformationUnstructuredArray']) ? implode(' ', (array) $transaction['remittanceInformationUnstructuredArray']) : null)
                ?? $transaction['additionalInformation']
                ?? null;

            return [
                'date' => (string) ($transaction['bookingDate'] ?? $transaction['valueDate'] ?? now()->toDateString()),
                'amount' => round((float) ($transaction['transactionAmount']['amount'] ?? 0), 2),
                'counterparty' => $counterparty !== null ? trim((string) $counterparty) : null,
                'purpose' => $purpose !== null ? trim(preg_replace('/\s+/u', ' ', (string) $purpose)) : null,
            ];
        })->values()->all();
    }

    private function request(): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('GoCardless Bank Account Data is not configured.');
        }

        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token())
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }

    private function token(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addHours(23), function () {
            $response = Http::baseUrl($this->baseUrl)->acceptJson()->asJson()->timeout(30)
                ->post('/token/new/', ['secret_id' => $this->secretId, 'secret_key' => $this->secretKey])
                ->throw()->json();

            return (string) ($response['access'] ?? '');
        });
    }
}
