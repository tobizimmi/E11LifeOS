<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Trip;
use App\Models\TripFeedback;
use App\Models\TripStop;
use App\Support\Life\PlaceFinder;
use App\Support\Life\TripDayPlanner;
use App\Support\Life\TripFeedbackBox;
use App\Support\Life\TripItinerary;
use App\Support\Life\TripRoutes;
use App\Support\Weather;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Trip extras for the app: the weather forecast, comments and suggestions of the trip group and
 * sorting a day's places by distance.
 */
class TripCollabController extends ApiController
{
    /**
     * The places of the trip with their map position. Entries without one are looked up (a few per
     * call) and remembered.
     */
    public function map(int $trip): JsonResponse
    {
        $model = $this->find($trip)->load('stops');
        $lookups = 0;

        foreach ($model->stops as $stop) {
            if ($stop->hasPosition() || $lookups >= 6) {
                continue;
            }

            $query = $stop->location ?: ($model->destination ? $stop->title.', '.$model->destination : null);
            if (! $query) {
                continue;
            }

            $lookups++;
            $position = PlaceFinder::locate($query, $stop->location ? $model->destination : null);

            if ($position !== null) {
                $stop->update(['lat' => $position['lat'], 'lng' => $position['lng']]);
            }
        }

        return $this->ok([
            'stops' => $model->stops->filter(fn (TripStop $stop): bool => $stop->hasPosition())
                ->sortBy([['day', 'asc'], ['position', 'asc']])->values()
                ->map(fn (TripStop $stop): array => [
                    'id' => $stop->id,
                    'title' => PlaceFinder::readable((string) $stop->title),
                    'kind' => $stop->kind,
                    'day' => $stop->day?->toDateString(),
                    'time' => $stop->time,
                    'lat' => (float) $stop->lat,
                    'lng' => (float) $stop->lng,
                ])->all(),
            'missing' => $model->stops->reject(fn (TripStop $stop): bool => $stop->hasPosition())->count(),
        ]);
    }

    public function weather(int $trip): JsonResponse
    {
        $model = $this->find($trip)->load('stops');
        $days = [];

        if (! $model->isPast() && ! $model->starts_on->gt(today()->addDays(15))) {
            $first = $model->stops->first(fn (TripStop $stop): bool => $stop->hasPosition());
            $center = $model->destination ? $this->center((string) $model->destination) : null;
            $center ??= $first ? ['lat' => (float) $first->lat, 'lng' => (float) $first->lng] : null;

            if ($center !== null) {
                $days = Weather::days((float) $center['lat'], (float) $center['lng'], $model->starts_on, $model->ends_on);
            }
        }

        return $this->ok(collect($days)->map(fn (array $day, string $date): array => ['date' => $date] + $day)->values()->all());
    }

    public function feedback(int $trip): JsonResponse
    {
        $model = $this->find($trip);

        return $this->ok($model->feedback()->with('stop')->limit(60)->get()->map(fn (TripFeedback $item): array => $this->feedbackData($item))->values()->all());
    }

    public function comment(Request $request, int $trip): JsonResponse
    {
        $model = $this->find($trip);
        $data = $request->validate(['text' => ['required', 'string', 'max:2000'], 'stop_id' => ['nullable', 'integer']]);
        $stopId = ($data['stop_id'] ?? null) !== null && $model->stops()->whereKey((int) $data['stop_id'])->exists() ? (int) $data['stop_id'] : null;

        $item = TripFeedbackBox::comment($model, ['name' => $this->user()->name, 'user' => $this->user()], (string) $data['text'], $stopId);

        return $this->ok($this->feedbackData($item), 201);
    }

    public function approve(Request $request, int $feedback): JsonResponse
    {
        $item = $this->findFeedback($feedback);
        $data = $request->validate(['day' => ['nullable', 'date']]);
        $stop = TripFeedbackBox::approve($item, array_key_exists('day', $data) ? (string) ($data['day'] ?? '') : null, $this->user());

        return $this->ok(['stop_id' => $stop->id]);
    }

    public function decline(int $feedback): JsonResponse
    {
        TripFeedbackBox::decline($this->findFeedback($feedback));

        return $this->ok();
    }

    public function destroyFeedback(int $feedback): JsonResponse
    {
        $this->findFeedback($feedback)->delete();

        return $this->ok();
    }

    /**
     * Sorts the places of one day by distance (the first one stays).
     */
    public function optimizeDay(int $trip, string $day): JsonResponse
    {
        $model = $this->find($trip);
        abort_unless((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $day), 422);

        $stops = $model->stops()->whereDate('day', $day)->get()->values();
        foreach (TripDayPlanner::shortestOrder($stops) as $index => $id) {
            $stops->firstWhere('id', $id)?->forceFill(['position' => $index + 1])->save();
        }

        return $this->ok(['sorted' => $stops->count()]);
    }

    /**
     * Duration and distance per day and per way (to each place from the one before). With
     * `fetch=1` missing routes are calculated first; otherwise only remembered ones are returned
     * and `pending` lists the days still to calculate.
     */
    public function routes(Request $request, int $trip): JsonResponse
    {
        $model = $this->find($trip)->load('stops');
        $mode = $this->tripMode($model);
        $routes = TripItinerary::dayRoutes(TripItinerary::build($model), $mode, fetch: $request->boolean('fetch'));

        return $this->ok([
            'travel_mode' => $mode,
            'modes' => $this->modes(),
            'days' => collect($routes['days'])->map(fn (array $day, string $date): array => [
                'date' => $date,
                'duration' => round($day['duration']),
                'distance' => round($day['distance']),
                'mode' => $day['mode'],
                'legs' => collect($day['legs'])->map(fn (array $leg, int $stopId): array => ['stop_id' => $stopId, 'duration' => round($leg['duration']), 'distance' => round($leg['distance']), 'mode' => $leg['mode']])->values()->all(),
            ])->values()->all(),
            'pending' => $routes['pending'],
            'failed' => $routes['failed'],
        ]);
    }

    /**
     * On foot, by bike or by car for the whole trip (the routes and times of all days change).
     */
    public function setTravelMode(Request $request, int $trip): JsonResponse
    {
        $model = $this->find($trip);
        $data = $request->validate(['mode' => ['required', 'in:'.implode(',', array_keys(TripRoutes::modes()))]]);
        $model->update(['travel_mode' => $data['mode']]);

        return $this->ok(['travel_mode' => $data['mode']]);
    }

    /**
     * The way to one entry: its own mode, or null for "as set for the trip".
     */
    public function setStopMode(Request $request, int $stop): JsonResponse
    {
        $model = TripStop::findOrFail($stop);
        $trip = $this->find($model->trip_id);
        $data = $request->validate(['mode' => ['nullable', 'in:'.implode(',', array_keys(TripRoutes::modes()))]]);
        $mode = $data['mode'] ?? null;
        $model->update(['travel_mode' => $mode === $this->tripMode($trip) ? null : $mode]);

        return $this->ok(['travel_mode' => $model->travel_mode]);
    }

    /**
     * Sets the times of a day: stay at each place plus the way to the next one.
     */
    public function scheduleDay(int $trip, string $day): JsonResponse
    {
        $model = $this->find($trip)->load('stops');
        abort_unless((bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $day), 422);

        $mode = $this->tripMode($model);
        $routes = TripItinerary::dayRoutes(TripItinerary::build($model), $mode, fetch: true);
        $stops = $model->stops()->whereDate('day', $day)->get()->values();

        $planned = TripDayPlanner::schedule($stops, $routes['days'][$day]['legs'] ?? [], $mode);
        foreach ($planned as $id => $times) {
            $stops->firstWhere('id', $id)?->update($times);
        }

        return $this->ok(['scheduled' => count($planned)]);
    }

    private function tripMode(Trip $trip): string
    {
        return $trip->travel_mode ?: 'foot';
    }

    /**
     * @return list<array{key: string, icon: string, label: string}>
     */
    private function modes(): array
    {
        return collect(TripRoutes::modes())->map(fn (array $mode, string $key): array => ['key' => $key, 'icon' => $mode['icon'], 'label' => $mode['label']])->values()->all();
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function center(string $destination): ?array
    {
        $key = 'places:center:'.md5($destination);
        $cached = Cache::get($key);

        if ($cached !== null) {
            return is_array($cached) ? ['lat' => (float) $cached['lat'], 'lng' => (float) $cached['lng']] : null;
        }

        $center = PlaceFinder::locate($destination);
        Cache::put($key, $center ?? false, $center ? now()->addDays(30) : now()->addMinutes(10));

        return $center === null ? null : ['lat' => (float) $center['lat'], 'lng' => (float) $center['lng']];
    }

    /**
     * @return array<string, mixed>
     */
    private function feedbackData(TripFeedback $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'author' => $item->author_name,
            'text' => $item->body,
            'title' => $item->title,
            'location' => $item->location,
            'day' => $item->day?->toDateString(),
            'status' => $item->status,
            'stop_id' => $item->trip_stop_id,
            'stop_title' => $item->stop?->title,
            'created_at' => $item->created_at->toIso8601String(),
        ];
    }

    private function findFeedback(int $id): TripFeedback
    {
        $item = TripFeedback::findOrFail($id);
        $this->find($item->trip_id);

        return $item;
    }

    private function find(int $id): Trip
    {
        $household = $this->user()->currentHousehold() ?? abort(404);

        return Trip::query()->visibleTo($this->user(), $household->id)->findOrFail($id);
    }
}
