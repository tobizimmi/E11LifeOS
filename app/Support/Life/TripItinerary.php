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
        $places = $stops->reject(fn (TripStop $stop) => $stop->kind === 'note')->pluck('location')->map(fn (?string $location) => trim((string) $location))->filter()->values();

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
