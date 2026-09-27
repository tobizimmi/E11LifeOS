<?php

namespace App\Support\Banking;

use App\Models\BankConnection;
use Fhp\Action\GetDepotAufstellung;
use Fhp\Action\GetSEPAAccounts;
use Fhp\Action\GetStatementOfAccount;
use Fhp\BaseAction;
use Fhp\FinTs;
use Fhp\Model\NoPsd2TanMode;
use Fhp\Model\SEPAAccount;
use Fhp\Options\Credentials;
use Fhp\Options\FinTsOptions;
use Fhp\Protocol\ServerException;
use RuntimeException;
use Throwable;

/**
 * Talks FinTS with the bank (nemiah/php-fints): login with strong customer authentication
 * (TAN or confirmation in the banking app), securities account holdings and account bookings.
 *
 * Results: ['status' => 'done', 'holdings' => [...], 'accounts' => [iban => bookings]] or
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

        return $this->run($fints, $operation);
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

        return $this->run($fints, $state['operation']);
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

        return $this->run($fints, $state['operation']);
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
            foreach ($modes as $id => $mode) {
                if ($mode->isDecoupled()) {
                    $fints->selectTanMode($id);

                    return;
                }
            }

            $fints->selectTanMode(array_key_first($modes));
        } catch (Throwable) {
            $fints->selectTanMode(new NoPsd2TanMode);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function tanRequired(FinTs $fints, BaseAction $action, string $operation): array
    {
        $decoupled = (bool) $fints->getSelectedTanMode()?->isDecoupled();
        $request = $action->getTanRequest();

        return [
            'status' => 'tan_required',
            'decoupled' => $decoupled,
            'challenge' => $request?->getChallenge() ?: ($decoupled ? __('Please confirm the login in your banking app.') : __('Please enter the TAN.')),
            'medium' => $request?->getTanMediumName(),
            'state' => ['fints' => $fints->persist(), 'action' => base64_encode(serialize($action)), 'operation' => $operation],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function run(FinTs $fints, string $operation): array
    {
        $result = ['status' => 'done', 'holdings' => [], 'accounts' => [], 'errors' => []];

        if ($operation !== 'test') {
            try {
                $getAccounts = GetSEPAAccounts::create();
                $fints->execute($getAccounts);
                $accounts = $getAccounts->needsTan() ? [] : $getAccounts->getAccounts();
            } catch (Throwable $exception) {
                $accounts = [];
                $result['errors'][] = __('The account list could not be read: :message', ['message' => $exception->getMessage()]);
            }

            foreach ($accounts as $account) {
                try {
                    $operation === 'depot' ? $this->readDepot($fints, $account, $result) : $this->readBookings($fints, $account, $result);
                } catch (Throwable) {
                    // Current accounts have no securities and vice versa – skip what the bank refuses.
                }
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
     * @param  array<string, mixed>  $result
     */
    private function readDepot(FinTs $fints, SEPAAccount $account, array &$result): void
    {
        $action = GetDepotAufstellung::create($account);
        $fints->execute($action);

        if ($action->needsTan()) {
            return;
        }

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
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function readBookings(FinTs $fints, SEPAAccount $account, array &$result): void
    {
        if (! $account->getIban()) {
            return;
        }

        $action = GetStatementOfAccount::create($account, new \DateTime('-90 days'), new \DateTime('today'));
        $fints->execute($action);

        if ($action->needsTan()) {
            return;
        }

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

    private function explain(ServerException $exception): string
    {
        $message = implode('; ', $exception->getErrors());

        if (str_contains($message, '9078')) {
            return __('The bank does not know this FinTS product (9078). Register the product at hbci-zka.de and set FINTS_PRODUCT_ID. Bank message: :message', ['message' => $message]);
        }

        return __('Login failed: :message', ['message' => $message]);
    }
}
