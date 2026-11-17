<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Trip;
use App\Models\TripFeedback;
use App\Models\TripStop;
use App\Support\Life\PlaceFinder;
use App\Support\Life\TripDayPlanner;
use App\Support\Life\TripFeedbackBox;
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
