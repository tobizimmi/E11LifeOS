<?php

namespace App\Support\Life;

use App\Models\Trip;
use App\Models\TripBooking;
use App\Models\TripStop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The day-by-day plan of a trip (like Lambus): every travel day with its bookings (flight, check-in,
 * check-out …) and planned entries, ideas without a day, a route per day and what comes next.
 */
class TripItinerary
{
    /** Pin colours of the days on the map (ideas are yellow), repeating after ten days. */
    public const DAY_COLORS = ['#ef4444', '#22c55e', '#8b5cf6', '#f97316', '#06b6d4', '#ec4899', '#84cc16', '#3b82f6', '#14b8a6', '#a855f7'];

    public const IDEA_COLOR = '#facc15';

    /** Bookings whose end is shown as its own event (check-out, car return). */
    private const END_EVENTS = ['hotel' => 'Check-out', 'car' => 'Car return'];

    /**
     * @return array{
     *     ideas: Collection<int, TripStop>,
     *     days: list<array{date: Carbon, number: int, bookings: list<array{booking: TripBooking, time: string, label: string}>, stops: Collection<int, TripStop>, route: ?string}>,
     *     next: ?array{title: string, icon: string, when: Carbon, day: ?int},
     *     planned_days: int,
     *     stop_costs: float
     * }
     */
    public static function build(Trip $trip): array
    {
        $trip->loadMissing(['stops', 'bookings']);
        $start = $trip->starts_on->copy()->startOfDay();
        $end = $trip->ends_on->copy()->startOfDay();
        $inTrip = fn (?Carbon $day) => $day !== null && $day->betweenIncluded($start, $end);
        $days = [];

        for ($date = $start->copy(), $number = 1; $date->lte($end); $date->addDay(), $number++) {
            $stops = $trip->stops->filter(fn (TripStop $stop) => $stop->day?->isSameDay($date))->values();
            $days[] = [
                'date' => $date->copy(),
                'number' => $number,
                'bookings' => self::bookingsOn($trip->bookings, $date),
                'stops' => $stops,
                'route' => self::routeUrl($stops),
            ];
        }

        return [
            'ideas' => $trip->stops->reject(fn (TripStop $stop) => $inTrip($stop->day))->values(),
            'days' => $days,
            'next' => self::next($trip, $start),
            'planned_days' => count(array_filter($days, fn (array $day) => $day['stops']->isNotEmpty() || $day['bookings'] !== [])),
            'stop_costs' => round((float) $trip->stops->sum('cost'), 2),
        ];
    }

    /**
     * Pins for the trip map: one group per day (colour, numbered in the planned order) plus the ideas.
     *
     * @param  array{ideas: Collection<int, TripStop>, days: list<array{date: Carbon, number: int, stops: Collection<int, TripStop>}>}  $plan
     * @return list<array{key: string, label: string, color: string, count: int, pins: list<array{id: int, title: string, icon: string, lat: float, lng: float, n: int}>}>
     */
    public static function mapGroups(array $plan): array
    {
        $kinds = TripStop::kinds();
        $pins = fn (Collection $stops) => $stops->filter->hasPosition()->values()
            ->map(fn (TripStop $stop, int $index) => ['id' => $stop->id, 'title' => $stop->title, 'icon' => $kinds[$stop->kind]['icon'] ?? '📍', 'lat' => (float) $stop->lat, 'lng' => (float) $stop->lng, 'n' => $index + 1])
            ->all();
        $groups = [['key' => 'ideas', 'label' => __('Ideas'), 'color' => self::IDEA_COLOR, 'count' => $plan['ideas']->count(), 'pins' => $pins($plan['ideas'])]];

        foreach ($plan['days'] as $day) {
            $groups[] = [
                'key' => $day['date']->toDateString(),
                'label' => __('Day :number', ['number' => $day['number']]).' · '.$day['date']->translatedFormat('D, j. M'),
                'color' => self::DAY_COLORS[($day['number'] - 1) % count(self::DAY_COLORS)],
                'count' => $day['stops']->count(),
                'pins' => $pins($day['stops']),
            ];
        }

        return $groups;
    }

    /**
     * Routes through the places of each day (days with at least two places on the map). "legs" holds the
     * way to each place from the one before, keyed by entry id; "failed" is set when no route was found.
     * Without $fetch only remembered routes are used, "pending" then lists the days still to calculate.
     *
     * @param  array{days: list<array{date: Carbon, stops: Collection<int, TripStop>}>}  $plan
     * @return array{days: array<string, array{duration: float, distance: float, line: list<array{0: float, 1: float}>, legs: array<int, array{duration: float, distance: float}>}>, failed: list<string>, pending: list<string>}
     */
    public static function dayRoutes(array $plan, string $mode, bool $fetch = false): array
    {
        $result = ['days' => [], 'failed' => [], 'pending' => []];

        foreach ($plan['days'] as $day) {
            $stops = $day['stops']->filter->hasPosition()->values();

            if ($stops->count() < 2) {
                continue;
            }

            $key = $day['date']->toDateString();
            $route = TripRoutes::route($stops->map(fn (TripStop $stop) => ['lat' => (float) $stop->lat, 'lng' => (float) $stop->lng])->all(), $mode, $fetch);

            if ($route === null) {
                $result['pending'][] = $key;
            } elseif ($route === false) {
                $result['failed'][] = $key;
            } else {
                $legs = [];

                foreach ($route['legs'] as $index => $leg) {
                    if ($stops->has($index + 1)) {
                        $legs[$stops[$index + 1]->id] = $leg;
                    }
                }

                $result['days'][$key] = ['duration' => $route['duration'], 'distance' => $route['distance'], 'line' => $route['line'], 'legs' => $legs];
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, TripBooking>  $bookings
     * @return list<array{booking: TripBooking, time: string, label: string}>
     */
    private static function bookingsOn(Collection $bookings, Carbon $date): array
    {
        $events = [];

        foreach ($bookings as $booking) {
            if ($booking->starts_at?->isSameDay($date)) {
                $events[] = ['booking' => $booking, 'time' => $booking->starts_at->format('H:i'), 'label' => $booking->kind === 'hotel' ? __('Check-in') : $booking->title];
            }

            if (isset(self::END_EVENTS[$booking->kind]) && $booking->ends_at?->isSameDay($date)) {
                $events[] = ['booking' => $booking, 'time' => $booking->ends_at->format('H:i'), 'label' => __(self::END_EVENTS[$booking->kind])];
            }
        }

        usort($events, fn (array $a, array $b) => $a['time'] <=> $b['time']);

        return $events;
    }

    /**
     * Google Maps route through the day's places in the planned order; null without places.
     *
     * @param  Collection<int, TripStop>  $stops
     */
    public static function routeUrl(Collection $stops): ?string
    {
        $places = $stops->reject(fn (TripStop $stop) => $stop->kind === 'note')
            ->map(fn (TripStop $stop) => trim((string) $stop->location) ?: ($stop->hasPosition() ? $stop->lat.','.$stop->lng : ''))
            ->filter()->values();

        if ($places->isEmpty()) {
            return null;
        }

        if ($places->count() === 1) {
            return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($places[0]);
        }

        $query = ['api' => '1', 'origin' => $places->first(), 'destination' => $places->last()];

        if ($places->count() > 2) {
            $query['waypoints'] = $places->slice(1, -1)->implode('|');
        }

        return 'https://www.google.com/maps/dir/?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The next booking or planned entry from now on.
     *
     * @return ?array{title: string, icon: string, when: Carbon, day: ?int}
     */
    private static function next(Trip $trip, Carbon $start): ?array
    {
        $now = now();
        $candidates = collect();

        foreach ($trip->bookings as $booking) {
            if ($booking->starts_at?->gte($now)) {
                $candidates->push(['title' => $booking->title, 'icon' => TripBooking::kinds()[$booking->kind]['icon'] ?? '📌', 'when' => $booking->starts_at]);
            }
        }

        foreach ($trip->stops as $stop) {
            if ($stop->day === null || $stop->done) {
                continue;
            }

            $when = $stop->day->copy()->setTimeFromTimeString($stop->time ?: '23:59');

            if ($when->gte($now)) {
                $candidates->push(['title' => $stop->title, 'icon' => TripStop::kinds()[$stop->kind]['icon'] ?? '📍', 'when' => $when]);
            }
        }

        $next = $candidates->sortBy(fn (array $candidate) => $candidate['when']->getTimestamp())->first();

        return $next ? $next + ['day' => $next['when']->gte($start) ? (int) $start->diffInDays($next['when']->copy()->startOfDay()) + 1 : null] : null;
    }
}
