<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Item;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The wishlist for the app: wishes with priority, price and status (open, bought, dropped).
 */
class WishlistController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok([
            'priorities' => $this->options(WishlistItem::priorities()),
            'categories' => $this->options(WishlistItem::categories()),
            'wishes' => WishlistItem::query()->visibleTo($this->user(), $this->household()->id)
                ->orderBy('position')->get()
                ->map(fn (WishlistItem $wish) => $this->wishData($wish))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $household = $this->household();

        $wish = WishlistItem::create($this->attributes($data) + [
            'household_id' => $household->id,
            'created_by' => $this->user()->id,
            'visibility' => ($data['private'] ?? false) ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'status' => 'open',
            'position' => (int) WishlistItem::where('household_id', $household->id)->max('position') + 1,
        ]);

        return $this->ok($this->wishData($wish->refresh()), 201);
    }

    public function update(Request $request, int $wish): JsonResponse
    {
        $model = $this->find($wish);
        $data = $this->validated($request);
        $attributes = $this->attributes($data);
        $linkChanged = $attributes['url'] !== $model->url;

        if (array_key_exists('private', $data) && $model->created_by === $this->user()->id) {
            $attributes['visibility'] = $data['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;
        }

        $model->update($attributes);

        // A new or changed link resets the tracked price so the next price check starts fresh.
        if ($linkChanged) {
            $model->forceFill(['tracked_price' => null, 'tracked_currency' => null, 'lowest_price' => null, 'price_checked_at' => null, 'price_check_status' => null])->save();
        }

        return $this->ok($this->wishData($model));
    }

    /**
     * Marks a wish as bought or dropped, or reopens it.
     */
    public function decide(Request $request, int $wish): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(WishlistItem::STATUSES)]]);
        $model = $this->find($wish);
        $model->update(['status' => $data['status'], 'decided_at' => $data['status'] === 'open' ? null : now()]);

        return $this->ok($this->wishData($model));
    }

    public function destroy(int $wish): JsonResponse
    {
        $this->find($wish)->delete();

        return $this->ok();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'priority' => ['required', Rule::in(WishlistItem::PRIORITIES)],
            'category' => ['required', Rule::in(array_keys(WishlistItem::categories()))],
            'reason' => ['nullable', 'string', 'max:2000'],
            'url' => ['nullable', 'url:http,https', 'max:500'],
            'wanted_by' => ['nullable', 'date_format:Y-m-d'],
            'private' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'title' => trim($data['title']),
            'price' => ($data['price'] ?? null) !== null && $data['price'] !== '' ? round((float) $data['price'], 2) : null,
            'priority' => $data['priority'],
            'category' => $data['category'],
            'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
            'url' => trim((string) ($data['url'] ?? '')) ?: null,
            'wanted_by' => ($data['wanted_by'] ?? null) ?: null,
        ];
    }

    /**
     * @param  array<string, array{label: string, icon: string}>  $options
     * @return list<array{key: string, label: string, icon: string}>
     */
    private function options(array $options): array
    {
        return collect($options)->map(fn (array $option, string $key) => ['key' => $key] + $option)->values()->all();
    }

    private function find(int $id): WishlistItem
    {
        return WishlistItem::query()->visibleTo($this->user(), $this->household()->id)->findOrFail($id);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function wishData(WishlistItem $wish): array
    {
        return [
            'id' => $wish->id,
            'title' => $wish->title,
            'price' => $wish->price,
            'priority' => $wish->priority,
            'category' => $wish->category,
            'reason' => $wish->reason,
            'url' => $wish->url,
            'status' => $wish->status,
            'wanted_by' => $wish->wanted_by?->toDateString(),
            'decided_at' => $wish->decided_at?->toIso8601String(),
            'tracked_price' => $wish->tracked_price,
            'lowest_price' => $wish->lowest_price,
            'price_drop' => $wish->hasPriceDrop(),
            'private' => $wish->isPrivate(),
            'mine' => $wish->created_by === $this->user()->id,
        ];
    }
}
