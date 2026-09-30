<?php

namespace App\Support\Banking;

use App\Models\BankConnection;
use Fhp\Action\GetBalance;
use Fhp\Action\GetDepotAufstellung;
use Fhp\Action\GetSEPAAccounts;
use Fhp\Action\GetStatementOfAccount;
use Fhp\BaseAction;
use Fhp\CurlException;
use Fhp\FinTs;
use Fhp\Model\NoPsd2TanMode;
use Fhp\Model\SEPAAccount;
use Fhp\Model\TanMode;
use Fhp\Options\Credentials;
use Fhp\Options\FinTsOptions;
use Fhp\Protocol\ServerException;
use RuntimeException;
use Throwable;

/**
 * Talks FinTS with the bank (nemiah/php-fints): login with strong customer authentication
 * (TAN or confirmation in the banking app), securities account holdings and account bookings.
 *
 * Operations: "depot" (holdings), "statement" (bookings), "accounts" (only the account list) and "test".
 * Results: ['status' => 'done', 'holdings' => [...], 'accounts' => [iban => bookings], 'bank_accounts' => [...],
 * 'balances' => [account number => balance]] or
 * ['status' => 'tan_required', 'challenge' => …, 'decoupled' => bool, 'state' => [...]].
 */
class FinTsClient
{
    /**
     * @return array<string, mixed>
     */
    public function start(BankConnection $connection, string $pin, string $operation): array
    {
        $fints = $this->client($connection, $pin);
        $this->selectTanMode($fints, $connection);

        try {
            $login = $fints->login();
        } catch (ServerException $exception) {
            throw new RuntimeException($this->explain($exception));
        }

        if ($login->needsTan()) {
            return $this->tanRequired($fints, $login, $operation);
        }

        return $this->run($fints, $operation, $connection->skippedAccounts($operation));
    }

    /**
     * Continues a dialog with the TAN the user typed in.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function submitTan(BankConnection $connection, string $pin, array $state, string $tan): array
    {
        $fints = $this->client($connection, $pin, $state['fints']);
        $action = unserialize(base64_decode($state['action']), ['allowed_classes' => true]);

        try {
            $fints->submitTan($action, $tan);
        } catch (ServerException $exception) {
            throw new RuntimeException(__('The bank rejected the TAN: :message', ['message' => implode('; ', $exception->getErrors())]));
        }

        return $this->resume($fints, $connection, $state, $action);
    }

    /**
     * Checks whether the user confirmed the login in the banking app; null while waiting.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    public function checkDecoupled(BankConnection $connection, string $pin, array $state): ?array
    {
        $fints = $this->client($connection, $pin, $state['fints']);
        $action = unserialize(base64_decode($state['action']), ['allowed_classes' => true]);

        try {
            if (! $fints->checkDecoupledSubmission($action)) {
                return null;
            }
        } catch (ServerException $exception) {
            throw new RuntimeException($this->explain($exception));
        }

        return $this->resume($fints, $connection, $state, $action);
    }

    /**
     * After the TAN: a login continues with the operation, a request that needed its own TAN
     * (holdings, bookings) is evaluated and the remaining accounts follow.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function resume(FinTs $fints, BankConnection $connection, array $state, BaseAction $action): array
    {
        $carry = $state['carry'] ?? [];

        if (isset($carry['account'])) {
            $result = $carry['result'];
            $account = (new SEPAAccount)->setAccountNumber($carry['account']['number'])->setIban($carry['account']['iban']);
            $state['operation'] === 'depot' ? $this->absorbHoldings($action, $account, $result) : $this->absorbBookings($action, $account, $result);
            $carry['result'] = $result;
            unset($carry['account']);
        }

        return $this->run($fints, $state['operation'], $connection->skippedAccounts($state['operation']), $carry);
    }

    private function client(BankConnection $connection, string $pin, ?string $persisted = null): FinTs
    {
        $options = new FinTsOptions;
        $options->url = $connection->fints_url;
        $options->bankCode = $connection->blz;
        $options->productName = (string) config('services.fints.product_id');
        $options->productVersion = '1.0';
        $options->timeoutConnect = 20;
        $options->timeoutResponse = 45;

        return FinTs::new($options, Credentials::create($connection->login_name, $pin), $persisted);
    }

    private function selectTanMode(FinTs $fints, BankConnection $connection): void
    {
        if ($connection->tan_mode === 'none') {
            $fints->selectTanMode(new NoPsd2TanMode);

            return;
        }

        try {
            $modes = $fints->getTanModes();

            if ($modes === []) {
                $fints->selectTanMode(new NoPsd2TanMode);

                return;
            }

            // Prefer confirmation in the banking app (no typing), else the first procedure.
            $id = collect($modes)->search(fn ($mode) => $mode->isDecoupled());
            $id = $id === false ? array_key_first($modes) : $id;
            $fints->selectTanMode($id, $this->tanMedium($fints, $modes[$id]));
        } catch (Throwable) {
            $fints->selectTanMode(new NoPsd2TanMode);
        }
    }

    /**
     * Procedures like pushTAN on several devices need the name of the device (TAN medium) with every
     * TAN request, otherwise the bank rejects it (9210). The first medium the bank lists is used.
     */
    private function tanMedium(FinTs $fints, TanMode $mode): ?string
    {
        if (! $mode->needsTanMedium()) {
            return null;
        }

        try {
            $media = $fints->getTanMedia($mode);
        } catch (Throwable) {
            return null;
        }

        return $media === [] ? null : reset($media)->getName();
    }

    /**
     * @param  array<string, mixed>  $carry  partial result of an operation interrupted by a TAN request
     * @return array<string, mixed>
     */
    private function tanRequired(FinTs $fints, BaseAction $action, string $operation, array $carry = []): array
    {
        $decoupled = (bool) $fints->getSelectedTanMode()?->isDecoupled();
        $request = $action->getTanRequest();

        return [
            'status' => 'tan_required',
            'decoupled' => $decoupled,
            'challenge' => $request?->getChallenge() ?: ($decoupled ? __('Please confirm the login in your banking app.') : __('Please enter the TAN.')),
            'medium' => $request?->getTanMediumName(),
            'state' => ['fints' => $fints->persist(), 'action' => base64_encode(serialize($action)), 'operation' => $operation, 'carry' => $carry],
        ];
    }

    /**
     * @param  list<string>  $skipped  account numbers the user switched off for this operation
     * @param  array{result?: array<string, mixed>, done?: list<string>}  $carry  state of an operation resumed after a TAN
     * @return array<string, mixed>
     */
    private function run(FinTs $fints, string $operation, array $skipped = [], array $carry = []): array
    {
        $result = $carry['result'] ?? ['status' => 'done', 'holdings' => [], 'accounts' => [], 'bank_accounts' => [], 'balances' => [], 'errors' => []];
        $done = $carry['done'] ?? [];

        if ($operation !== 'test') {
            // The accounts usually come with the bank's account data (UPD). The SEPA account list (HKSPA) is
            // only requested when the UPD has none – some banks reject it and end the whole dialog.
            $accounts = $this->updAccounts($fints);
            $sepaAccounts = [];

            if ($accounts === []) {
                try {
                    $getAccounts = GetSEPAAccounts::create();
                    $fints->execute($getAccounts);
                    $sepaAccounts = $getAccounts->needsTan() ? [] : $getAccounts->getAccounts();
                    $accounts = $sepaAccounts;
                } catch (Throwable $exception) {
                    $result['errors'][] = __('The account list could not be read: :message', ['message' => $this->describe($exception)]);
                }
            }

            $result['bank_accounts'] = $this->bankAccounts($fints, $sepaAccounts);

            if ($operation === 'depot') {
                // Depots from the UPD (account type or holdings request allowed); without any, all accounts are tried.
                $depots = $this->depotAccounts($fints);
                $accounts = $depots !== [] ? $depots : $accounts;

                // Some banks (e.g. DKB) only offer newer versions of the holdings request than php-fints knows.
                if (! $this->bankOffersHoldings($fints)) {
                    $result['errors'][] = __('This bank offers the depot query only in a FinTS version that is not supported yet. Account bookings and balances still work; import the holdings as a CSV file from the bank\'s online depot instead.');
                    $accounts = [];
                    $depots = null;
                }
            }

            $allAccounts = $accounts;
            $accounts = $operation === 'accounts' ? [] : array_filter($accounts, fn (SEPAAccount $account) => ! in_array((string) $account->getAccountNumber(), $skipped, true));
            $dialogLost = false;

            foreach ($accounts as $account) {
                $number = (string) $account->getAccountNumber();

                if (in_array($number, $done, true) || ($operation !== 'depot' && ! $account->getIban())) {
                    continue;
                }

                $done[] = $number;

                try {
                    $pending = $operation === 'depot' ? $this->readDepot($fints, $account, $result) : $this->readBookings($fints, $account, $result);

                    if ($pending) {
                        return $this->tanRequired($fints, $pending, $operation, [
                            'result' => $result, 'done' => $done, 'account' => ['number' => $number, 'iban' => $account->getIban()],
                        ]);
                    }
                } catch (Throwable $exception) {
                    if ($dialogLost = $this->dialogLost($exception)) {
                        $result['errors'][] = __('The bank ended the connection after a rejected request. Please try again.');

                        break;
                    }

                    // Without depots in the UPD every account is tried – refusals of current accounts are expected there.
                    if ($operation !== 'depot' || ($depots ?? []) !== []) {
                        $result['errors'][] = __('Account :number: :message', ['number' => $account->getAccountNumber(), 'message' => $this->describe($exception)]);
                    }
                }
            }

            if ($operation !== 'depot' && ! $dialogLost) {
                foreach ($operation === 'accounts' ? $allAccounts : $accounts as $account) {
                    try {
                        $this->readBalance($fints, $account, $result);
                    } catch (Throwable $exception) {
                        // Not every account (or bank) reports a balance; a lost dialog ends the loop.
                        if ($this->dialogLost($exception)) {
                            break;
                        }
                    }
                }
            }

            if ($operation === 'depot' && $result['holdings'] === [] && ($depots ?? null) === []) {
                $result['errors'][] = __('The bank did not report a securities account for this login. If your depot has its own login, add it as a separate connection.');
            }
        }

        try {
            $fints->close();
        } catch (Throwable) {
            // The dialog ends anyway.
        }

        return $result;
    }

    /**
     * All accounts of the login: the bank's account data (UPD) with account type and allowed
     * transactions, plus SEPA accounts the UPD does not list.
     *
     * @param  list<SEPAAccount>  $sepaAccounts
     * @return list<array{number: string, iban: ?string, type: string, product: ?string, owner: ?string, currency: ?string, bookings: bool, holdings: bool}>
     */
    private function bankAccounts(FinTs $fints, array $sepaAccounts): array
    {
        // php-fints keeps the UPD private; it is read, not changed.
        $upd = (fn () => $this->upd ?? null)->call($fints);
        $list = [];

        foreach (is_array($upd->hiupd ?? null) ? $upd->hiupd : [] as $hiupd) {
            $number = $hiupd->kontoverbindung?->kontonummer ?? null;

            if (! $number) {
                continue;
            }

            $transactions = collect($hiupd->getErlaubteGeschaeftsvorfaelle())->map(fn ($transaction) => $transaction->getGeschaeftsvorfall());
            $type = self::accountType($hiupd->kontoart ?? null, $transactions->contains('HKWPD'));
            $iban = $hiupd->iban ?? null;
            $list[(string) $number] = [
                'number' => (string) $number,
                'iban' => $iban,
                'type' => $type,
                'product' => $hiupd->kontoproduktbezeichnung ?? null,
                'owner' => trim(($hiupd->name1 ?? '').' '.($hiupd->name2 ?? '')) ?: null,
                'currency' => $hiupd->kontowaehrung ?? null,
                'bookings' => (bool) $iban && ($transactions->isEmpty() ? $type !== 'depot' : $transactions->intersect(['HKKAZ', 'HKCAZ'])->isNotEmpty()),
                'holdings' => $type === 'depot' || $transactions->contains('HKWPD'),
            ];
        }

        foreach ($sepaAccounts as $account) {
            $number = (string) $account->getAccountNumber();
            $list[$number] ??= [
                'number' => $number, 'iban' => $account->getIban(), 'type' => 'other', 'product' => null, 'owner' => null, 'currency' => null,
                'bookings' => (bool) $account->getIban(), 'holdings' => false,
            ];
        }

        return array_values($list);
    }

    /**
     * Account type from the FinTS "Kontoart" (1–9 current account, 10–29 savings/fixed deposit,
     * 30–39 and 60–69 depot, 40–49 loan, 50–59 credit card, 70–79 building savings, 80–89 insurance).
     */
    public static function accountType(?int $kontoart, bool $allowsHoldings = false): string
    {
        return match (true) {
            $kontoart === null => $allowsHoldings ? 'depot' : 'other',
            $kontoart >= 1 && $kontoart <= 9 => 'giro',
            $kontoart >= 10 && $kontoart <= 29 => 'savings',
            ($kontoart >= 30 && $kontoart <= 39) || ($kontoart >= 60 && $kontoart <= 69) => 'depot',
            $kontoart >= 40 && $kontoart <= 49 => 'loan',
            $kontoart >= 50 && $kontoart <= 59 => 'credit_card',
            $kontoart >= 70 && $kontoart <= 79 => 'building_savings',
            $kontoart >= 80 && $kontoart <= 89 => 'insurance',
            default => 'other',
        };
    }

    /**
     * All accounts of the bank's account data (UPD) as SEPA accounts (without BIC, which the UPD lacks).
     *
     * @return list<SEPAAccount>
     */
    private function updAccounts(FinTs $fints): array
    {
        // php-fints keeps the UPD private; it is read, not changed.
        $upd = (fn () => $this->upd ?? null)->call($fints);
        $accounts = [];

        foreach (is_array($upd->hiupd ?? null) ? $upd->hiupd : [] as $hiupd) {
            $connection = $hiupd->kontoverbindung ?? null;

            if (! $connection?->kontonummer) {
                continue;
            }

            $account = new SEPAAccount;
            $account->setAccountNumber($connection->kontonummer);
            $account->setSubAccount($connection->unterkontomerkmal);
            $account->setBlz($connection->kik?->kreditinstitutscode);
            $account->setIban($hiupd->iban ?? null);
            $accounts[] = $account;
        }

        return $accounts;
    }

    /**
     * php-fints forgets the dialog when the bank aborts it (9800); every further request then fails.
     */
    private function dialogLost(Throwable $exception): bool
    {
        return ($exception instanceof ServerException && str_contains(implode(' ', $exception->getErrors()), '9800'))
            || str_contains($exception->getMessage(), 'Need to login');
    }

    /**
     * Readable text of a failed request.
     */
    private function describe(Throwable $exception): string
    {
        return $exception instanceof ServerException ? implode('; ', $exception->getErrors()) : $exception->getMessage();
    }

    /**
     * Securities accounts from the bank's account data (UPD): account type 30–39 or accounts that
     * allow the holdings request (HKWPD). They share the login with the current account.
     *
     * @return list<SEPAAccount>
     */
    private function depotAccounts(FinTs $fints): array
    {
        // php-fints keeps the UPD private; it is read, not changed.
        $upd = (fn () => $this->upd ?? null)->call($fints);

        if (! $upd || ! is_array($upd->hiupd ?? null)) {
            return [];
        }

        $depots = [];

        foreach ($upd->hiupd as $hiupd) {
            $allowsHoldings = collect($hiupd->getErlaubteGeschaeftsvorfaelle())->contains(fn ($transaction) => $transaction->getGeschaeftsvorfall() === 'HKWPD');
            $type = $hiupd->kontoart ?? null;
            $isDepot = $type !== null && $type >= 30 && $type <= 39;
            $connection = $hiupd->kontoverbindung ?? null;
            $number = $connection?->kontonummer;

            if ((! $isDepot && ! $allowsHoldings) || ! $number) {
                continue;
            }

            $account = new SEPAAccount;
            $account->setAccountNumber($number);
            $account->setSubAccount($connection->unterkontomerkmal);
            $account->setBlz($connection->kik?->kreditinstitutscode);
            $account->setIban($hiupd->iban ?? null);
            $depots[] = $account;
        }

        return $depots;
    }

    /**
     * Whether the bank's parameters contain a version of the holdings request (HKWPD) php-fints can send.
     */
    private function bankOffersHoldings(FinTs $fints): bool
    {
        try {
            return $fints->getBpd()->getLatestSupportedParameters('HIWPDS') !== null;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Holdings of a depot; returns the request when the bank asks for a TAN for it.
     *
     * @param  array<string, mixed>  $result
     */
    private function readDepot(FinTs $fints, SEPAAccount $account, array &$result): ?BaseAction
    {
        $action = GetDepotAufstellung::create($account);
        $fints->execute($action);

        if ($action->needsTan()) {
            return $action;
        }

        $this->absorbHoldings($action, $account, $result);

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function absorbHoldings(BaseAction $action, SEPAAccount $account, array &$result): void
    {
        /** @var GetDepotAufstellung $action */
        foreach ($action->getStatement()->getHoldings() as $holding) {
            $result['holdings'][] = [
                'isin' => $holding->getISIN(),
                'wkn' => $holding->getWKN(),
                'name' => (string) $holding->getName(),
                'quantity' => (float) $holding->getAmount(),
                'acquisition_price' => $holding->getAcquisitionPrice(),
                'price' => $holding->getPrice(),
                'value' => $holding->getValue(),
                'currency' => $holding->getCurrency() ?: 'EUR',
                'price_date' => $holding->getDate()?->format('Y-m-d'),
                'account_number' => (string) $account->getAccountNumber() ?: null,
            ];
        }
    }

    /**
     * Booked balance of an account (HKSAL).
     *
     * @param  array<string, mixed>  $result
     */
    private function readBalance(FinTs $fints, SEPAAccount $account, array &$result): void
    {
        if (! $account->getIban()) {
            return;
        }

        $action = GetBalance::create($account);
        $fints->execute($action);

        if ($action->needsTan()) {
            return;
        }

        foreach ($action->getBalances() as $balance) {
            $booked = $balance->getGebuchterSaldo();
            $result['balances'][(string) $account->getAccountNumber()] = [
                'iban' => $account->getIban(),
                'amount' => round($booked->getAmount(), 2),
                'currency' => $booked->getCurrency(),
                'date' => $booked->getTimestamp()->format('Y-m-d'),
            ];
        }
    }

    /**
     * Bookings of the last 90 days; returns the request when the bank asks for a TAN for it.
     *
     * @param  array<string, mixed>  $result
     */
    private function readBookings(FinTs $fints, SEPAAccount $account, array &$result): ?BaseAction
    {
        if (! $account->getIban()) {
            return null;
        }

        $action = GetStatementOfAccount::create($account, new \DateTime('-90 days'), new \DateTime('today'));
        $fints->execute($action);

        if ($action->needsTan()) {
            return $action;
        }

        $this->absorbBookings($action, $account, $result);

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function absorbBookings(BaseAction $action, SEPAAccount $account, array &$result): void
    {
        /** @var GetStatementOfAccount $action */
        $bookings = [];

        foreach ($action->getStatement()->getStatements() as $day) {
            foreach ($day->getTransactions() as $transaction) {
                $amount = $transaction->getAmount() * ($transaction->getCreditDebit() === 'debit' ? -1 : 1);
                $bookings[] = [
                    'date' => ($transaction->getBookingDate() ?? $day->getDate())->format('Y-m-d'),
                    'amount' => round($amount, 2),
                    'counterparty' => trim($transaction->getName()) ?: null,
                    'purpose' => trim($transaction->getMainDescription() ?: $transaction->getBookingText()) ?: null,
                ];
            }
        }

        $result['accounts'][$account->getIban()] = $bookings;
    }

    /**
     * A readable explanation when the bank's server answers with an HTTP error or cannot be reached.
     */
    public static function describeConnectionError(CurlException $exception, string $url, ?string $knownUrl = null): string
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        $status = (int) $exception->getCode();
        $response = (string) $exception->getResponse();
        $detail = trim(preg_replace('/\s+/', ' ', strip_tags($response)));
        $detail = $detail !== '' ? ' '.__('Answer of the bank: “:detail”', ['detail' => mb_strimwidth($detail, 0, 160, '…')]) : '';
        $suggestion = $knownUrl && rtrim($knownUrl, '/') !== rtrim($url, '/') ? ' '.__('The known FinTS address of this bank is :url.', ['url' => $knownUrl]) : '';

        // A web page (HTML, CSRF protection, login form) answered instead of the FinTS interface.
        if ($status >= 300 && (stripos($response, 'csrf') !== false || stripos($response, '<html') !== false)) {
            return __('The address :url leads to the bank\'s website, not to its FinTS interface. Enter the FinTS address in the connection settings.', ['url' => $url]).$suggestion.$detail;
        }

        return match (true) {
            in_array($status, [401, 403], true) => __('The bank server :host refused the request (HTTP :status). Usually the FinTS address is out of date, the FinTS product ID is not (yet) activated for this bank, or the bank blocks requests from this server. Check the FinTS address in the connection settings.', ['host' => $host, 'status' => $status]).$suggestion.$detail,
            $status === 404 => __('The bank server :host does not know this FinTS address (HTTP 404). Check the FinTS address in the connection settings.', ['host' => $host]).$suggestion.$detail,
            $status >= 500 => __('The bank server :host has a problem right now (HTTP :status). Please try again later.', ['host' => $host, 'status' => $status]),
            $status >= 300 => __('The bank server :host answered with HTTP :status.', ['host' => $host, 'status' => $status]).$detail,
            default => __('The bank server :host cannot be reached: :message', ['host' => $host, 'message' => (string) ($exception->getCurlMessage() ?: $exception->getMessage())]).$suggestion,
        };
    }

    private function explain(ServerException $exception): string
    {
        $message = implode('; ', $exception->getErrors());

        if (str_contains($message, '9078')) {
            return __('The bank does not know this FinTS product (9078). Register the product at hbci-zka.de and set FINTS_PRODUCT_ID. Bank message: :message', ['message' => $message]);
        }

        return __('Login failed: :message', ['message' => $message]);
    }
}
