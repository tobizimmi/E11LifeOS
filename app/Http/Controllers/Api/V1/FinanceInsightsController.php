<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FinanceBudget;
use App\Models\Household;
use App\Models\Transaction;
use App\Support\AiException;
use App\Support\Finance\FinanceAi;
use App\Support\Finance\FinancePeriod;
use App\Support\Finance\FinanceReport;
use App\Support\Finance\TaxCategories;
use App\Support\Finance\TaxYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Finance extras for the app: the report with the AI analyst, the tax year (deductions, credits,
 * tagging bookings) and turning a budget into a pot whose left-over money carries on.
 */
class FinanceInsightsController extends ApiController
{
    public function report(Request $request): JsonResponse
    {
        $period = $this->period($request);
        $report = $this->buildReport($period);

        return $this->ok([
            'period' => $period,
            'periods' => collect(FinancePeriod::options())->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values()->all(),
            'ai' => FinanceAi::isConfigured(),
            'report' => $this->presentReport($report),
        ]);
    }

    public function advice(Request $request, FinanceAi $ai): JsonResponse
    {
        $report = $this->buildReport($this->period($request));
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
    private function buildReport(string $period): array
    {
        [$from, $to] = FinancePeriod::range($period);

        return FinanceReport::build(FinancePeriod::query($this->user(), $this->household()->id, $period)->get(), $from, $to);
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

        return array_key_exists($period, FinancePeriod::options()) ? $period : FinancePeriod::DEFAULT;
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
