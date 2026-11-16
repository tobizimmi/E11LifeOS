<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\RealEstate;
use App\Models\RealEstateRentPayment;
use App\Models\RealEstateTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Mobile API for letting a property: tenants with their lease terms and the monthly rent check.
 * Handovers, service-charge statements, applicants and the tenant portal stay in the web app.
 */
class RealEstateRentalController extends ApiController
{
    public function show(Request $request, int $estate): JsonResponse
    {
        $property = $this->find($estate);
        $year = (int) $request->query('year', (string) today()->year);
        abort_unless($year >= 2000 && $year <= 2100, 422);

        return $this->ok($this->payload($property, $year));
    }

    public function storeTenant(Request $request, int $estate): JsonResponse
    {
        $property = $this->find($estate);
        RealEstateTenant::create($this->tenantFields($request) + ['real_estate_id' => $property->id, 'created_by' => $this->user()->id]);

        return $this->ok($this->payload($property, (int) today()->year), 201);
    }

    public function updateTenant(Request $request, int $estate, int $tenant): JsonResponse
    {
        $property = $this->find($estate);
        $this->tenant($property, $tenant)->update($this->tenantFields($request));

        return $this->ok($this->payload($property, (int) today()->year));
    }

    public function destroyTenant(int $estate, int $tenant): JsonResponse
    {
        $property = $this->find($estate);
        $this->tenant($property, $tenant)->delete();

        return $this->ok($this->payload($property, (int) today()->year));
    }

    /**
     * Rent received for a month: marks it as paid (the full monthly amount, today) or clears it again.
     */
    public function togglePayment(Request $request, int $estate, int $tenant): JsonResponse
    {
        $property = $this->find($estate);
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'month' => ['required', 'integer', 'min:1', 'max:12']]);
        $model = $this->tenant($property, $tenant);
        $date = Carbon::create((int) $data['year'], (int) $data['month'], 1)->startOfDay();
        $payments = RealEstateRentPayment::where('tenant_id', $model->id)->whereYear('month', $data['year'])->whereMonth('month', $data['month'])->get();

        if ($payments->isNotEmpty()) {
            $payments->each->delete();
        } else {
            abort_unless($model->livesInMonth($date), 422, __('The tenant does not live there in this month.'));
            RealEstateRentPayment::create(['tenant_id' => $model->id, 'month' => $date->toDateString(), 'amount' => $model->monthlyTotal(), 'paid_on' => today()->min($date->copy()->endOfMonth())->max($date->copy()->subDays(7))->toDateString()]);
        }

        return $this->ok($this->payload($property, (int) $data['year']));
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantFields(Request $request): array
    {
        $data = $request->validate([
            'unit' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'persons' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'area' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'move_in_on' => ['required', 'date'],
            'move_out_on' => ['nullable', 'date', 'after_or_equal:move_in_on'],
            'cold_rent' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'utilities_advance' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'deposit_paid_on' => ['nullable', 'date'],
            'lease_end_on' => ['nullable', 'date', 'after:move_in_on'],
            'notice_months' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'rent_adjustment' => ['sometimes', Rule::in(RealEstateTenant::RENT_ADJUSTMENTS)],
            'index_base' => ['nullable', 'numeric', 'min:1', 'max:1000'],
            'index_base_month' => ['nullable', 'date_format:Y-m'],
            'due_day' => ['sometimes', 'integer', 'min:1', 'max:28'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        // § 551 BGB: at most three monthly cold rents.
        abort_if((float) ($data['deposit'] ?? 0) > 3 * (float) $data['cold_rent'] + 0.001, 422, __('The deposit may be at most three monthly cold rents (§ 551 BGB).'));

        return [
            'unit' => $data['unit'] ?? null,
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'persons' => (int) ($data['persons'] ?? 1),
            'area' => $data['area'] ?? null,
            'move_in_on' => $data['move_in_on'],
            'move_out_on' => $data['move_out_on'] ?? null,
            'cold_rent' => (float) $data['cold_rent'],
            'utilities_advance' => (float) ($data['utilities_advance'] ?? 0),
            'deposit' => (float) ($data['deposit'] ?? 0),
            'deposit_paid_on' => $data['deposit_paid_on'] ?? null,
            'lease_end_on' => $data['lease_end_on'] ?? null,
            'notice_months' => (int) ($data['notice_months'] ?? 3),
            'rent_adjustment' => $data['rent_adjustment'] ?? 'none',
            'index_base' => $data['index_base'] ?? null,
            'index_base_month' => $data['index_base_month'] ?? null,
            'due_day' => (int) ($data['due_day'] ?? 3),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(RealEstate $property, int $year): array
    {
        $tenants = [];
        $expected = 0.0;
        $received = 0.0;

        foreach (RealEstateTenant::where('real_estate_id', $property->id)->orderBy('move_in_on')->get() as $tenant) {
            $paid = [];

            foreach (RealEstateRentPayment::where('tenant_id', $tenant->id)->whereYear('month', $year)->get() as $payment) {
                $paid[(int) Carbon::parse((string) $payment->month)->month] = $payment->amount;
            }

            $months = [];

            for ($month = 1; $month <= 12; $month++) {
                $date = Carbon::create($year, $month, 1)->startOfDay();
                $lives = $tenant->livesInMonth($date);
                $isPaid = isset($paid[$month]);
                $due = $lives && $date->copy()->day($tenant->due_day)->lt(today());
                $expected += $lives && $date->lte(today()) ? $tenant->monthlyTotal() : 0.0;
                $received += $paid[$month] ?? 0.0;
                $months[] = ['month' => $month, 'lives' => $lives, 'paid' => $isPaid, 'overdue' => $due && ! $isPaid];
            }

            $tenants[] = [
                'id' => $tenant->id,
                'unit' => $tenant->unit,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'persons' => $tenant->persons,
                'area' => $tenant->area,
                'move_in_on' => $this->date($tenant->move_in_on),
                'move_out_on' => $this->date($tenant->move_out_on),
                'cold_rent' => $tenant->cold_rent,
                'utilities_advance' => $tenant->utilities_advance,
                'monthly_total' => $tenant->monthlyTotal(),
                'deposit' => $tenant->deposit,
                'deposit_paid_on' => $this->date($tenant->deposit_paid_on),
                'lease_end_on' => $this->date($tenant->lease_end_on),
                'notice_months' => $tenant->notice_months,
                'rent_adjustment' => $tenant->rent_adjustment,
                'due_day' => $tenant->due_day,
                'notes' => $tenant->notes,
                'active' => $tenant->isActiveOn(today()),
                'index_base' => $tenant->index_base,
                'index_base_month' => $tenant->index_base_month,
                'next_index_change' => $this->date($tenant->nextIndexChangeOn()),
                'has_portal' => $tenant->getAttribute('portal_token') !== null,
                'months' => $months,
            ];
        }

        return ['year' => $year, 'tenants' => $tenants, 'expected' => round($expected, 2), 'received' => round($received, 2), 'open' => round(max(0.0, $expected - $received), 2)];
    }

    private function find(int $id): RealEstate
    {
        $household = $this->household();

        return RealEstate::query()->visibleTo($this->user(), $household->id)->find($id) ?? abort(404);
    }

    private function tenant(RealEstate $property, int $id): RealEstateTenant
    {
        return RealEstateTenant::where('real_estate_id', $property->id)->find($id) ?? abort(404);
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
