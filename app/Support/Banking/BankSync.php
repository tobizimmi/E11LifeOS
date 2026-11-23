<?php

namespace App\Support\Banking;

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankDepotPosition;
use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\Transaction;
use App\Support\Finance\BankCsvImporter;
use App\Support\Finance\Categorizer;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\Positions;
use Fhp\CurlException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /**
     * The FinTS address we know for the connection's bank (by preset, otherwise by bank code).
     */
    public static function knownUrl(BankConnection $connection): ?string
    {
        $url = self::BANKS[$connection->bank]['url'] ?? '';

        if ($url === '' && $connection->blz) {
            $url = collect(self::BANKS)->firstWhere('blz', (string) $connection->blz)['url'] ?? '';
        }

        return $url !== '' ? $url : null;
    }

    /** A dialog waiting for a TAN is kept this long. */
    public const PENDING_MINUTES = 10;

    public function __construct(private FinTsClient $client, private MarketData $market) {}

    /**
     * Starts "depot", "statement", "accounts" or "test". Returns the result or the TAN request.
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
            try {
                $result = $call();
            } catch (CurlException $exception) {
                // An outdated address (e.g. taken over from an old app) whose host no longer exists:
                // nothing reached the bank, so switch to the known FinTS address and try once more.
                $knownUrl = self::knownUrl($connection);

                if ($exception->getCode() !== CURLE_COULDNT_RESOLVE_HOST || ! $knownUrl || rtrim($knownUrl, '/') === rtrim((string) $connection->fints_url, '/')) {
                    throw $exception;
                }

                $connection->update(['fints_url' => $knownUrl]);
                $result = $call();
            }
        } catch (Throwable $exception) {
            // A detailed connection error (host, HTTP status, the bank's raw response) is useful in the
            // logs, but shown to the user it turns a user-chosen FinTS URL into a port-scan oracle –
            // the member only sees that the bank could not be reached.
            $isConnectionError = $exception instanceof CurlException;

            if ($isConnectionError) {
                report(new RuntimeException(FinTsClient::describeConnectionError($exception, (string) $connection->fints_url, self::knownUrl($connection)), previous: $exception));
                $exception = new RuntimeException(__('The bank could not be reached.'), previous: $exception);
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

        if (($result['bank_accounts'] ?? []) !== []) {
            $connection->rememberAccounts($result['bank_accounts']);
        }

        $balanceChanges = [];

        if (($result['balances'] ?? []) !== []) {
            $connection->rememberBalances($result['balances']);
            $balanceChanges = $this->storeBalances($connection, $result['balances'], create: $operation === 'statement');
        }

        if ($operation === 'accounts') {
            $this->alert($connection, [], $balanceChanges);

            return ['message' => trim(trans_choice(':count account found.|:count accounts found.', count($result['bank_accounts'] ?? [])).' '.$errors)];
        }

        if ($operation === 'depot') {
            DB::transaction(function () use ($connection, $result) {
                $connection->depotPositions()->delete();

                foreach ($result['holdings'] ?? [] as $holding) {
                    $connection->depotPositions()->create($holding);
                }
            });

            return ['message' => trim(trans_choice(':count holding received.|:count holdings received.', count($result['holdings'] ?? [])).' '.$errors), 'holdings' => count($result['holdings'] ?? [])];
        }

        if ($operation === 'statement') {
            $importer = app(BankCsvImporter::class);
            $categorizer = new Categorizer($connection->user_id);
            $imported = 0;
            $skipped = 0;
            $newTransactions = [];
            $products = collect($connection->accounts ?? [])->pluck('product', 'iban');

            $failedAccounts = 0;

            foreach ($result['accounts'] as $iban => $bookings) {
                // One account failing (a locked row, a malformed booking) must not drop the bookings
                // of the other accounts in the same statement run.
                try {
                    $account = BankAccount::firstOrCreate(
                        ['user_id' => $connection->user_id, 'iban' => $iban],
                        ['household_id' => $connection->household_id, 'name' => trim($connection->bank_name.' '.($products[$iban] ?? '')).' '.substr((string) $iban, -4)],
                    );
                    $import = $importer->importBookings($account, $bookings, $categorizer);
                    $imported += $import['imported'];
                    $skipped += $import['skipped'];
                    $newTransactions = [...$newTransactions, ...$import['transactions']];
                } catch (Throwable $exception) {
                    report($exception);
                    $failedAccounts++;
                }
            }

            $this->alert($connection, $newTransactions, $balanceChanges);

            $message = trans_choice(':count new booking imported into Finances.|:count new bookings imported into Finances.', $imported);

            if ($skipped > 0) {
                $message .= ' '.trans_choice(':count booking was already there|:count bookings were already there', $skipped);
            }

            if ($failedAccounts > 0) {
                $message .= ' '.trans_choice(':count account could not be imported and was skipped.|:count accounts could not be imported and were skipped.', $failedAccounts);
            }

            return ['message' => trim($message.' '.$errors), 'imported' => $imported];
        }

        return ['message' => __('Connection works.')];
    }

    /**
     * Balances go to the matching finance account (by IBAN), so the net worth uses the bank's figure.
     *
     * @param  array<string, array{iban: ?string, amount: float, date: string}>  $balances
     * @return list<array{account: BankAccount, before: ?float, after: float}>
     */
    private function storeBalances(BankConnection $connection, array $balances, bool $create): array
    {
        $products = collect($connection->accounts ?? [])->pluck('product', 'number');
        $changes = [];

        foreach ($balances as $number => $balance) {
            if (! $balance['iban']) {
                continue;
            }

            $account = BankAccount::where('user_id', $connection->user_id)->where('iban', $balance['iban'])->first();

            if (! $account && $create) {
                $account = BankAccount::create([
                    'user_id' => $connection->user_id, 'household_id' => $connection->household_id, 'iban' => $balance['iban'],
                    'name' => trim($connection->bank_name.' '.($products[$number] ?? '')).' '.substr($balance['iban'], -4),
                ]);
            }

            if ($account) {
                $before = $account->currentBalance();
                $account->update(['balance' => $balance['amount'], 'balance_on' => $balance['date']]);
                $changes[] = ['account' => $account, 'before' => $before, 'after' => (float) $balance['amount']];
            }
        }

        return $changes;
    }

    /**
     * Push messages for the user of the connection; a failing push never breaks the sync.
     *
     * @param  list<Transaction>  $transactions
     * @param  list<array{account: BankAccount, before: ?float, after: float}>  $balances
     */
    private function alert(BankConnection $connection, array $transactions, array $balances): void
    {
        try {
            if ($connection->user) {
                BankAlerts::afterSync($connection->user, $connection->household_id, $transactions, $balances);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Bank holdings next to the linked portfolio of their depot account: ISIN → symbol, quantity difference.
     *
     * @return list<array{position: BankDepotPosition, portfolio_id: ?int, symbol: ?string, portfolio_quantity: float, difference: float}>
     */
    public function compare(BankConnection $connection): array
    {
        $holdings = $connection->depotPositions()->reorder()->orderBy('account_number')->orderBy('name')->get();
        $resolved = $this->market->resolveMany($holdings->pluck('isin')->filter()->merge($holdings->pluck('wkn')->filter())->all());
        $portfolioIds = $holdings->map(fn (BankDepotPosition $holding) => $connection->portfolioIdFor($holding->account_number))->filter()->unique();
        $positions = PortfolioPosition::whereIn('portfolio_id', $portfolioIds)->get()->groupBy('portfolio_id');

        return $holdings->map(function ($holding) use ($connection, $resolved, $positions) {
            $portfolioId = $connection->portfolioIdFor($holding->account_number);
            $symbol = ($resolved[$holding->isin] ?? $resolved[$holding->wkn] ?? null)?->symbol;
            $position = ($positions[$portfolioId] ?? collect())->first(fn (PortfolioPosition $position) => ($holding->isin && $position->isin === $holding->isin) || ($symbol && $position->symbol === $symbol));
            $quantity = (float) ($position?->quantity ?? 0);

            return ['position' => $holding, 'portfolio_id' => $portfolioId, 'symbol' => $position?->symbol ?? $symbol, 'portfolio_quantity' => $quantity, 'difference' => round($holding->quantity - $quantity, 6)];
        })->all();
    }

    /**
     * Brings the linked portfolios to the bank's quantities with transfer bookings.
     *
     * @param  string  $mode  "add" adjusts the holdings the bank reports (default); "replace" also
     *                        books out every position the linked portfolio still holds but the bank
     *                        no longer reports, so the portfolio mirrors the depot exactly.
     */
    public function applyToPortfolio(BankConnection $connection, string $mode = 'add'): int
    {
        $rows = collect($this->compare($connection))->filter(fn (array $row) => $row['portfolio_id'] !== null);

        if ($rows->isEmpty()) {
            throw new RuntimeException(__('Please link a portfolio first.'));
        }

        $portfolios = Portfolio::where('user_id', $connection->user_id)->whereIn('id', $rows->pluck('portfolio_id')->unique())->get()->keyBy('id');
        $count = 0;

        foreach ($rows as $row) {
            $portfolio = $portfolios[$row['portfolio_id']] ?? null;

            if (! $portfolio || ! $row['symbol'] || abs($row['difference']) < 0.000001) {
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
                'price' => $this->costBasisFor($holding),
                'currency' => $holding->currency ?: 'EUR',
                'executed_on' => today()->toDateString(),
                'notes' => __('Reconciled with :bank', ['bank' => $connection->bank_name]),
                'import_source' => 'fints',
            ]);
            $count++;
        }

        if ($mode === 'replace') {
            $count += $this->bookOutMissingPositions($connection, $rows, $portfolios);
        }

        $portfolios->each(fn (Portfolio $portfolio) => Positions::recalculate($portfolio));

        return $count;
    }

    /**
     * Books out (to zero) every position a linked portfolio still holds that the bank no longer
     * reports for it – used by the "replace" reconcile so the portfolio exactly mirrors the depot.
     *
     * @param  Collection<int, array{position: BankDepotPosition, portfolio_id: int, symbol: ?string, portfolio_quantity: float, difference: float}>  $rows  bank holdings from {@see compare()}
     * @param  Collection<int, Portfolio>  $portfolios
     */
    private function bookOutMissingPositions(BankConnection $connection, Collection $rows, Collection $portfolios): int
    {
        $cleared = 0;

        foreach ($rows->groupBy('portfolio_id') as $portfolioId => $portfolioRows) {
            $portfolio = $portfolios[$portfolioId] ?? null;

            if (! $portfolio) {
                continue;
            }

            $bankIsins = $portfolioRows->pluck('position.isin')->filter()->map(fn ($isin) => mb_strtoupper((string) $isin))->unique();
            $bankSymbols = $portfolioRows->pluck('symbol')->filter()->unique();

            $leftovers = PortfolioPosition::where('portfolio_id', $portfolioId)->where('quantity', '>', 0)->get()
                ->reject(fn (PortfolioPosition $position) => ($position->isin && $bankIsins->contains(mb_strtoupper((string) $position->isin)))
                    || ($position->symbol && $bankSymbols->contains($position->symbol)));

            foreach ($leftovers as $position) {
                $portfolio->transactions()->create([
                    'symbol' => $position->symbol,
                    'name' => $position->name,
                    'isin' => $position->isin,
                    'wkn' => null,
                    'type' => 'transfer_out',
                    'quantity' => abs((float) $position->quantity),
                    'price' => (float) $position->avg_buy_price,
                    'currency' => $position->currency ?: 'EUR',
                    'executed_on' => today()->toDateString(),
                    'notes' => __('Removed on full reconcile with :bank', ['bank' => $connection->bank_name]),
                    'import_source' => 'fints',
                ]);
                $cleared++;
            }
        }

        return $cleared;
    }

    /**
     * The per-unit cost basis to book a reconciled holding in at.
     *
     * Banks report the current market price ({@see BankDepotPosition::$price}) reliably, but the
     * acquisition price (MT535 Einstandskurs, :70E:) is often missing or mis-scaled by the FinTS
     * library's parser. A mis-scaled Einstandskurs sinks the position's average buy price and blows
     * the displayed gain up out of all proportion, so the acquisition price is trusted only when it
     * sits in a plausible band around the current unit price; otherwise the current price is used,
     * which shows no gain until the member enters the real purchase price by hand.
     */
    private function costBasisFor(BankDepotPosition $holding): float
    {
        $currentUnitPrice = (float) ($holding->price ?: ($holding->quantity > 0 ? (float) $holding->value / (float) $holding->quantity : 0.0));
        $acquisition = (float) $holding->acquisition_price;

        $plausible = $acquisition > 0
            && $currentUnitPrice > 0
            && $acquisition >= $currentUnitPrice * 0.1
            && $acquisition <= $currentUnitPrice * 10;

        if ($plausible) {
            return $acquisition;
        }

        return $currentUnitPrice ?: $acquisition;
    }
}
