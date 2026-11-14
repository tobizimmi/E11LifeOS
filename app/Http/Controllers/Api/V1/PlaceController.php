<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Item;
use App\Models\Place;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Places to remember for the app: restaurants, cafés and sights with rating and visits.
 */
class PlaceController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok([
            'categories' => collect(Place::categories())->map(fn (array $category, string $key) => ['key' => $key] + $category)->values()->all(),
            'places' => Place::query()->visibleTo($this->user(), $this->household()->id)->orderBy('name')->get()
                ->map(fn (Place $place) => $this->placeData($place))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $visited = (bool) ($data['visited'] ?? false);

        $place = Place::create($this->attributes($data) + [
            'household_id' => $this->household()->id,
            'created_by' => $this->user()->id,
            'visibility' => ($data['private'] ?? false) ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'visited' => $visited,
            'visit_count' => $visited ? 1 : 0,
            'last_visited_on' => $visited ? today() : null,
        ]);

        return $this->ok($this->placeData($place->refresh()), 201);
    }

    public function update(Request $request, int $place): JsonResponse
    {
        $model = $this->find($place);
        $data = $this->validated($request);
        $attributes = $this->attributes($data);

        if (array_key_exists('private', $data) && $model->created_by === $this->user()->id) {
            $attributes['visibility'] = $data['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;
        }

        if (array_key_exists('visited', $data)) {
            $attributes['visited'] = (bool) $data['visited'];
        }

        $model->update($attributes);

        return $this->ok($this->placeData($model));
    }

    /**
     * Counts one more visit today.
     */
    public function visit(int $place): JsonResponse
    {
        $model = $this->find($place);
        $model->update(['visited' => true, 'visit_count' => $model->visit_count + 1, 'last_visited_on' => today()]);

        return $this->ok($this->placeData($model));
    }

    /**
     * Rates the place with 1–5 stars (0 removes the rating); a rated place counts as visited.
     */
    public function rate(Request $request, int $place): JsonResponse
    {
        $data = $request->validate(['stars' => ['required', 'integer', 'between:0,5']]);
        $model = $this->find($place);
        $model->update([
            'rating' => $data['stars'] ?: null,
            'visited' => true,
            'visit_count' => max(1, $model->visit_count),
            'last_visited_on' => $model->last_visited_on ?? today(),
        ]);

        return $this->ok($this->placeData($model));
    }

    public function destroy(int $place): JsonResponse
    {
        $model = $this->find($place);
        abort_unless($model->household_id === $this->household()->id, 403);
        $model->delete();

        return $this->ok();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(array_keys(Place::categories()))],
            'cuisine' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'url' => ['nullable', 'url:http,https', 'max:500'],
            'phone' => ['nullable', 'string', 'max:40'],
            'price_level' => ['nullable', 'integer', 'between:1,4'],
            'recommended_by' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'visited' => ['sometimes', 'boolean'],
            'private' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [
            'name' => trim($data['name']),
            'category' => $data['category'],
            'price_level' => ($data['price_level'] ?? null) !== null ? (int) $data['price_level'] : null,
        ];

        foreach (['cuisine', 'address', 'city', 'url', 'phone', 'recommended_by', 'notes'] as $field) {
            $attributes[$field] = trim((string) ($data[$field] ?? '')) ?: null;
        }

        return $attributes;
    }

    private function find(int $id): Place
    {
        return Place::query()->visibleTo($this->user(), $this->household()->id)->findOrFail($id);
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function placeData(Place $place): array
    {
        return [
            'id' => $place->id,
            'name' => $place->name,
            'category' => $place->category,
            'cuisine' => $place->cuisine,
            'address' => $place->address,
            'city' => $place->city,
            'url' => $place->url,
            'phone' => $place->phone,
            'price_level' => $place->price_level,
            'rating' => $place->rating,
            'recommended_by' => $place->recommended_by,
            'notes' => $place->notes,
            'visited' => (bool) $place->visited,
            'visit_count' => (int) $place->visit_count,
            'last_visited_on' => $place->last_visited_on?->toDateString(),
            'maps_url' => $place->mapsUrl(),
            'private' => $place->isPrivate(),
            'mine' => $place->created_by === $this->user()->id,
        ];
    }
}
