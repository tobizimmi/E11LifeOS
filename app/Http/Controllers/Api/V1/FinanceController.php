<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\CashflowEntry;
use App\Models\FinanceBudget;
use App\Models\Household;
use App\Models\Transaction;
use App\Support\Finance\FinanceCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile API for editing finances the same way the web does: recategorise imported bookings,
 * manage monthly budgets, and keep manual cashflow lines. There is deliberately no "add a
 * booking" here – transactions only ever come from a bank CSV import, as on the web.
 */
class FinanceController extends ApiController
{
    /**
     * The transaction category catalogue used by the recategorise picker.
     */
    public function categories(): JsonResponse
    {
        $categories = [];
        foreach (FinanceCategories::all() as $key => $meta) {
            $categories[] = ['key' => $key, 'label' => $meta['label'], 'icon' => $meta['icon'], 'group' => $meta['group']];
        }

        return $this->ok($categories);
    }

    public function recategorize(Request $request, int $transaction): JsonResponse
    {
        $tx = Transaction::where('household_id', $this->household()->id)->findOrFail($transaction);

        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(FinanceCategories::all()))],
        ]);

        // Locking keeps a later re-import from overwriting the member's manual choice.
        $tx->update(['category' => $data['category'], 'category_locked' => true]);

        return $this->ok([
            'id' => $tx->id,
            'category' => $tx->category,
            'category_label' => FinanceCategories::label($tx->category),
        ]);
    }

    public function budgets(): JsonResponse
    {
        $budgets = FinanceBudget::where('user_id', $this->user()->id)
            ->where('household_id', $this->household()->id)
            ->get()
            ->map(fn (FinanceBudget $budget): array => [
                'category' => $budget->category,
                'label' => FinanceCategories::label($budget->category),
                'icon' => FinanceCategories::icon($budget->category),
                'monthly_limit' => round((float) $budget->monthly_limit, 2),
                'rollover' => $budget->rollover_from !== null,
            ]);

        return $this->ok($budgets);
    }

    public function updateBudget(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(FinanceCategories::all()))],
            'monthly_limit' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
        $household = $this->household();

        // A limit of 0 means "no budget" – remove it.
        if ($data['monthly_limit'] <= 0) {
            FinanceBudget::where('user_id', $this->user()->id)
                ->where('household_id', $household->id)
                ->where('category', $data['category'])
                ->delete();

            return $this->ok(['category' => $data['category'], 'monthly_limit' => 0.0]);
        }

        $budget = FinanceBudget::updateOrCreate(
            ['user_id' => $this->user()->id, 'household_id' => $household->id, 'category' => $data['category']],
            ['monthly_limit' => $data['monthly_limit']],
        );

        return $this->ok([
            'category' => $budget->category,
            'label' => FinanceCategories::label($budget->category),
            'icon' => FinanceCategories::icon($budget->category),
            'monthly_limit' => round((float) $budget->monthly_limit, 2),
        ]);
    }

    public function cashflow(): JsonResponse
    {
        $entries = CashflowEntry::where('user_id', $this->user()->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (CashflowEntry $entry): array => $this->cashflowData($entry));

        return $this->ok($entries);
    }

    public function storeCashflow(Request $request): JsonResponse
    {
        $entry = new CashflowEntry($this->validatedCashflow($request, creating: true));
        $entry->user_id = $this->user()->id;
        $entry->position = (int) CashflowEntry::where('user_id', $this->user()->id)->max('position') + 1;
        $entry->save();

        return $this->ok($this->cashflowData($entry), 201);
    }

    public function updateCashflow(Request $request, CashflowEntry $cashflowEntry): JsonResponse
    {
        abort_unless($cashflowEntry->user_id === $this->user()->id, 404);

        $cashflowEntry->fill($this->validatedCashflow($request, creating: false, fallbackKind: $cashflowEntry->kind));
        $cashflowEntry->save();

        return $this->ok($this->cashflowData($cashflowEntry));
    }

    public function destroyCashflow(CashflowEntry $cashflowEntry): JsonResponse
    {
        abort_unless($cashflowEntry->user_id === $this->user()->id, 404);
        $cashflowEntry->delete();

        return $this->ok();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCashflow(Request $request, bool $creating, ?string $fallbackKind = null): array
    {
        $kind = (string) $request->input('kind', $fallbackKind);
        $categories = array_keys(CashflowEntry::categories()[$kind] ?? []);

        return $request->validate([
            'kind' => [$creating ? 'required' : 'sometimes', Rule::in([CashflowEntry::INCOME, CashflowEntry::EXPENSE, CashflowEntry::ASSET, CashflowEntry::LIABILITY])],
            'category' => [$creating ? 'required' : 'sometimes', Rule::in($categories)],
            'label' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'amount' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:100000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function cashflowData(CashflowEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'kind' => $entry->kind,
            'category' => $entry->category,
            'category_label' => $entry->categoryLabel(),
            'label' => $entry->label,
            'amount' => round((float) $entry->amount, 2),
            'notes' => $entry->notes,
        ];
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
