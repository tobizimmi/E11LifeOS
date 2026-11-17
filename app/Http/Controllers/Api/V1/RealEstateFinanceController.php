<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\RealEstate;
use App\Models\RealEstateBudgetItem;
use App\Models\RealEstateDraw;
use App\Models\RealEstateLoan;
use App\Models\RealEstateSubsidy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Mobile API for the financing of a property: loans, subsidies and the draw controlling.
 */
class RealEstateFinanceController extends ApiController
{
    public function show(int $estate): JsonResponse
    {
        return $this->ok($this->payload($this->find($estate)));
    }

    public function storeLoan(Request $request, int $estate): JsonResponse
    {
        $property = $this->find($estate);
        $property->loans()->create($this->loanFields($request));

        return $this->ok($this->payload($property), 201);
    }

    public function updateLoan(Request $request, int $estate, int $loan): JsonResponse
    {
        $property = $this->find($estate);
        $this->loan($property, $loan)->update($this->loanFields($request));

        return $this->ok($this->payload($property));
    }

    public function destroyLoan(int $estate, int $loan): JsonResponse
    {
        $property = $this->find($estate);
        $this->loan($property, $loan)->delete();

        return $this->ok($this->payload($property));
    }

    public function storeSubsidy(Request $request, int $estate): JsonResponse
    {
        $property = $this->find($estate);
        $property->subsidies()->create($this->subsidyFields($request, $property));

        return $this->ok($this->payload($property), 201);
    }

    public function updateSubsidy(Request $request, int $estate, int $subsidy): JsonResponse
    {
        $property = $this->find($estate);
        $this->subsidy($property, $subsidy)->update($this->subsidyFields($request, $property));

        return $this->ok($this->payload($property));
    }

    /**
     * Moves a subsidy along its way; the matching date is set to today when still empty.
     */
    public function subsidyStatus(Request $request, int $estate, int $subsidy): JsonResponse
    {
        $property = $this->find($estate);
        $data = $request->validate(['status' => ['required', Rule::in(RealEstateSubsidy::STATUSES)]]);
        $model = $this->subsidy($property, $subsidy);
        $field = ['applied' => 'applied_on', 'approved' => 'approved_on', 'paid' => 'paid_on'][$data['status']] ?? null;
        $model->status = $data['status'];

        if ($field !== null && $model->getAttribute($field) === null) {
            $model->setAttribute($field, today());
        }

        $model->save();

        return $this->ok($this->payload($property));
    }

    public function destroySubsidy(int $estate, int $subsidy): JsonResponse
    {
        $property = $this->find($estate);
        $this->subsidy($property, $subsidy)->delete();

        return $this->ok($this->payload($property));
    }

    public function storeDraw(Request $request, int $estate): JsonResponse
    {
        $property = $this->find($estate);
        $property->draws()->create($this->drawFields($request, $property) + ['position' => (int) $property->draws()->max('position') + 1]);

        return $this->ok($this->payload($property), 201);
    }

    public function updateDraw(Request $request, int $estate, int $draw): JsonResponse
    {
        $property = $this->find($estate);
        $this->draw($property, $draw)->update($this->drawFields($request, $property));

        return $this->ok($this->payload($property));
    }

    /**
     * Marks the whole planned amount as disbursed today, or clears it again.
     */
    public function drawDone(int $estate, int $draw): JsonResponse
    {
        $property = $this->find($estate);
        $model = $this->draw($property, $draw);
        $done = $model->actual_amount >= $model->planned_amount && $model->actual_amount > 0;
        $model->update($done
            ? ['actual_amount' => 0, 'actual_on' => null]
            : ['actual_amount' => $model->planned_amount, 'actual_on' => $model->actual_on ?? today()]);

        return $this->ok($this->payload($property));
    }

    public function destroyDraw(int $estate, int $draw): JsonResponse
    {
        $property = $this->find($estate);
        $this->draw($property, $draw)->delete();

        return $this->ok($this->payload($property));
    }

    /**
     * @return array<string, mixed>
     */
    private function loanFields(Request $request): array
    {
        $data = $request->validate([
            'lender' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(RealEstateLoan::KINDS)],
            'amount' => ['required', 'numeric', 'min:1', 'max:100000000'],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:20'],
            'repayment_rate' => ['required', 'numeric', 'min:0', 'max:50'],
            'fixed_years' => ['required', 'integer', 'min:1', 'max:40'],
            'start_on' => ['nullable', 'date'],
        ]);

        return ['start_on' => $data['start_on'] ?? null] + $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function subsidyFields(Request $request, RealEstate $property): array
    {
        $data = $request->validate([
            'program' => ['required', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(RealEstateSubsidy::KINDS)],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'status' => ['required', Rule::in(RealEstateSubsidy::STATUSES)],
            'budget_item_id' => ['nullable', 'integer'],
            'deadline_on' => ['nullable', 'date'],
            'applied_on' => ['nullable', 'date'],
            'approved_on' => ['nullable', 'date'],
            'paid_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (! empty($data['budget_item_id'])) {
            abort_unless(RealEstateBudgetItem::where('real_estate_id', $property->id)->whereKey($data['budget_item_id'])->exists(), 404);
        }

        return [
            'program' => $data['program'],
            'title' => $data['title'],
            'kind' => $data['kind'],
            'amount' => (float) ($data['amount'] ?? 0),
            'status' => $data['status'],
            'budget_item_id' => $data['budget_item_id'] ?? null,
            'deadline_on' => $data['deadline_on'] ?? null,
            'applied_on' => $data['applied_on'] ?? null,
            'approved_on' => $data['approved_on'] ?? null,
            'paid_on' => $data['paid_on'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function drawFields(Request $request, RealEstate $property): array
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(RealEstateDraw::SOURCES)],
            'loan_id' => ['nullable', 'integer'],
            'label' => ['required', 'string', 'max:255'],
            'planned_amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'planned_on' => ['nullable', 'date'],
            'actual_amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'actual_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $loanId = $data['source'] === 'loan' ? ($data['loan_id'] ?? null) : null;

        if ($loanId !== null) {
            abort_unless(RealEstateLoan::where('real_estate_id', $property->id)->whereKey($loanId)->exists(), 404);
        }

        return [
            'source' => $data['source'],
            'loan_id' => $loanId,
            'label' => $data['label'],
            'planned_amount' => (float) ($data['planned_amount'] ?? 0),
            'planned_on' => $data['planned_on'] ?? null,
            'actual_amount' => (float) ($data['actual_amount'] ?? 0),
            'actual_on' => $data['actual_on'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(RealEstate $property): array
    {
        $loans = [];
        $debt = 0.0;
        $monthly = 0.0;

        foreach (RealEstateLoan::where('real_estate_id', $property->id)->orderBy('id')->get() as $loan) {
            $startValue = $loan->start_on ?? $property->purchase_date;
            $start = $startValue !== null ? Carbon::parse((string) $startValue) : null;
            $elapsed = $start !== null && $start->lte(today()) ? (int) $start->diffInMonths(today()) : 0;
            $now = $loan->after($elapsed);
            $debt += $now['balance'];
            $monthly += $loan->monthlyRate();
            $loans[] = [
                'id' => $loan->id,
                'lender' => $loan->lender,
                'kind' => $loan->kind,
                'amount' => $loan->amount,
                'interest_rate' => $loan->interest_rate,
                'repayment_rate' => $loan->repayment_rate,
                'fixed_years' => $loan->fixed_years,
                'start_on' => $this->date($loan->start_on),
                'monthly_rate' => $loan->monthlyRate(),
                'balance' => $now['balance'],
                'balance_after_fixed' => $loan->after($loan->fixed_years * 12)['balance'],
                'interest_this_year' => $loan->interestIn((int) today()->year),
            ];
        }

        $subsidies = [];
        $secured = 0.0;

        foreach (RealEstateSubsidy::where('real_estate_id', $property->id)->orderBy('id')->get() as $subsidy) {
            $secured += $subsidy->isSecured() ? $subsidy->amount : 0.0;
            $subsidies[] = [
                'id' => $subsidy->id,
                'program' => $subsidy->program,
                'title' => $subsidy->title,
                'kind' => $subsidy->kind,
                'amount' => $subsidy->amount,
                'status' => $subsidy->status,
                'budget_item_id' => $subsidy->budget_item_id,
                'deadline_on' => $this->date($subsidy->deadline_on),
                'applied_on' => $this->date($subsidy->applied_on),
                'approved_on' => $this->date($subsidy->approved_on),
                'paid_on' => $this->date($subsidy->paid_on),
                'notes' => $subsidy->notes,
                'secured' => $subsidy->isSecured(),
            ];
        }

        $draws = [];

        foreach (RealEstateDraw::where('real_estate_id', $property->id)->orderBy('source')->orderBy('position')->orderBy('id')->get() as $draw) {
            $draws[] = [
                'id' => $draw->id,
                'source' => $draw->source,
                'loan_id' => $draw->loan_id,
                'label' => $draw->label,
                'planned_amount' => $draw->planned_amount,
                'planned_on' => $this->date($draw->planned_on),
                'actual_amount' => $draw->actual_amount,
                'actual_on' => $this->date($draw->actual_on),
                'notes' => $draw->notes,
                'utilisation' => $draw->utilisation(),
            ];
        }

        return [
            'loans' => $loans,
            'subsidies' => $subsidies,
            'draws' => $draws,
            'debt' => round($debt, 2),
            'monthly_rate' => round($monthly, 2),
            'secured_subsidies' => round($secured, 2),
            'equity' => (float) $property->equity,
        ];
    }

    private function find(int $id): RealEstate
    {
        $household = $this->household();

        return RealEstate::query()->visibleTo($this->user(), $household->id)->find($id) ?? abort(404);
    }

    private function loan(RealEstate $property, int $id): RealEstateLoan
    {
        return RealEstateLoan::where('real_estate_id', $property->id)->find($id) ?? abort(404);
    }

    private function subsidy(RealEstate $property, int $id): RealEstateSubsidy
    {
        return RealEstateSubsidy::where('real_estate_id', $property->id)->find($id) ?? abort(404);
    }

    private function draw(RealEstate $property, int $id): RealEstateDraw
    {
        return RealEstateDraw::where('real_estate_id', $property->id)->find($id) ?? abort(404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function date(mixed $value): ?string
    {
        return $value !== null ? Carbon::parse((string) $value)->toDateString() : null;
    }
}
