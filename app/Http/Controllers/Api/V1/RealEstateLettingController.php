<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\RealEstate;
use App\Models\RealEstateTenant;
use App\Models\RealEstateUtilityStatement;
use App\Support\RealEstate\RentalAccounting;
use App\Support\RealEstate\RentalPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Mobile API for letting, part two: the yearly utility statement (Nebenkostenabrechnung), index rent,
 * the tenant portal link and the letting PDFs (lease, reminder, statement, rent increase).
 */
class RealEstateLettingController extends ApiController
{
    public function utility(Request $request, int $estate): JsonResponse
    {
        $property = $this->find($estate);

        return $this->ok($this->utilityPayload($property, $this->year($request)));
    }

    public function saveUtility(Request $request, int $estate): JsonResponse
    {
        $property = $this->find($estate);
        $types = RealEstateUtilityStatement::costTypes();
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'total_area' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'total_units' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'costs' => ['present', 'array', 'max:40'],
            'costs.*.type' => ['required', Rule::in(array_keys($types))],
            'costs.*.label' => ['required', 'string', 'max:255'],
            'costs.*.amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'costs.*.key' => ['required', Rule::in(RealEstateUtilityStatement::KEYS)],
        ]);
        // § 556 BGB: the billing period may be at most twelve months.
        abort_if(Carbon::parse($data['period_start'])->diffInDays(Carbon::parse($data['period_end'])) > 366, 422, __('The period may be at most twelve months (§ 556 BGB).'));

        $costs = array_map(fn (array $cost) => ['type' => $cost['type'], 'label' => trim($cost['label']), 'amount' => round((float) $cost['amount'], 2), 'key' => $cost['key']], $data['costs']);
        RealEstateUtilityStatement::updateOrCreate(['real_estate_id' => $property->id, 'year' => $data['year']], [
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'total_area' => $data['total_area'] ?? null,
            'total_units' => $data['total_units'] ?? null,
            'notes' => ($data['notes'] ?? '') ?: null,
            'costs' => $costs,
        ]);

        return $this->ok($this->utilityPayload($property, (int) $data['year']));
    }

    public function utilitySent(int $estate, int $year): JsonResponse
    {
        $property = $this->find($estate);
        $statement = RealEstateUtilityStatement::where('real_estate_id', $property->id)->where('year', $year)->first() ?? abort(404);
        $statement->update(['sent_on' => $statement->sent_on ? null : today()]);

        return $this->ok($this->utilityPayload($property, $year));
    }

    public function utilityPdf(int $estate, int $year, int $tenant): Response
    {
        $property = $this->find($estate);
        $statement = RealEstateUtilityStatement::where('real_estate_id', $property->id)->where('year', $year)->first() ?? abort(404);
        $model = $this->tenant($property, $tenant);

        return $this->pdf(RentalPdf::utilityStatement($statement, $model), 'nebenkostenabrechnung-'.$year.'-'.Str::slug($model->name).'.pdf');
    }

    public function leasePdf(int $estate, int $tenant): Response
    {
        $model = $this->tenant($this->find($estate), $tenant);

        return $this->pdf(RentalPdf::lease($model), 'mietvertrag-'.Str::slug($model->name).'.pdf');
    }

    public function dunningPdf(Request $request, int $estate, int $tenant): Response
    {
        $model = $this->tenant($this->find($estate), $tenant);
        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'level' => ['nullable', 'integer', 'in:1,2']]);

        return $this->pdf(RentalPdf::dunning($model, (int) $data['year'], (int) ($data['level'] ?? 1)), 'zahlungserinnerung-'.Str::slug($model->name).'.pdf');
    }

    /**
     * What an index rent adjustment would be (new cold rent, from when, whether it is allowed already).
     */
    public function indexPreview(Request $request, int $estate, int $tenant): JsonResponse
    {
        $model = $this->tenant($this->find($estate), $tenant);
        $data = $request->validate(['index' => ['required', 'numeric', 'min:1', 'max:1000']]);
        $calculation = RentalAccounting::indexRent($model, (float) $data['index']);
        abort_if($calculation === null, 422, __('Enter the index base of the tenant first.'));

        return $this->ok($this->indexData($calculation));
    }

    /**
     * Takes over the index adjustment: new cold rent, the current index becomes the new base.
     */
    public function indexApply(Request $request, int $estate, int $tenant): JsonResponse
    {
        $model = $this->tenant($this->find($estate), $tenant);
        $data = $request->validate(['index' => ['required', 'numeric', 'min:1', 'max:1000'], 'month' => ['nullable', 'date_format:Y-m']]);
        $calculation = RentalAccounting::indexRent($model, (float) $data['index']);
        abort_if($calculation === null, 422, __('Enter the index base of the tenant first.'));
        abort_unless($calculation['allowed'], 422, __('An index rent may change at most once a year – next on :date.', ['date' => $calculation['allowed_from']->format('d.m.Y')]));

        $model->update([
            'cold_rent' => $calculation['new'],
            'index_base' => (float) $data['index'],
            'index_base_month' => ($data['month'] ?? '') ?: now()->format('Y-m'),
            'last_rent_change_on' => $calculation['effective_on'],
        ]);

        return $this->ok($this->indexData($calculation));
    }

    public function indexPdf(Request $request, int $estate, int $tenant): Response
    {
        $model = $this->tenant($this->find($estate), $tenant);
        $data = $request->validate(['index' => ['required', 'numeric', 'min:1', 'max:1000'], 'month' => ['nullable', 'date_format:Y-m']]);

        return $this->pdf(RentalPdf::indexRent($model, (float) $data['index'], $data['month'] ?? null), 'mieterhoehung-'.Str::slug($model->name).'.pdf');
    }

    /**
     * Creates the link of the tenant portal (replaces an existing one).
     */
    public function createPortal(int $estate, int $tenant): JsonResponse
    {
        $model = $this->tenant($this->find($estate), $tenant);
        $model->update(['portal_token' => Str::random(48)]);

        return $this->ok(['url' => $this->portalUrl($model)], 201);
    }

    public function revokePortal(int $estate, int $tenant): JsonResponse
    {
        $this->tenant($this->find($estate), $tenant)->update(['portal_token' => null]);

        return $this->ok();
    }

    public function portal(int $estate, int $tenant): JsonResponse
    {
        $model = $this->tenant($this->find($estate), $tenant);

        return $this->ok(['url' => $this->portalUrl($model)]);
    }

    /**
     * @param  array{old: float, new: float, increase: float, percent: float, allowed: bool, allowed_from: Carbon, effective_on: Carbon}  $calculation
     * @return array<string, mixed>
     */
    private function indexData(array $calculation): array
    {
        return [
            'old' => $calculation['old'],
            'new' => $calculation['new'],
            'increase' => $calculation['increase'],
            'percent' => $calculation['percent'],
            'allowed' => $calculation['allowed'],
            'allowed_from' => $calculation['allowed_from']->toDateString(),
            'effective_on' => $calculation['effective_on']->toDateString(),
        ];
    }

    private function portalUrl(RealEstateTenant $tenant): ?string
    {
        $token = $tenant->getAttribute('portal_token');

        return is_string($token) && $token !== '' ? route('rental.portal', ['token' => $token]) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function utilityPayload(RealEstate $property, int $year): array
    {
        $types = RealEstateUtilityStatement::costTypes();
        $statement = RealEstateUtilityStatement::where('real_estate_id', $property->id)->where('year', $year)->first();
        $costs = [];

        if ($statement !== null) {
            $stored = $statement->getAttribute('costs');

            foreach (is_array($stored) ? $stored : [] as $cost) {
                if (is_array($cost)) {
                    $costs[] = ['type' => (string) ($cost['type'] ?? 'other'), 'label' => (string) ($cost['label'] ?? ''), 'amount' => (float) ($cost['amount'] ?? 0), 'key' => (string) ($cost['key'] ?? 'area')];
                }
            }
        } else {
            foreach (['property_tax', 'water', 'street_cleaning', 'insurance'] as $type) {
                $costs[] = ['type' => $type, 'label' => $types[$type]['label'], 'amount' => 0.0, 'key' => $types[$type]['key']];
            }
        }

        $result = null;

        if ($statement !== null) {
            $calculation = RentalAccounting::utilityStatement($statement);
            $rows = [];

            foreach ($calculation['tenants'] as $row) {
                $rows[] = [
                    'tenant_id' => $row['tenant']->id,
                    'name' => $row['tenant']->name,
                    'unit' => $row['tenant']->unit,
                    'days' => $row['days'],
                    'lines' => $row['lines'],
                    'costs' => $row['costs'],
                    'advances' => $row['advances'],
                    'balance' => $row['balance'],
                ];
            }

            $result = ['period_days' => $calculation['period_days'], 'total' => $calculation['total'], 'landlord' => $calculation['landlord'], 'tenants' => $rows];
        }

        return [
            'year' => $year,
            'exists' => $statement !== null,
            'period_start' => $statement !== null ? Carbon::parse((string) $statement->period_start)->toDateString() : $year.'-01-01',
            'period_end' => $statement !== null ? Carbon::parse((string) $statement->period_end)->toDateString() : $year.'-12-31',
            'total_area' => $statement !== null ? $statement->total_area : ($property->living_area !== null ? (float) $property->living_area : null),
            'total_units' => $statement !== null ? $statement->total_units : null,
            'notes' => $statement !== null ? $statement->notes : null,
            'sent_on' => $statement !== null && $statement->sent_on !== null ? Carbon::parse((string) $statement->sent_on)->toDateString() : null,
            'costs' => $costs,
            'total' => round(array_sum(array_column($costs, 'amount')), 2),
            'cost_types' => array_map(fn (array $type, string $key) => ['type' => $key, 'label' => $type['label'], 'key' => $type['key']], $types, array_keys($types)),
            'keys' => RealEstateUtilityStatement::keyLabels(),
            'result' => $result,
        ];
    }

    private function pdf(string $content, string $name): Response
    {
        return response($content, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"']);
    }

    private function year(Request $request): int
    {
        $year = (int) $request->query('year', (string) today()->year);

        return $year >= 2000 && $year <= 2100 ? $year : (int) today()->year;
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
}
