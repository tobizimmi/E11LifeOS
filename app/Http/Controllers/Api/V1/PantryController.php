<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Item;
use App\Models\ShoppingList;
use App\Support\Pantry;
use App\Support\ShoppingLists;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The pantry for the app: what is in stock (groceries and household supplies), one more / one
 * less, and putting what runs low on the shopping list.
 */
class PantryController extends ApiController
{
    public function index(): JsonResponse
    {
        $household = $this->household();
        $collection = Pantry::ensure($this->user(), $household->id);

        return $this->ok([
            'kinds' => collect(Pantry::kindOptions())->map(fn (array $kind) => ['key' => $kind['id'], 'label' => $kind['name']])->all(),
            'places' => collect(Pantry::places($collection))->map(fn (string $name, string $id) => ['key' => $id, 'label' => $name])->values()->all(),
            'items' => $this->rows($household->id),
        ]);
    }

    /**
     * Adds an entry, or adds the quantity to an existing one with the same name.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in([Pantry::KIND_FOOD, Pantry::KIND_SUPPLIES])],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'unit' => ['nullable', 'string', 'max:30'],
            'minimum' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'place' => ['nullable', 'string', 'max:20'],
            'best_before' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $household = $this->household();
        $item = Pantry::add($this->user(), $household->id, [
            'name' => $data['name'],
            'kind' => $data['kind'],
            'quantity' => ($data['quantity'] ?? null) !== null ? (float) $data['quantity'] : null,
            'unit' => trim((string) ($data['unit'] ?? '')),
            'minimum' => ($data['minimum'] ?? null) !== null ? (float) $data['minimum'] : null,
            'place' => (string) ($data['place'] ?? ''),
            'best_before' => ($data['best_before'] ?? null) ?: null,
        ]);

        return $this->ok(collect($this->rows($household->id))->firstWhere('id', $item->id), 201);
    }

    /**
     * One more or one less of an entry (never below zero).
     */
    public function adjust(Request $request, int $item): JsonResponse
    {
        $data = $request->validate(['delta' => ['required', 'integer', Rule::in([-1, 1])]]);
        $entry = $this->find($item);
        Pantry::adjust($entry, (int) $data['delta']);

        return $this->ok(collect($this->rows($this->household()->id))->firstWhere('id', $entry->id));
    }

    /**
     * Puts one entry (or, without an id, everything running low) on the shopping list.
     */
    public function toShopping(Request $request): JsonResponse
    {
        $data = $request->validate(['item' => ['nullable', 'integer']]);
        $household = $this->household();
        $itemId = $data['item'] ?? null;

        $rows = Pantry::inventory($this->user(), $household->id)
            ->filter(fn (array $row) => $itemId === null ? $row['low'] : $row['item']->id === $itemId);
        abort_if($itemId !== null && $rows->isEmpty(), 404);

        $list = ShoppingList::defaultFor($household->id);

        foreach ($rows as $row) {
            $missing = $row['minimum'] !== null ? max(1, $row['minimum'] - ($row['quantity'] ?? 0) + 1) : null;
            ShoppingLists::add($list, $row['name'], $missing !== null ? trim(($missing + 0).' '.$row['unit']) : null, $this->user()->id);
        }

        return $this->ok(['added' => $rows->count()]);
    }

    public function destroy(int $item): JsonResponse
    {
        $this->find($item)->delete();

        return $this->ok();
    }

    private function find(int $itemId): Item
    {
        $collection = Pantry::collectionFor($this->household()->id);

        return Item::query()->visibleTo($this->user())->where('collection_id', $collection?->id)->find($itemId) ?? abort(404);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(int $householdId): array
    {
        return Pantry::inventory($this->user(), $householdId)->map(fn (array $row) => [
            'id' => $row['item']->id,
            'name' => $row['name'],
            'quantity' => $row['quantity'],
            'unit' => $row['unit'],
            'minimum' => $row['minimum'],
            'best_before' => $row['best_before']?->toDateString(),
            'kind' => $row['kind'],
            'place' => $row['place'],
            'low' => $row['low'],
        ])->values()->all();
    }
}
