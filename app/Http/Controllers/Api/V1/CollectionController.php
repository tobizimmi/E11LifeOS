<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Household;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile API for the generic collections/items system (notes, pantry/household supplies and custom
 * databases). It lets the app read and edit these entries offline and sync the changes back; tasks
 * keep their own dedicated endpoints in {@see LifeController}.
 */
class CollectionController extends ApiController
{
    public function index(): JsonResponse
    {
        $household = $this->household();

        return $this->ok(
            Collection::query()->visibleTo($this->user(), $household->id)
                ->withCount('items')->orderBy('name')->get()
                ->map(fn (Collection $collection) => $this->collectionData($collection))
                ->all()
        );
    }

    /**
     * One collection with its property definitions and its visible entries.
     */
    public function items(Collection $collection): JsonResponse
    {
        $this->authorizeCollection($collection);

        return $this->ok([
            'collection' => $this->collectionData($collection) + [
                'properties' => CollectionProperty::where('collection_id', $collection->id)->orderBy('position')->get()
                    ->map(fn (CollectionProperty $property) => [
                        'id' => $property->id,
                        'name' => $property->name,
                        'type' => $property->type,
                        'options' => $property->options,
                        'system_key' => $property->system_key,
                    ])->all(),
            ],
            'items' => Item::query()->forHousehold($collection->household_id)->visibleTo($this->user())
                ->where('collection_id', $collection->id)->orderBy('position')->orderBy('id')->get()
                ->map(fn (Item $item) => $this->itemData($item))->all(),
        ]);
    }

    public function storeItem(Request $request, Collection $collection): JsonResponse
    {
        $this->authorizeCollection($collection);
        $data = $this->validateItem($request);

        $item = new Item([
            'household_id' => $collection->household_id,
            'collection_id' => $collection->id,
            'type' => $collection->isTasks() ? Item::TYPE_TODO : Item::TYPE_ENTRY,
            'visibility' => $collection->isPrivate() ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'title' => $data['title'],
            'icon' => $data['icon'] ?? null,
            'content' => $data['content'] ?? null,
            'created_by' => $this->user()->id,
            'position' => (int) Item::where('collection_id', $collection->id)->max('position') + 1,
        ]);
        $item->save();

        $this->applyProperties($item, $collection, $data['properties'] ?? []);

        return $this->ok($this->itemData($item->fresh()), 201);
    }

    public function updateItem(Request $request, Item $item): JsonResponse
    {
        $this->authorizeItem($item);
        $data = $this->validateItem($request, required: false);

        $item->fill(array_filter([
            'title' => $data['title'] ?? null,
            'icon' => $data['icon'] ?? null,
            'content' => $data['content'] ?? null,
        ], fn ($value) => $value !== null));

        if ($request->has('done')) {
            $item->done = (bool) $request->boolean('done');
        }

        $item->save();
        $this->applyProperties($item, Collection::findOrFail($item->collection_id), $data['properties'] ?? []);

        return $this->ok($this->itemData($item->fresh()));
    }

    public function destroyItem(Item $item): JsonResponse
    {
        $this->authorizeItem($item);
        $item->delete();

        return $this->ok(['id' => $item->id]);
    }

    /**
     * @return array{title?: string, icon?: ?string, content?: array<string, mixed>|null, properties?: array<int|string, mixed>}
     */
    private function validateItem(Request $request, bool $required = true): array
    {
        return $request->validate([
            'title' => [$required ? 'required' : 'sometimes', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:16'],
            'content' => ['nullable', 'array'],
            'properties' => ['nullable', 'array'],
        ]);
    }

    /**
     * Writes the given {propertyId: value} map, ignoring keys that are not real properties of the
     * collection so a stale app cannot set arbitrary data.
     *
     * @param  array<int|string, mixed>  $values
     */
    private function applyProperties(Item $item, Collection $collection, array $values): void
    {
        if ($values === []) {
            return;
        }

        $properties = $collection->properties()->get()->keyBy('id');

        foreach ($values as $propertyId => $value) {
            $property = $properties->get((int) $propertyId);
            if ($property instanceof CollectionProperty) {
                $item->setPropertyValue($property, $value === '' ? null : $value);
            }
        }

        $item->save();
    }

    /**
     * @return array{id: int, name: string, icon: string, is_tasks: bool, item_count: int}
     */
    private function collectionData(Collection $collection): array
    {
        return [
            'id' => $collection->id,
            'name' => $collection->name,
            'icon' => $collection->icon ?: Collection::DEFAULT_ICON,
            'is_tasks' => $collection->isTasks(),
            'item_count' => (int) ($collection->items_count ?? $collection->items()->count()),
        ];
    }

    /**
     * @return array{id: int, collection_id: int, title: string, icon: ?string, content: array<string, mixed>|null, done: bool, properties: array<int, mixed>, position: int}
     */
    private function itemData(Item $item): array
    {
        return [
            'id' => $item->id,
            'collection_id' => (int) $item->collection_id,
            'title' => $item->title,
            'icon' => $item->icon,
            'content' => $item->content,
            'done' => (bool) $item->done,
            'properties' => $item->properties ?? [],
            'position' => (int) $item->position,
        ];
    }

    private function authorizeCollection(Collection $collection): void
    {
        abort_unless(
            Collection::query()->visibleTo($this->user(), $this->household()->id)->whereKey($collection->id)->exists(),
            404
        );
    }

    private function authorizeItem(Item $item): void
    {
        abort_unless(
            Item::query()->forHousehold($this->household()->id)->visibleTo($this->user())->whereKey($item->id)->exists(),
            404
        );
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
