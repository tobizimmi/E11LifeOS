<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BankAccount;
use App\Models\FinanceBudget;
use App\Models\Household;
use App\Models\Transaction;
use App\Support\AiException;
use App\Support\Finance\FinanceAi;
use App\Support\Finance\FinanceCategories;
use App\Support\Finance\FinancePeriod;
use App\Support\Finance\FinanceReport;
use App\Support\Finance\TaxCategories;
use App\Support\Finance\TaxYear;
use App\Support\Finance\TransactionReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Finance extras for the app: the report with the AI analyst, the tax year (deductions, credits,
 * tagging bookings) and turning a budget into a pot whose left-over money carries on.
 */
class FinanceInsightsController extends ApiController
{
    /**
     * The report: a preset or free period, optionally compared with the one before, with the toggles for
     * transfers and savings, a category drill-down and an account filter, plus the transfers and duplicates
     * waiting for review.
     */
    public function report(Request $request): JsonResponse
    {
        $period = $this->period($request);
        [$from, $to] = FinancePeriod::range($period, $request->query('from'), $request->query('to'));
        $options = $this->options($request);
        $report = $this->buildReport($request);
        $comparison = null;
        $deltas = [];

        if ($request->boolean('compare')) {
            [$prevFrom, $prevTo] = FinancePeriod::previousRange($from, $to);
            $previous = FinanceReport::build($this->reportQuery($request, $prevFrom, $prevTo)->get(), $prevFrom, $prevTo, $options);
            $comparison = ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()];
            $deltas = [
                ['key' => 'income', 'label' => __('Income'), 'delta' => round((float) $report['income'] - (float) $previous['income'], 2), 'higher_better' => true],
                ['key' => 'expenses', 'label' => __('Expenses'), 'delta' => round((float) $report['expenses'] - (float) $previous['expenses'], 2), 'higher_better' => false],
                ['key' => 'balance', 'label' => __('Left over'), 'delta' => round((float) $report['balance'] - (float) $previous['balance'], 2), 'higher_better' => true],
            ];
        }

        $review = app(TransactionReview::class);
        $household = $this->household();

        return $this->ok([
            'period' => $period,
            'periods' => collect(FinancePeriod::options())->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
            'ai' => FinanceAi::isConfigured(),
            'categories' => collect(FinanceCategories::all())->reject(fn (array $category): bool => in_array($category['group'], ['income', 'transfer'], true))->map(fn (array $category, string $key): array => ['key' => $key, 'label' => $category['label'], 'icon' => $category['icon']])->values()->all(),
            'accounts' => BankAccount::where('user_id', $this->user()->id)->where('household_id', $household->id)->orderBy('name')->get(['id', 'name'])->map(fn (BankAccount $account): array => ['id' => $account->id, 'name' => $account->name])->all(),
            'comparison' => $comparison,
            'deltas' => $deltas,
            'report' => $this->presentReport($report),
            'transfers' => collect($review->transfers($this->user(), $household->id))->map(fn (array $pair): array => ['out' => $this->bookingData($pair['out']), 'in' => $this->bookingData($pair['in'])])->all(),
            'duplicates' => collect($review->duplicates($this->user(), $household->id))->map(fn (array $pair): array => ['keep' => $this->bookingData($pair['keep']), 'duplicate' => $this->bookingData($pair['duplicate'])])->all(),
        ]);
    }

    public function advice(Request $request, FinanceAi $ai): JsonResponse
    {
        $report = $this->buildReport($request);
        if ($report['count'] === 0) {
            throw ValidationException::withMessages(['period' => __('No bookings in this period.')]);
        }

        try {
            return $this->ok($ai->advise($report));
        } catch (AiException $exception) {
            throw ValidationException::withMessages(['ai' => $exception->getMessage()]);
        }
    }

    public function tax(Request $request): JsonResponse
    {
        $user = $this->user();
        $household = $this->household();
        $year = $this->year($request);
        $data = TaxYear::build($user, $household->id, $year);
        $categories = TaxCategories::all();

        $first = Transaction::where('user_id', $user->id)->min('booked_on');
        $years = collect(range((int) now()->year, min((int) now()->year - 1, $first ? (int) substr((string) $first, 0, 4) : (int) now()->year - 1)))->push($year)->unique()->sortDesc()->values()->all();

        return $this->ok([
            'year' => $year,
            'years' => $years,
            'rate' => (float) $data['rate'],
            'children' => (int) ($user->tax_children ?? 0),
            'categories' => collect($categories)->map(fn (array $category, string $key): array => ['key' => $key, 'label' => $category['label'], 'icon' => $category['icon'], 'form' => $category['form'], 'hint' => $category['hint']])->values()->all(),
            'deductions' => collect($data['deductions'])->map(fn (array $row, string $key): array => ['key' => $key, 'label' => $categories[$key]['label'] ?? $key, 'icon' => $categories[$key]['icon'] ?? '', 'spent' => (float) $row['spent'], 'deductible' => (float) $row['deductible']])->values()->all(),
            'deductible' => (float) $data['deductible'],
            'deduction_effect' => (float) $data['deduction_effect'],
            'credits' => $data['credits'],
            'rental_effect' => $data['rental_effect'],
            'business' => $data['business'],
            'portfolio' => $data['portfolio'],
            'effect' => (float) $data['effect'],
            'tagged' => $this->yearBookings($year)->whereNotNull('tax_category')->get()->map(fn (Transaction $transaction): array => $this->bookingData($transaction))->values()->all(),
            'suggestions' => $this->suggestions($year),
        ]);
    }

    /**
     * Tags a booking with a tax category (null removes it) and optionally its tax-relevant part.
     */
    public function tagBooking(Request $request, int $transaction): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'in:'.implode(',', array_keys(TaxCategories::all()))],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $model = Transaction::where('user_id', $this->user()->id)->where('household_id', $this->household()->id)->findOrFail($transaction);

        $category = ($data['category'] ?? null) ?: null;
        $amount = $category === null ? null : (array_key_exists('amount', $data) && $data['amount'] !== null ? round((float) $data['amount'], 2) : null);
        if ($amount !== null && $amount > abs((float) $model->amount)) {
            throw ValidationException::withMessages(['amount' => __('At most the amount of the booking.')]);
        }
        $model->update(['tax_category' => $category, 'tax_amount' => $amount]);

        return $this->ok($this->bookingData($model->refresh()));
    }

    public function search(Request $request): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        if ($q === '') {
            return $this->ok([]);
        }

        return $this->ok($this->yearBookings($this->year($request))->whereNull('tax_category')
            ->where(fn ($query) => $query->whereRaw('LOWER(counterparty) LIKE ?', ['%'.$q.'%'])->orWhereRaw('LOWER(purpose) LIKE ?', ['%'.$q.'%']))
            ->orderByDesc('booked_on')->limit(20)->get()->map(fn (Transaction $transaction): array => $this->bookingData($transaction))->all());
    }

    public function acceptSuggestions(Request $request): JsonResponse
    {
        $accepted = 0;
        foreach ($this->yearBookings($this->year($request))->whereNull('tax_category')->orderBy('booked_on')->get() as $transaction) {
            $category = TaxCategories::suggest($transaction);
            if ($category !== null) {
                $transaction->update(['tax_category' => $category]);
                $accepted++;
            }
        }

        return $this->ok(['accepted' => $accepted]);
    }

    public function taxSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['rate' => ['required', 'numeric', 'min:0', 'max:45'], 'children' => ['required', 'integer', 'min:0', 'max:20']]);
        $this->user()->update(['tax_rate' => (float) $data['rate'], 'tax_children' => (int) $data['children']]);

        return $this->ok(['rate' => (float) $data['rate'], 'children' => (int) $data['children']]);
    }

    /**
     * Turns a budget into a pot (left-over money carries into the next month) or back.
     */
    public function toggleRollover(string $category): JsonResponse
    {
        $budget = FinanceBudget::where('user_id', $this->user()->id)->where('household_id', $this->household()->id)->where('category', $category)->firstOrFail();
        $budget->update(['rollover_from' => $budget->rollover_from ? null : now()->startOfMonth()]);

        return $this->ok(['category' => $category, 'rollover' => $budget->rollover_from !== null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(Request $request): array
    {
        [$from, $to] = FinancePeriod::range($this->period($request), $request->query('from'), $request->query('to'));

        return FinanceReport::build($this->reportQuery($request, $from, $to)->get(), $from, $to, $this->options($request));
    }

    /**
     * @return array{includeTransfers: bool, includeSavings: bool}
     */
    private function options(Request $request): array
    {
        return ['includeTransfers' => $request->boolean('transfers'), 'includeSavings' => $request->boolean('savings')];
    }

    /**
     * The bookings behind the report: period, account and (optionally) one category.
     *
     * @return Builder<Transaction>
     */
    private function reportQuery(Request $request, Carbon $from, Carbon $to): Builder
    {
        $account = (int) $request->query('account', 0);
        $category = (string) $request->query('category', '');

        return FinancePeriod::queryFor($this->user(), $this->household()->id, $from, $to, $account > 0 ? $account : null)
            ->with('account')
            ->when($category !== '' && FinanceCategories::exists($category), fn (Builder $query) => $query->where('category', $category));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function presentReport(array $report): array
    {
        $copy = $report;
        $copy['from'] = $report['from']->toDateString();
        $copy['to'] = $report['to']->toDateString();
        $copy['largest'] = collect($report['largest'])->map(fn (Transaction $transaction): array => $this->bookingData($transaction))->all();

        return $copy;
    }

    private function period(Request $request): string
    {
        $period = (string) $request->query('period', $request->input('period', FinancePeriod::DEFAULT));

        return $period === FinancePeriod::CUSTOM || array_key_exists($period, FinancePeriod::options()) ? $period : FinancePeriod::DEFAULT;
    }

    private function year(Request $request): int
    {
        $year = (int) $request->query('year', $request->input('year', 0));

        return $year >= 2000 && $year <= 2100 ? $year : (int) now()->year - (now()->month <= 5 ? 1 : 0);
    }

    /**
     * @return Builder<Transaction>
     */
    private function yearBookings(int $year): Builder
    {
        return Transaction::where('user_id', $this->user()->id)->where('household_id', $this->household()->id)
            ->where('amount', '<', 0)->whereBetween('booked_on', [$year.'-01-01', $year.'-12-31']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suggestions(int $year): array
    {
        return $this->yearBookings($year)->whereNull('tax_category')->orderBy('booked_on')->get()
            ->map(fn (Transaction $transaction): array => ['transaction' => $transaction, 'category' => TaxCategories::suggest($transaction)])
            ->filter(fn (array $row): bool => $row['category'] !== null)
            ->map(fn (array $row): array => $this->bookingData($row['transaction']) + ['suggested' => $row['category']])
            ->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingData(Transaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'booked_on' => $transaction->booked_on->toDateString(),
            'counterparty' => $transaction->counterparty,
            'purpose' => $transaction->purpose,
            'amount' => (float) $transaction->amount,
            'category' => $transaction->category,
            'tax_category' => $transaction->tax_category,
            'tax_amount' => $transaction->tax_amount !== null ? (float) $transaction->tax_amount : null,
        ];
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
