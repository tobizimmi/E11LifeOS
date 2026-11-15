<?php

namespace App\Http\Controllers\Api\V1;

use App\Support\ContractOverview;
use App\Support\Finance\InsuranceCheck;
use App\Support\Finance\RetirementPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile API for the provision check: gaps and duplicates among the insurances, and the pension gap
 * with the path to financial freedom.
 */
class ProvisionController extends ApiController
{
    public function insurance(): JsonResponse
    {
        $check = InsuranceCheck::for($this->user());
        $answers = $this->answers();

        return $this->ok([
            'rows' => array_map(fn (array $row) => $row + ['answer' => $answers[$row['key']] ?? null], $check['rows']),
            'unnecessary' => $check['unnecessary'],
            'candidates' => $check['candidates'],
            'monthly' => $check['monthly'],
            'gaps' => $check['gaps'],
            'types' => array_map(fn (string $key, array $type) => ['key' => $key, 'label' => $type['label'], 'icon' => $type['icon']], array_keys(InsuranceCheck::types()), array_values(InsuranceCheck::types())),
        ]);
    }

    /**
     * "I have it" / "not needed" for a kind of insurance (null clears the answer).
     */
    public function answer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(InsuranceCheck::types()))],
            'answer' => ['present', 'nullable', Rule::in([InsuranceCheck::HAVE, InsuranceCheck::NOT_NEEDED])],
        ]);
        $answers = $this->answers();

        if ($data['answer'] === null) {
            unset($answers[$data['type']]);
        } else {
            $answers[$data['type']] = $data['answer'];
        }

        $this->user()->update(['insurance_answers' => $answers ?: null]);

        return $this->insurance();
    }

    /**
     * Adds an insurance to the contracts.
     */
    public function storeInsurance(Request $request): JsonResponse
    {
        $household = $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(InsuranceCheck::types()))],
            'provider' => ['nullable', 'string', 'max:120'],
            'monthly' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        InsuranceCheck::create($this->user(), $household->id, $data['type'], trim((string) ($data['provider'] ?? '')), isset($data['monthly']) ? (float) $data['monthly'] : null);

        return $this->insurance();
    }

    /**
     * Sets the kind of insurance of a contract by hand ("none": it is no insurance, "": guess again).
     */
    public function assign(Request $request, int $item): JsonResponse
    {
        $household = $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
        $data = $request->validate(['type' => ['present', 'nullable', 'string']]);
        $type = (string) ($data['type'] ?? '');
        abort_unless($type === '' || $type === InsuranceCheck::NONE || isset(InsuranceCheck::types()[$type]), 422);

        foreach (ContractOverview::for($this->user(), $household->id)['contracts'] as $contract) {
            if ($contract['item']->id === $item) {
                InsuranceCheck::assign($contract['item'], $type === '' ? null : $type);

                return $this->insurance();
            }
        }

        abort(404);
    }

    /**
     * @return array<string, string>
     */
    private function answers(): array
    {
        $answers = $this->user()->getAttribute('insurance_answers');

        return is_array($answers) ? $answers : [];
    }

    public function retirement(): JsonResponse
    {
        $inputs = RetirementPlan::inputs($this->user());

        return $this->ok(['inputs' => $inputs, 'result' => RetirementPlan::calculate($inputs)]);
    }

    public function updateRetirement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'birth_year' => ['required', 'integer', 'between:1920,'.now()->year],
            'retirement_age' => ['required', 'integer', 'between:40,80'],
            'desired_income' => ['required', 'numeric', 'min:0', 'max:100000'],
            'statutory_pension' => ['required', 'numeric', 'min:0', 'max:100000'],
            'other_pension' => ['required', 'numeric', 'min:0', 'max:100000'],
            'assets' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'monthly_savings' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'return' => ['required', 'numeric', 'between:-5,15'],
            'inflation' => ['required', 'numeric', 'between:0,10'],
            'withdrawal' => ['required', 'numeric', 'between:2,8'],
        ]);
        $this->user()->update(['retirement_plan' => $data]);

        return $this->retirement();
    }

    public function resetRetirement(): JsonResponse
    {
        $this->user()->update(['retirement_plan' => null]);

        return $this->retirement();
    }
}
