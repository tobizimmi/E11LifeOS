<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\LoadTripPlaces;
use App\Models\Item;
use App\Models\Trip;
use App\Models\TripStop;
use App\Support\Life\TripPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile API for trips: create, edit and delete a trip, ready-made packing lists, un-pack all
 * and tick off stops.
 */
class TripExtrasController extends ApiController
{
    public function templates(): JsonResponse
    {
        return $this->ok(collect(TripPlanner::templates())->map(fn (array $template, string $key): array => ['key' => $key, 'label' => $template['label'], 'icon' => $template['icon'], 'count' => count($template['items'])])->values()->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + ['template' => ['nullable', Rule::in(array_keys(TripPlanner::templates()))]]);
        $household = $this->user()->currentHousehold() ?? abort(404);

        $trip = Trip::create($this->attributes($data) + [
            'household_id' => $household->id,
            'created_by' => $this->user()->id,
            'visibility' => ! empty($data['private']) ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
        ]);

        if ($trip->destination) {
            LoadTripPlaces::start($trip);
        }

        if (! empty($data['template'])) {
            TripPlanner::applyTemplate($trip, 'basics');
            TripPlanner::applyTemplate($trip, $data['template']);
        }

        return $this->ok(['id' => $trip->id], 201);
    }

    public function update(Request $request, int $trip): JsonResponse
    {
        $model = $this->find($trip);
        $data = $request->validate($this->rules());
        $newDestination = ($data['destination'] ?? null) !== $model->destination;

        // Fields the app did not send (budget, travelers, notes) stay as they are.
        $attributes = array_intersect_key($this->attributes($data), array_flip(array_merge(['title', 'destination', 'starts_on', 'ends_on'], array_keys(array_intersect_key($data, array_flip(['budget', 'travelers', 'notes']))))));
        $model->update($attributes + ($model->created_by === $this->user()->id && array_key_exists('private', $data) ? ['visibility' => $data['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED] : []));

        if ($newDestination) {
            $model->forceFill(['map_area' => null, 'places_loaded_at' => null])->save();
            LoadTripPlaces::start($model);
        }

        return $this->ok(['id' => $model->id]);
    }

    public function destroy(int $trip): JsonResponse
    {
        $model = $this->find($trip);
        abort_unless($model->created_by === $this->user()->id || $this->user()->isAdmin(), 403);
        $model->delete();

        return $this->ok();
    }

    public function applyTemplate(Request $request, int $trip): JsonResponse
    {
        $model = $this->find($trip);
        $data = $request->validate(['template' => ['required', Rule::in(array_keys(TripPlanner::templates()))], 'person' => ['nullable', 'string', 'max:60']]);
        $added = TripPlanner::applyTemplate($model, $data['template'], $data['person'] ?? null);

        return $this->ok(['added' => $added]);
    }

    public function unpackAll(int $trip): JsonResponse
    {
        $this->find($trip)->packingItems()->whereNull('owner_id')->update(['packed' => false]);

        return $this->ok();
    }

    public function stopDone(int $stop): JsonResponse
    {
        $model = TripStop::findOrFail($stop);
        $this->find($model->trip_id);
        $model->update(['done' => ! $model->done]);

        return $this->ok(['id' => $model->id, 'done' => (bool) $model->done]);
    }

    private function find(int $id): Trip
    {
        $household = $this->user()->currentHousehold() ?? abort(404);

        return Trip::query()->visibleTo($this->user(), $household->id)->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'destination' => ['nullable', 'string', 'max:150'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'travelers' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'private' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'title' => $data['title'],
            'destination' => ($data['destination'] ?? null) ?: null,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'budget' => $data['budget'] ?? null,
            'travelers' => ($data['travelers'] ?? null) ?: null,
            'notes' => ($data['notes'] ?? null) ?: null,
        ];
    }
}
