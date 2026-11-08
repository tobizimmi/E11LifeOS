<?php

namespace App\Support\Banking;

use App\Models\BankAccount;
use App\Models\BankLink;
use App\Models\User;
use App\Support\Finance\BankCsvImporter;
use App\Support\Finance\Categorizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives the PSD2 account sync: starts a consent, completes it after the user came back from the
 * bank, and pulls balances and bookings into the finance module. Works with two providers –
 * GoCardless (requisition flow) and Enable Banking (auth + session flow) – chosen per bank link.
 * Depots are not covered by PSD2 and stay with FinTS.
 */
class BankLinkSync
{
    public function __construct(private GoCardlessClient $client, private EnableBankingClient $enableBanking) {}

    /**
     * GoCardless: create a consent for the chosen bank and return the hosted link.
     */
    public function connect(User $user, string $institutionId, string $institutionName, ?string $logo, string $redirect): string
    {
        $reference = (string) Str::uuid();
        $requisition = $this->client->createRequisition($institutionId, $redirect, $reference);

        BankLink::create([
            'user_id' => $user->id,
            'household_id' => $user->currentHousehold()?->id,
            'provider' => BankLink::PROVIDER_GOCARDLESS,
            'institution_id' => $institutionId,
            'institution_name' => $institutionName,
            'logo' => $logo,
            'reference' => $reference,
            'requisition_id' => $requisition['id'],
            'status' => BankLink::STATUS_CREATED,
        ]);

        return $requisition['link'];
    }

    /**
     * Enable Banking: start an authorization for the chosen bank (by ASPSP name + country) and
     * return the hosted link. `reference` is the state echoed back on the redirect.
     */
    public function connectEnableBanking(User $user, string $aspspName, string $country, ?string $logo, string $redirect): string
    {
        $reference = (string) Str::uuid();
        $authorization = $this->enableBanking->startAuthorization($aspspName, $country, $redirect, $reference);

        BankLink::create([
            'user_id' => $user->id,
            'household_id' => $user->currentHousehold()?->id,
            'provider' => BankLink::PROVIDER_ENABLEBANKING,
            'institution_id' => $aspspName,
            'institution_name' => $aspspName,
            'logo' => $logo,
            'reference' => $reference,
            'requisition_id' => $authorization['authorization_id'],
            'status' => BankLink::STATUS_CREATED,
        ]);

        return $authorization['url'];
    }

    /**
     * After the user returns from the bank: store the granted accounts and the consent window.
     * $code is the authorization code Enable Banking appends to the redirect (unused by GoCardless).
     */
    public function complete(BankLink $link, ?string $code = null): bool
    {
        return $link->provider === BankLink::PROVIDER_ENABLEBANKING
            ? $this->completeEnableBanking($link, $code)
            : $this->completeGoCardless($link);
    }

    private function completeGoCardless(BankLink $link): bool
    {
        if (! $link->requisition_id) {
            return false;
        }

        $requisition = $this->client->requisition($link->requisition_id);

        if ($requisition['status'] !== 'LN' || $requisition['accounts'] === []) {
            $link->update(['status' => BankLink::STATUS_ERROR, 'sync_message' => __('The bank did not confirm the connection.')]);

            return false;
        }

        $link->update([
            'status' => BankLink::STATUS_LINKED,
            'account_ids' => $requisition['accounts'],
            // PSD2 consent is 90 days by default; re-consent is needed afterwards.
            'consent_expires_at' => now()->addDays(90),
        ]);

        return true;
    }

    private function completeEnableBanking(BankLink $link, ?string $code): bool
    {
        if (! $code) {
            $link->update(['status' => BankLink::STATUS_ERROR, 'sync_message' => __('No authorisation code came back from the bank – the approval was not completed.')]);

            return false;
        }

        $session = $this->enableBanking->createSession($code);

        if ($session['accounts'] === []) {
            $link->update(['status' => BankLink::STATUS_ERROR, 'sync_message' => __('The bank granted no account for access. Please reconnect and tick the account(s) to share.')]);

            return false;
        }

        $meta = [];

        foreach ($session['accounts'] as $account) {
            $meta[$account['uid']] = ['iban' => $account['iban'] ?? null, 'name' => $account['name'] ?? null];
        }

        $link->update([
            'status' => BankLink::STATUS_LINKED,
            'account_ids' => array_keys($meta),
            'accounts_meta' => $meta,
            'consent_expires_at' => now()->addDays(90),
        ]);

        return true;
    }

    /**
     * Pulls balances and new bookings of every linked account into the finance module.
     *
     * @return array{imported: int, accounts: int}
     */
    public function sync(BankLink $link): array
    {
        if ($link->isExpired()) {
            $link->update(['status' => BankLink::STATUS_EXPIRED, 'sync_status' => 'error', 'sync_message' => __('The bank connection has expired – please reconnect.')]);

            return ['imported' => 0, 'accounts' => 0];
        }

        $importer = app(BankCsvImporter::class);
        $categorizer = new Categorizer($link->user_id);
        $isEnableBanking = $link->provider === BankLink::PROVIDER_ENABLEBANKING;
        $client = $isEnableBanking ? $this->enableBanking : $this->client;
        $imported = 0;
        $accounts = 0;

        try {
            foreach ($link->account_ids ?? [] as $accountId) {
                // Account IBAN/name: Enable Banking returned it on the session; GoCardless has a
                // dedicated details call.
                if ($isEnableBanking) {
                    $details = ((array) $link->accounts_meta)[$accountId] ?? ['iban' => null, 'name' => null];
                } else {
                    $details = $this->client->accountDetails($accountId);
                }

                $iban = $details['iban'] ?: $accountId;

                $account = BankAccount::firstOrCreate(
                    ['user_id' => $link->user_id, 'iban' => $iban],
                    ['household_id' => $link->household_id, 'name' => trim($link->institution_name.' '.($details['name'] ?? '')).' '.substr((string) $iban, -4)],
                );

                $balance = $client->balance($accountId);

                if ($balance) {
                    $account->update(['balance' => $balance['amount'], 'balance_on' => $balance['date'] ?: Carbon::now()->toDateString()]);
                }

                $bookings = $client->transactions($accountId, now()->subDays(90)->toDateString());
                $imported += $importer->importBookings($account, $bookings, $categorizer)['imported'];
                $accounts++;
            }

            $link->update(['last_synced_at' => now(), 'sync_status' => 'ok', 'sync_message' => null]);
        } catch (Throwable $exception) {
            $link->update(['sync_status' => 'error', 'sync_message' => Str::limit($exception->getMessage(), 200)]);

            throw $exception;
        }

        return ['imported' => $imported, 'accounts' => $accounts];
    }
}
