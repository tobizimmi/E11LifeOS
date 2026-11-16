<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\RealEstate;
use App\Models\RealEstateAnalysis;
use App\Support\RealEstate\ProfitabilityAnalysis;
use App\Support\RealEstate\RealEstateLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile API for the profitability check of a rental property: saved calculations and a live
 * calculation of the figures, hints and projection.
 */
class RealEstateAnalysisController extends ApiController
{
    public function index(): JsonResponse
    {
        $household = $this->household();
        $saved = [];

        foreach (RealEstateAnalysis::where('household_id', $household->id)->latest('updated_at')->get(['id', 'title', 'updated_at']) as $analysis) {
            $saved[] = ['id' => $analysis->id, 'title' => $analysis->title, 'updated_at' => $analysis->updated_at?->toIso8601String()];
        }

        $properties = [];

        foreach (RealEstateLink::properties($this->user(), $household->id) as $property) {
            $properties[] = ['id' => $property->id, 'name' => $property->name];
        }

        return $this->ok([
            'saved' => $saved,
            'properties' => $properties,
            'defaults' => ProfitabilityAnalysis::defaults(),
            'transfer_tax' => RealEstate::TRANSFER_TAX,
            'states' => RealEstate::stateNames(),
        ]);
    }

    /**
     * Inputs prefilled from an owned property.
     */
    public function prefill(int $estate): JsonResponse
    {
        $household = $this->household();
        $property = RealEstate::query()->visibleTo($this->user(), $household->id)->find($estate) ?? abort(404);

        return $this->ok(['title' => $property->name, 'inputs' => ProfitabilityAnalysis::fromRealEstate($property->load('loans'))]);
    }

    /**
     * Calculates without saving (live preview).
     */
    public function calculate(Request $request): JsonResponse
    {
        $this->household();

        return $this->ok(ProfitabilityAnalysis::calculate($this->inputs($request)));
    }

    public function show(int $analysis): JsonResponse
    {
        $model = $this->find($analysis);
        $inputs = array_merge(ProfitabilityAnalysis::defaults(), $model->inputs);

        return $this->ok(['id' => $model->id, 'title' => $model->title, 'inputs' => $inputs, 'result' => ProfitabilityAnalysis::calculate($inputs)]);
    }

    public function store(Request $request): JsonResponse
    {
        $household = $this->household();
        $data = $request->validate(['title' => ['required', 'string', 'max:150']]);
        $model = RealEstateAnalysis::create(['title' => $data['title'], 'inputs' => $this->inputs($request), 'household_id' => $household->id, 'created_by' => $this->user()->id]);

        return $this->show($model->id)->setStatusCode(201);
    }

    public function update(Request $request, int $analysis): JsonResponse
    {
        $model = $this->find($analysis);
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:150']]);
        $model->update(['title' => $data['title'] ?? $model->title] + ($request->has('inputs') ? ['inputs' => $this->inputs($request)] : []));

        return $this->show($model->id);
    }

    public function destroy(int $analysis): JsonResponse
    {
        $this->find($analysis)->delete();

        return $this->ok();
    }

    /**
     * Numbers within sensible limits; unknown keys are dropped, missing ones take the default.
     *
     * @return array<string, float|int|string>
     */
    private function inputs(Request $request): array
    {
        $given = $request->input('inputs');
        $given = is_array($given) ? $given : [];
        $values = ProfitabilityAnalysis::defaults();

        foreach ($values as $key => $default) {
            if (! array_key_exists($key, $given)) {
                continue;
            }

            if ($key === 'state') {
                $values[$key] = is_string($given[$key]) && array_key_exists($given[$key], RealEstate::TRANSFER_TAX) ? $given[$key] : $default;

                continue;
            }

            $raw = $given[$key];
            $values[$key] = is_numeric($raw) ? max(0.0, min(100000000.0, (float) $raw)) : 0.0;
        }

        $values['years'] = max(1, min(40, (int) $values['years']));

        return $values;
    }

    private function find(int $id): RealEstateAnalysis
    {
        return RealEstateAnalysis::where('household_id', $this->household()->id)->find($id) ?? abort(404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
