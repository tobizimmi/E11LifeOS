<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Support\ContractOverview;
use App\Support\Finance\SubscriptionMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contract costs for the app: all contracts with their monthly cost, the sums and the upcoming
 * cancellation deadlines.
 */
class ContractController extends ApiController
{
    public function index(): JsonResponse
    {
        $overview = ContractOverview::for($this->user(), $this->household()->id);

        return $this->ok([
            'monthly' => round($overview['monthly'], 2),
            'yearly' => round($overview['yearly'], 2),
            'by_category' => collect($overview['by_category'])->map(fn (float $amount, string $category) => ['category' => $category, 'monthly' => round($amount, 2)])->values()->all(),
            'contracts' => collect($overview['contracts'])->map(fn (array $contract) => [
                'id' => $contract['item']->id,
                'title' => $contract['item']->title,
                'provider' => $contract['provider'],
                'customer_number' => $contract['customer_number'],
                'category' => $contract['category'],
                'monthly' => round($contract['monthly'], 2),
                'cancel_by' => $contract['cancel_by'],
                'days_to_cancel' => $contract['cancel_by'] ? ContractOverview::daysUntil($contract['cancel_by']) : null,
                'ends_on' => $contract['ends_on'],
                'collection_id' => $contract['collection']->id,
            ])->values()->all(),
        ]);
    }

    /**
     * A new contract entered by hand, added to the household's contracts list.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'provider' => ['nullable', 'string', 'max:255'],
            'monthly' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $household = $this->household();
        $item = SubscriptionMatcher::create(
            $this->user(),
            $household->id,
            SubscriptionMatcher::contractCollection($this->user(), $household->id),
            trim($data['title']),
            trim((string) ($data['provider'] ?? '')) ?: null,
            ($data['monthly'] ?? null) !== null ? (float) $data['monthly'] : null,
            null,
        );

        return $this->ok(['id' => $item->id, 'title' => $item->title, 'collection_id' => $item->collection_id], 201);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
