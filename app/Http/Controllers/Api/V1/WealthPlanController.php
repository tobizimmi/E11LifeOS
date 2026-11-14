<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\Finance\WealthPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The wealth plan ("FIRE") for the app: projects the net worth forward from the savings rate and
 * the chosen assumptions. Nothing is stored – every call computes the plan for the given inputs.
 */
class WealthPlanController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $inputs = $request->validate([
            'monthly_savings' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'monthly_expenses' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'return' => ['nullable', 'numeric', 'min:0', 'max:20'],
            'withdrawal' => ['nullable', 'numeric', 'min:1', 'max:10'],
            'target' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        $plan = WealthPlan::for($this->user(), array_filter($inputs, fn (mixed $value) => $value !== null));

        return $this->ok($plan + [
            'horizon_years' => WealthPlan::HORIZON_YEARS,
            // Every fifth year plus the year the target is reached – enough for a list on a phone.
            'milestones' => collect($plan['series'])->filter(fn (array $point) => $point['year'] > 0 && ($point['year'] % 5 === 0 || $point['year'] === $plan['years_to_target']))->values()->all(),
        ]);
    }
}
