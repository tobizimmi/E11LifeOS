<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BankConnection;
use App\Models\BankLink;
use App\Support\Banking\BankAlerts;
use App\Support\Banking\BankLinkSync;
use App\Support\Banking\BankSync;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
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
                'has_pin' => filled($connection->pin),
                'pending_tan' => $connection->hasPendingTan() ? (string) $connection->getAttribute('sync_message') : null,
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
    /**
     * Takes the holdings last fetched from the bank into the linked portfolio: "add" adjusts the quantities the
     * bank reports, "replace" also books out positions the bank no longer lists.
     */
    public function applyToPortfolio(Request $request, int $connection, BankSync $sync): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:add,replace']]);
        $model = BankConnection::where('user_id', $this->user()->id)->findOrFail($connection);

        try {
            $count = $sync->applyToPortfolio($model, $data['mode']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['mode' => $exception->getMessage()]);
        }

        return $this->ok(['adopted' => $count, 'portfolio_id' => $model->portfolio_id]);
    }

    /**
     * Starts a FinTS fetch ("depot", "statement", "accounts" or "test"). The PIN is only needed when it is not
     * saved; the answer is the result or a TAN request ("tan_required", with the challenge and whether the
     * confirmation happens in the banking app).
     */
    public function fetch(Request $request, int $connection, BankSync $sync): JsonResponse
    {
        $data = $request->validate(['operation' => ['required', 'in:depot,statement,accounts,test'], 'pin' => ['nullable', 'string', 'max:64']]);
        $model = BankConnection::where('user_id', $this->user()->id)->findOrFail($connection);

        if (! $model->pin && blank($data['pin'] ?? null)) {
            return $this->ok(['status' => 'pin_required']);
        }

        return $this->bankResult(fn () => $sync->run($model, $data['operation'], $data['pin'] ?? null));
    }

    public function submitTan(Request $request, int $connection, BankSync $sync): JsonResponse
    {
        $data = $request->validate(['tan' => ['required', 'string', 'max:32']]);
        $model = BankConnection::where('user_id', $this->user()->id)->findOrFail($connection);

        return $this->bankResult(fn () => $sync->submitTan($model, trim($data['tan'])));
    }

    /**
     * Polls while the member confirms in the banking app ("waiting" until it is done).
     */
    public function checkDecoupled(int $connection, BankSync $sync): JsonResponse
    {
        $model = BankConnection::where('user_id', $this->user()->id)->findOrFail($connection);

        return $this->bankResult(fn () => $sync->checkDecoupled($model) ?? ['status' => 'waiting']);
    }

    /**
     * @param  callable(): array<string, mixed>  $call
     */
    private function bankResult(callable $call): JsonResponse
    {
        try {
            $result = $call();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['bank' => $exception->getMessage()]);
        }

        return $this->ok(['status' => $result['status'], 'message' => $result['message'] ?? null, 'challenge' => $result['challenge'] ?? null, 'decoupled' => (bool) ($result['decoupled'] ?? false)]);
    }

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
