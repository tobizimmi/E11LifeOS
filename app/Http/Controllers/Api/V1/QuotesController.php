<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Item;
use App\Models\Quote;
use App\Models\QuoteComparison;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Mobile API for quote comparisons: several quotes for one purchase side by side, and the choice.
 */
class QuotesController extends ApiController
{
    public function index(): JsonResponse
    {
        $household = $this->household();
        $comparisons = QuoteComparison::query()->visibleTo($this->user(), $household->id)->latest()->get()
            ->sortBy(fn (QuoteComparison $comparison) => $comparison->isDecided() ? 1 : 0)->values();

        return $this->ok(array_map(fn (QuoteComparison $comparison) => $this->summary($comparison), $comparisons->all()));
    }

    public function show(int $comparison): JsonResponse
    {
        return $this->ok($this->detail($this->find($this->household(), $comparison)));
    }

    public function store(Request $request): JsonResponse
    {
        $household = $this->household();
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'icon' => ['nullable', 'string', 'max:16'], 'decide_by' => ['nullable', 'date'], 'private' => ['sometimes', 'boolean']]);
        $comparison = QuoteComparison::create([
            'household_id' => $household->id,
            'created_by' => $this->user()->id,
            'visibility' => ! empty($data['private']) ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'title' => trim($data['title']),
            'icon' => ($data['icon'] ?? null) ?: '🧾',
            'decide_by' => $data['decide_by'] ?? null,
        ]);

        return $this->ok($this->detail($comparison), 201);
    }

    public function update(Request $request, int $comparison): JsonResponse
    {
        $item = $this->find($this->household(), $comparison);
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:255'], 'icon' => ['sometimes', 'nullable', 'string', 'max:16'], 'decide_by' => ['sometimes', 'nullable', 'date'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000']]);
        $item->update($data);

        return $this->ok($this->detail($item->refresh()));
    }

    public function destroy(int $comparison): JsonResponse
    {
        $this->find($this->household(), $comparison)->delete();

        return $this->ok();
    }

    public function addQuote(Request $request, int $comparison): JsonResponse
    {
        $item = $this->find($this->household(), $comparison);
        $data = $this->validated($request, true);
        $item->quotes()->create($data + ['position' => (int) $item->quotes()->max('position') + 1]);

        return $this->ok($this->detail($item->refresh()), 201);
    }

    public function updateQuote(Request $request, int $comparison, int $quote): JsonResponse
    {
        $item = $this->find($this->household(), $comparison);
        $this->quote($item, $quote)->update($this->validated($request, false));

        return $this->ok($this->detail($item->refresh()));
    }

    public function destroyQuote(int $comparison, int $quote): JsonResponse
    {
        $item = $this->find($this->household(), $comparison);
        $row = $this->quote($item, $quote);

        if ($item->chosen_quote_id === $row->id) {
            $item->choose(null);
        }

        $row->delete();

        return $this->ok($this->detail($item->refresh()));
    }

    /**
     * Picks the winning quote; quote null undoes the choice.
     */
    public function choose(Request $request, int $comparison): JsonResponse
    {
        $item = $this->find($this->household(), $comparison);
        $data = $request->validate(['quote' => ['present', 'nullable', 'integer']]);
        $item->choose($data['quote'] !== null ? $this->quote($item, (int) $data['quote']) : null);

        return $this->ok($this->detail($item->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'vendor' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
            'quoted_on' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'delivery' => ['sometimes', 'nullable', 'string', 'max:255'],
            'rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'pros' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'cons' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'url' => ['sometimes', 'nullable', 'url:http,https', 'max:2000'],
        ]);

        return array_map(fn ($value) => $value === '' ? null : $value, $data);
    }

    private function quote(QuoteComparison $comparison, int $id): Quote
    {
        return Quote::where('quote_comparison_id', $comparison->id)->find($id) ?? abort(404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    private function find(Household $household, int $id): QuoteComparison
    {
        return QuoteComparison::query()->visibleTo($this->user(), $household->id)->find($id) ?? abort(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(QuoteComparison $comparison): array
    {
        $cheapest = $comparison->cheapestQuote();
        $chosen = $comparison->chosen_quote_id !== null ? Quote::find($comparison->chosen_quote_id) : null;

        return [
            'id' => $comparison->id,
            'title' => $comparison->title,
            'icon' => $comparison->icon,
            'decide_by' => $comparison->decide_by !== null ? Carbon::parse((string) $comparison->decide_by)->toDateString() : null,
            'decided' => $comparison->isDecided(),
            'chosen' => $chosen !== null ? ['vendor' => $chosen->vendor, 'amount' => $chosen->amount !== null ? (float) $chosen->amount : null] : null,
            'cheapest' => $cheapest !== null ? ['vendor' => $cheapest->vendor, 'amount' => (float) $cheapest->amount] : null,
            'quotes' => Quote::where('quote_comparison_id', $comparison->id)->count(),
            'private' => $comparison->visibility === Item::VISIBILITY_PRIVATE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(QuoteComparison $comparison): array
    {
        $cheapest = $comparison->cheapestQuote();
        $quotes = [];

        foreach (Quote::where('quote_comparison_id', $comparison->id)->orderBy('position')->orderBy('id')->get() as $quote) {
            $quotes[] = [
                'id' => $quote->id,
                'vendor' => $quote->vendor,
                'amount' => $quote->amount !== null ? (float) $quote->amount : null,
                'quoted_on' => $quote->quoted_on !== null ? Carbon::parse((string) $quote->quoted_on)->toDateString() : null,
                'valid_until' => $quote->valid_until !== null ? Carbon::parse((string) $quote->valid_until)->toDateString() : null,
                'delivery' => $quote->delivery,
                'rating' => $quote->rating,
                'pros' => $quote->pros,
                'cons' => $quote->cons,
                'notes' => $quote->notes,
                'url' => $quote->url,
                'chosen' => $comparison->chosen_quote_id === $quote->id,
                'cheapest' => $cheapest?->id === $quote->id,
            ];
        }

        return $this->summary($comparison) + ['notes' => $comparison->notes, 'quote_list' => $quotes];
    }
}
