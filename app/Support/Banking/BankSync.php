<?php

namespace App\Support\Banking;

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankDepotPosition;
use App\Models\PortfolioPosition;
use App\Support\Finance\BankCsvImporter;
use App\Support\Finance\Categorizer;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\Positions;
use Fhp\CurlException;
use RuntimeException;
use Throwable;

/**
 * Online banking sync: runs FinTS operations (depot holdings, account bookings), keeps dialogs
 * that wait for a TAN, stores the holdings and imports bookings into the finance module.
 */
class BankSync
{
    /** Known FinTS access data of German banks and brokers. */
    public const BANKS = [
        'ing' => ['name' => 'ING', 'blz' => '50010517', 'url' => 'https://fints.ing.de/fints/', 'tan_mode' => 'none', 'hint' => 'Access ID = ING customer number, PIN = banking PIN.'],
        'dkb' => ['name' => 'DKB', 'blz' => '12030000', 'url' => 'https://fints.dkb.de/fints', 'tan_mode' => null, 'hint' => 'Access ID = login name, confirmation in the DKB app.'],
        'comdirect' => ['name' => 'comdirect', 'blz' => '20041155', 'url' => 'https://fints.comdirect.de/fints', 'tan_mode' => null, 'hint' => 'Access ID = customer number, confirmation via photoTAN/push.'],
        'consorsbank' => ['name' => 'Consorsbank', 'blz' => '76030080', 'url' => 'https://brokerage-hbci.consorsbank.de/hbci', 'tan_mode' => null, 'hint' => 'Access ID = account number.'],
        'smartbroker' => ['name' => 'Smartbroker+ (Baader Bank)', 'blz' => '70033100', 'url' => 'https://fints.baaderbank.de/', 'tan_mode' => null, 'hint' => 'FinTS must be activated once at Baader Bank.'],
        'flatex' => ['name' => 'flatex', 'blz' => '30153700', 'url' => 'https://fints.flatex.de/banking-proxy/servlet/MRBankServer', 'tan_mode' => null, 'hint' => 'Access ID = customer number.'],
        'sparkasse' => ['name' => 'Sparkasse', 'blz' => '', 'url' => '', 'tan_mode' => null, 'hint' => 'Enter your BLZ and the FinTS address of your Sparkasse (see its website).'],
        'custom' => ['name' => '', 'blz' => '', 'url' => '', 'tan_mode' => null, 'hint' => 'Enter BLZ and FinTS URL of your bank.'],
    ];

    /** A dialog waiting for a TAN is kept this long. */
    public const PENDING_MINUTES = 10;

    public function __construct(private FinTsClient $client, private MarketData $market) {}

    /**
     * Starts "depot", "statement" or "test". Returns the result or the TAN request.
     *
     * @return array<string, mixed>
     */
    public function run(BankConnection $connection, string $operation, ?string $pin = null): array
    {
        $pin = $pin ?: $connection->pin;

        if (! $pin) {
            throw new RuntimeException(__('Please enter your PIN.'));
        }

        return $this->handle($connection, $operation, $pin, fn () => $this->client->start($connection, $pin, $operation));
    }

    /**
     * @return array<string, mixed>
     */
    public function submitTan(BankConnection $connection, string $tan): array
    {
        $state = $this->pendingState($connection);

        return $this->handle($connection, $state['operation'], $state['pin'], fn () => $this->client->submitTan($connection, $state['pin'], $state, $tan));
    }

    /**
     * Null while the user has not confirmed in the banking app yet.
     *
     * @return array<string, mixed>|null
     */
    public function checkDecoupled(BankConnection $connection): ?array
    {
        $state = $this->pendingState($connection);
        $result = null;

        $outcome = $this->handle($connection, $state['operation'], $state['pin'], function () use ($connection, $state, &$result) {
            $result = $this->client->checkDecoupled($connection, $state['pin'], $state);

            return $result ?? ['status' => 'waiting'];
        });

        return $result === null ? null : $outcome;
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingState(BankConnection $connection): array
    {
        if (! $connection->hasPendingTan()) {
            throw new RuntimeException(__('The TAN request has expired. Please start again.'));
        }

        return $connection->pending_state;
    }

    /**
     * @param  callable(): array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function handle(BankConnection $connection, string $operation, string $pin, callable $call): array
    {
        try {
            $result = $call();
        } catch (Throwable $exception) {
            if ($exception instanceof CurlException) {
                $exception = new RuntimeException(FinTsClient::describeConnectionError($exception, (string) $connection->fints_url), previous: $exception);
            }

            $connection->update(['sync_status' => 'error', 'sync_message' => mb_substr($exception->getMessage(), 0, 1000), 'pending_state' => null, 'pending_until' => null]);

            throw $exception instanceof RuntimeException ? $exception : new RuntimeException($exception->getMessage(), previous: $exception);
        }

        if ($result['status'] === 'waiting') {
            return $result;
        }

        if ($result['status'] === 'tan_required') {
            $connection->update([
                'sync_status' => 'tan_required',
                'sync_message' => $result['challenge'],
                'pending_state' => $result['state'] + ['pin' => $pin],
                'pending_until' => now()->addMinutes(self::PENDING_MINUTES),
            ]);
            unset($result['state']);

            return $result;
        }

        $summary = $this->apply($connection, $operation, $result);
        $connection->update([
            'sync_status' => 'success',
            'sync_message' => $summary['message'],
            'last_synced_at' => now(),
            'pending_state' => null,
            'pending_until' => null,
        ]);

        return ['status' => 'done'] + $summary;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{message: string, holdings?: int, imported?: int}
     */
    public function apply(BankConnection $connection, string $operation, array $result): array
    {
        $errors = implode(' ', $result['errors'] ?? []);

        if ($operation === 'depot') {
            $connection->depotPositions()->delete();

            foreach ($result['holdings'] as $holding) {
                $connection->depotPositions()->create($holding);
            }

            return ['message' => trim(trans_choice(':count holding received.|:count holdings received.', count($result['holdings'])).' '.$errors), 'holdings' => count($result['holdings'])];
        }

        if ($operation === 'statement') {
            $importer = app(BankCsvImporter::class);
            $categorizer = new Categorizer($connection->user_id);
            $imported = 0;

            foreach ($result['accounts'] as $iban => $bookings) {
                $account = BankAccount::firstOrCreate(
                    ['user_id' => $connection->user_id, 'iban' => $iban],
                    ['household_id' => $connection->household_id, 'name' => $connection->bank_name.' '.substr((string) $iban, -4)],
                );
                $imported += $importer->importBookings($account, $bookings, $categorizer)['imported'];
            }

            return ['message' => trim(trans_choice(':count new booking imported into Finances.|:count new bookings imported into Finances.', $imported).' '.$errors), 'imported' => $imported];
        }

        return ['message' => __('Connection works.')];
    }

    /**
     * Bank holdings next to the linked portfolio: ISIN → symbol, quantity difference.
     *
     * @return list<array{position: BankDepotPosition, symbol: ?string, portfolio_quantity: float, difference: float}>
     */
    public function compare(BankConnection $connection): array
    {
        $holdings = $connection->depotPositions()->get();
        $resolved = $this->market->resolveMany($holdings->pluck('isin')->filter()->merge($holdings->pluck('wkn')->filter())->all());
        $positions = $connection->portfolio_id ? PortfolioPosition::where('portfolio_id', $connection->portfolio_id)->get() : collect();

        return $holdings->map(function ($holding) use ($resolved, $positions) {
            $symbol = ($resolved[$holding->isin] ?? $resolved[$holding->wkn] ?? null)?->symbol;
            $position = $positions->first(fn (PortfolioPosition $position) => ($holding->isin && $position->isin === $holding->isin) || ($symbol && $position->symbol === $symbol));
            $quantity = (float) ($position?->quantity ?? 0);

            return ['position' => $holding, 'symbol' => $position?->symbol ?? $symbol, 'portfolio_quantity' => $quantity, 'difference' => round($holding->quantity - $quantity, 6)];
        })->all();
    }

    /**
     * Brings the linked portfolio to the bank's quantities with transfer bookings.
     */
    public function applyToPortfolio(BankConnection $connection): int
    {
        $portfolio = $connection->portfolio ?? throw new RuntimeException(__('Please link a portfolio first.'));
        $count = 0;

        foreach ($this->compare($connection) as $row) {
            if (! $row['symbol'] || abs($row['difference']) < 0.000001) {
                continue;
            }

            $holding = $row['position'];
            $portfolio->transactions()->create([
                'symbol' => $row['symbol'],
                'name' => $holding->name,
                'isin' => $holding->isin,
                'wkn' => $holding->wkn,
                'type' => $row['difference'] > 0 ? 'transfer_in' : 'transfer_out',
                'quantity' => abs($row['difference']),
                'price' => (float) ($holding->acquisition_price ?: $holding->price ?: 0),
                'currency' => $holding->currency ?: 'EUR',
                'executed_on' => today()->toDateString(),
                'notes' => __('Reconciled with :bank', ['bank' => $connection->bank_name]),
                'import_source' => 'fints',
            ]);
            $count++;
        }

        Positions::recalculate($portfolio);

        return $count;
    }
}
