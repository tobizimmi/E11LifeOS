<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\ShoppingListEntry;
use App\Support\Addons;
use App\Support\SmartCapture;
use App\Support\SmartHome\EnergyRecorder;
use App\Support\TodayView;
use App\Support\Weather;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile API for the start screen and the home screen widgets: "my day", the one input for
 * everything and a compact summary for widgets.
 */
class TodayController extends ApiController
{
    /**
     * "My day": timeline of today, what comes next, overdue and done tasks, tomorrow in the evening.
     */
    public function today(): JsonResponse
    {
        $user = $this->user();
        $today = TodayView::for($user, $this->household()->id);

        return $this->ok([
            'mode' => $today['mode'],
            'title' => $today['title'],
            'next' => $today['next'] ? $this->event($today['next']) : null,
            'minutes_until_next' => $today['minutesUntilNext'],
            'overdue' => $today['overdue'],
            'done' => $today['done'],
            'events' => array_map(fn (array $event) => $this->event($event) + ['past' => $event['past']], $today['events']),
            'tomorrow' => array_map(fn (array $event) => $this->event($event), $today['tomorrow']),
            'weather' => $this->weather(),
        ]);
    }

    /**
     * One line of text, filed where it belongs (shopping list, expense, device, task or inbox).
     */
    public function capture(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:500'], 'private' => ['sometimes', 'boolean']]);
        $household = $this->household();
        $kind = SmartCapture::preview($this->user(), $household->id, $data['text']);
        $message = SmartCapture::capture($this->user(), $household->id, $data['text'], (bool) ($data['private'] ?? false));
        abort_if($message === null, 422, __('Please enter a title.'));

        return $this->ok(['kind' => $kind['kind'], 'label' => $kind['label'], 'message' => $message], 201);
    }

    /**
     * Small summary for home screen widgets (one request, few fields).
     */
    public function widgets(): JsonResponse
    {
        $user = $this->user();
        $household = $this->household();
        $today = TodayView::for($user, $household->id);
        $energy = $user->canUseAddon(Addons::SMART_HOME) ? EnergyRecorder::latest($household->id) : [];

        return $this->ok([
            'next' => $today['next'] ? $this->event($today['next']) : null,
            'minutes_until_next' => $today['minutesUntilNext'],
            'events_today' => count($today['events']),
            'overdue' => $today['overdue'],
            'shopping_open' => ShoppingListEntry::where('household_id', $household->id)->where('checked', false)->count(),
            'energy' => $energy === [] ? null : ['pv' => $energy['pv'] ?? null, 'home' => $energy['home'] ?? null, 'grid' => $energy['grid'] ?? null, 'battery_soc' => $energy['batterySoc'] ?? null],
            'weather' => $this->weather(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{date: string, time: ?string, source: string, title: string, url: string, task_id: ?int, overdue: bool}
     */
    private function event(array $event): array
    {
        return ['date' => $event['date'], 'time' => $event['time'], 'source' => $event['source'], 'title' => $event['title'], 'url' => $event['url'], 'task_id' => $event['task_id'], 'overdue' => $event['overdue']];
    }

    /**
     * @return array{icon: string, label: string, min: mixed, max: mixed, rain: mixed}|null
     */
    private function weather(): ?array
    {
        $user = $this->user();

        if ($user->weather_latitude === null || $user->weather_longitude === null) {
            return null;
        }

        $weather = Weather::today($user->weather_latitude, $user->weather_longitude);

        return $weather ? ['icon' => $weather['icon'], 'label' => $weather['label'], 'min' => $weather['min'], 'max' => $weather['max'], 'rain' => $weather['rain']] : null;
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
