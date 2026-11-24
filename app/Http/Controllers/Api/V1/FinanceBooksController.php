<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BankAccount;
use App\Models\ExternalLink;
use App\Models\FinanceBudget;
use App\Models\FinanceCategory;
use App\Models\FinanceRule;
use App\Models\Household;
use App\Models\Portfolio;
use App\Models\Transaction;
use App\Support\Finance\BankCsvImporter;
use App\Support\Finance\FinanceCategories;
use App\Support\Finance\TransactionReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Finance housekeeping for the app: editing and deleting bookings, confirming internal transfers and
 * duplicates, own categories and the accounts overview with balances and depots.
 */
class FinanceBooksController extends ApiController
{
    public function updateBooking(Request $request, int $transaction): JsonResponse
    {
        $data = $request->validate([
            'booked_on' => ['required', 'date'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'between:-999999999,999999999'],
        ]);

        $this->booking($transaction)->update([
            'booked_on' => $data['booked_on'],
            'counterparty' => trim((string) ($data['counterparty'] ?? '')) !== '' ? mb_substr(trim($data['counterparty']), 0, 255) : null,
            'purpose' => trim((string) ($data['purpose'] ?? '')) !== '' ? mb_substr(trim($data['purpose']), 0, 2000) : null,
            'amount' => round((float) $data['amount'], 2),
        ]);

        return $this->ok();
    }

    public function destroyBooking(int $transaction): JsonResponse
    {
        $model = $this->booking($transaction);
        ExternalLink::where('linkable_type', 'transaction')->where('linkable_id', $model->id)->get()->each->delete();
        $model->delete();

        return $this->ok();
    }

    public function confirmTransfer(Request $request): JsonResponse
    {
        $data = $request->validate(['out' => ['required', 'integer'], 'in' => ['required', 'integer']]);

        foreach ([$data['out'], $data['in']] as $id) {
            $this->booking($id)->update(['category' => FinanceCategories::TRANSFER, 'category_locked' => true]);
        }

        return $this->ok();
    }

    public function ignoreTransfer(Request $request): JsonResponse
    {
        $data = $request->validate(['out' => ['required', 'integer'], 'in' => ['required', 'integer']]);

        foreach ([$data['out'], $data['in']] as $id) {
            $this->booking($id)->update(['review_ignored_at' => now()]);
        }

        return $this->ok();
    }

    public function confirmAllTransfers(): JsonResponse
    {
        $pairs = app(TransactionReview::class)->transfers($this->user(), $this->household()->id);

        foreach ($pairs as $pair) {
            $this->booking($pair['out']->id)->update(['category' => FinanceCategories::TRANSFER, 'category_locked' => true]);
            $this->booking($pair['in']->id)->update(['category' => FinanceCategories::TRANSFER, 'category_locked' => true]);
        }

        return $this->ok(['confirmed' => count($pairs)]);
    }

    public function confirmDuplicate(Request $request): JsonResponse
    {
        $data = $request->validate(['keep' => ['required', 'integer'], 'duplicate' => ['required', 'integer', 'different:keep']]);
        $keep = $this->booking($data['keep']);
        $this->booking($data['duplicate'])->update(['duplicate_of_id' => $keep->id]);

        return $this->ok();
    }

    public function ignoreDuplicate(Request $request): JsonResponse
    {
        $data = $request->validate(['keep' => ['required', 'integer'], 'duplicate' => ['required', 'integer']]);

        foreach ([$data['keep'], $data['duplicate']] as $id) {
            $this->booking($id)->update(['review_ignored_at' => now()]);
        }

        return $this->ok();
    }

    /**
     * Own categories (name, icon, 50/30/20 group) with the built-in ones for reference.
     */
    public function categories(): JsonResponse
    {
        return $this->ok(FinanceCategory::where('household_id', $this->household()->id)->orderBy('label')->get()->map(fn (FinanceCategory $category): array => ['key' => $category->key, 'label' => $category->label, 'icon' => $category->icon, 'group' => $category->group])->all());
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'icon' => ['nullable', 'string', 'max:16'],
            'group' => ['required', Rule::in(['needs', 'wants', 'savings'])],
        ]);

        $category = FinanceCategory::create([
            'household_id' => $this->household()->id,
            'user_id' => $this->user()->id,
            'key' => 'cat_'.Str::lower(Str::random(8)),
            'label' => trim($data['label']),
            'icon' => trim((string) ($data['icon'] ?? '')) ?: '🏷️',
            'group' => $data['group'],
        ]);
        FinanceCategories::flush();

        return $this->ok(['key' => $category->key], 201);
    }

    /**
     * Removes an own category; its bookings, budget and rules fall back to "Other expenses".
     */
    public function destroyCategory(string $key): JsonResponse
    {
        abort_unless(FinanceCategories::isCustom($key), 404);
        $household = $this->household();

        FinanceCategory::where('household_id', $household->id)->where('key', $key)->delete();
        Transaction::where('user_id', $this->user()->id)->where('household_id', $household->id)->where('category', $key)->update(['category' => FinanceCategories::OTHER]);
        FinanceBudget::where('household_id', $household->id)->where('category', $key)->delete();
        FinanceRule::where('user_id', $this->user()->id)->where('category', $key)->update(['category' => FinanceCategories::OTHER]);
        FinanceCategories::flush();

        return $this->ok();
    }

    /**
     * Accounts with their current balance, the depots with their last value and the totals.
     */
    public function accounts(): JsonResponse
    {
        $user = $this->user();
        $accounts = BankAccount::where('user_id', $user->id)->where('household_id', $this->household()->id)->withCount('transactions')->orderBy('name')->get();
        $rows = $accounts->map(fn (BankAccount $account): array => ['id' => $account->id, 'name' => $account->name, 'balance' => $account->currentBalance(), 'bookings' => (int) $account->transactions_count]);
        $portfolios = Portfolio::where('user_id', $user->id)->with(['snapshots' => fn ($query) => $query->latest('taken_at')->limit(1)])->orderBy('name')->get();
        $depots = $portfolios->map(fn (Portfolio $portfolio): array => [
            'id' => $portfolio->id,
            'name' => $portfolio->name,
            'value' => data_get($portfolio->snapshots->first(), 'total_value'),
            'date' => data_get($portfolio->snapshots->first(), 'taken_at')?->toDateString(),
        ]);

        return $this->ok([
            'accounts' => $rows->values()->all(),
            'accounts_total' => round((float) $rows->sum(fn (array $row): float => (float) ($row['balance'] ?? 0)), 2),
            'accounts_missing' => $rows->filter(fn (array $row): bool => $row['balance'] === null)->count(),
            'depots' => $depots->values()->all(),
            'depots_total' => round((float) $depots->sum(fn (array $row): float => (float) ($row['value'] ?? 0)), 2),
        ]);
    }

    /**
     * Sets (or with an empty value clears) an account's balance as of today.
     */
    public function setBalance(Request $request, int $account): JsonResponse
    {
        $data = $request->validate(['balance' => ['nullable', 'string', 'max:30']]);
        $model = BankAccount::where('user_id', $this->user()->id)->where('household_id', $this->household()->id)->findOrFail($account);
        $parsed = trim((string) ($data['balance'] ?? '')) === '' ? null : BankCsvImporter::parseAmount($data['balance']);

        $model->update(['balance' => $parsed, 'balance_on' => $parsed === null ? null : today()->toDateString()]);

        return $this->ok();
    }

    private function booking(int $id): Transaction
    {
        return Transaction::where('user_id', $this->user()->id)->where('household_id', $this->household()->id)->findOrFail($id);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
