<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BankConnection;
use App\Models\BankLink;
use App\Support\Banking\BankAlerts;
use App\Support\Banking\BankLinkSync;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Bank connections for the app (behind the password confirmation): the PSD2 links and FinTS
 * logins with their accounts and sync state, a manual sync of a PSD2 link, the account switches
 * and the bank alerts. Setting up a login (PIN, TAN, the bank's redirect) stays in the browser.
 */
class PortfolioBankingController extends ApiController
{
    public function index(): JsonResponse
    {
        $user = $this->user();

        return $this->ok([
            'alerts' => BankAlerts::settings($user),
            'links' => BankLink::where('user_id', $user->id)->orderBy('institution_name')->get()->map(fn (BankLink $link): array => [
                'id' => $link->id,
                'name' => (string) $link->getAttribute('institution_name'),
                'logo' => $link->getAttribute('logo'),
                'status' => $link->getAttribute('status'),
                'auto_sync' => (bool) $link->auto_sync,
                'accounts' => count($link->account_ids ?? []),
                'last_synced_at' => $link->last_synced_at?->toIso8601String(),
                'consent_expires_at' => $link->consent_expires_at?->toIso8601String(),
                'sync_status' => $link->getAttribute('sync_status'),
                'sync_message' => $link->getAttribute('sync_message'),
            ])->all(),
            'connections' => BankConnection::where('user_id', $user->id)->orderBy('bank_name')->get()->map(fn (BankConnection $connection): array => [
                'id' => $connection->id,
                'name' => (string) $connection->getAttribute('bank_name'),
                'auto_sync' => (bool) $connection->getAttribute('auto_sync'),
                'last_synced_at' => $connection->getAttribute('last_synced_at') === null ? null : (string) $connection->getAttribute('last_synced_at'),
                'sync_status' => $connection->getAttribute('sync_status'),
                'sync_message' => $connection->getAttribute('sync_message'),
                'accounts' => collect($connection->getAttribute('accounts') ?? [])->map(fn (array $account): array => [
                    'number' => (string) ($account['number'] ?? ''),
                    'name' => (string) (($account['product'] ?? '') ?: ($account['type'] ?? 'Konto')),
                    'type' => (string) ($account['type'] ?? 'other'),
                    'iban' => ! empty($account['iban']) ? substr((string) $account['iban'], 0, 4).' •••• '.substr((string) $account['iban'], -4) : null,
                    'balance' => isset($account['balance']) ? (float) $account['balance'] : null,
                    'bookings' => (bool) ($account['bookings'] ?? false),
                    'holdings' => (bool) ($account['holdings'] ?? false),
                    'use_bookings' => (bool) ($account['use_bookings'] ?? true),
                    'use_holdings' => (bool) ($account['use_holdings'] ?? true),
                ])->values()->all(),
            ])->all(),
        ]);
    }

    public function saveAlerts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'salary' => ['sometimes', 'boolean'],
            'forecast' => ['sometimes', 'boolean'],
            'large_debit' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:1000000'],
            'low_balance' => ['sometimes', 'nullable', 'numeric', 'min:-1000000', 'max:1000000'],
        ]);

        $user = $this->user();
        $user->update(['bank_alerts' => $data + ($user->bank_alerts ?? [])]);

        return $this->ok(BankAlerts::settings($user->refresh()));
    }

    public function syncLink(int $link, BankLinkSync $sync): JsonResponse
    {
        $model = BankLink::where('user_id', $this->user()->id)->findOrFail($link);

        try {
            $result = $sync->sync($model);
        } catch (Throwable $exception) {
            report($exception);
            $rateLimited = $exception instanceof RequestException && $exception->response->status() === 429;

            return response()->json(['success' => false, 'error' => $rateLimited
                ? __('The bank\'s daily access limit is reached. Please try again tomorrow (the bank limits how often it can be queried without a new login).')
                : __('The bank sync failed. Please try again later.')], 422);
        }

        return $this->ok($result);
    }

    public function toggleLinkAutoSync(int $link): JsonResponse
    {
        $model = BankLink::where('user_id', $this->user()->id)->findOrFail($link);
        $model->update(['auto_sync' => ! $model->auto_sync]);

        return $this->ok(['auto_sync' => (bool) $model->auto_sync]);
    }

    public function destroyLink(int $link): JsonResponse
    {
        BankLink::where('user_id', $this->user()->id)->findOrFail($link)->delete();

        return $this->ok();
    }

    /**
     * Switches whether bookings or holdings of a FinTS account are fetched.
     */
    public function toggleAccount(Request $request, int $connection): JsonResponse
    {
        $data = $request->validate(['number' => ['required', 'string', 'max:60'], 'field' => ['required', 'in:use_bookings,use_holdings']]);
        $model = BankConnection::where('user_id', $this->user()->id)->findOrFail($connection);

        $accounts = $model->getAttribute('accounts') ?? [];
        $index = collect($accounts)->search(fn (array $account): bool => (string) ($account['number'] ?? '') === $data['number']);
        abort_if($index === false, 404);

        $accounts[$index][$data['field']] = ! ($accounts[$index][$data['field']] ?? true);
        $model->update(['accounts' => $accounts]);

        return $this->ok([$data['field'] => $accounts[$index][$data['field']]]);
    }
}
