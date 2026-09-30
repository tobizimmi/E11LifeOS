<?php

namespace App\Support\Life;

use App\Models\TripStop;
use Illuminate\Support\Collection;

/**
 * Plans one travel day: the shortest order through its places (the first place stays the start,
 * e.g. the hotel) and the times of the day (stay at each place plus the way to the next one).
 */
class TripDayPlanner
{
    /** Usual stay in minutes per type of entry when no end time is given. */
    public const STAY_MINUTES = ['sight' => 90, 'activity' => 120, 'food' => 60, 'shopping' => 45, 'accommodation' => 0, 'transport' => 0, 'note' => 0];

    /** Rough speed in km/h (with detours) when no route could be calculated. */
    private const SPEED = ['foot' => 4, 'bike' => 13, 'car' => 35];

    /**
     * The entries in the shortest order: the first one stays, the others follow by distance
     * (nearest neighbour, then improved by swapping sections). Entries without a place come last.
     *
     * @param  Collection<int, TripStop>  $stops  in the current order
     * @return list<int> entry ids
     */
    public static function shortestOrder(Collection $stops): array
    {
        $placed = $stops->filter->hasPosition()->values();
        $unplaced = $stops->reject->hasPosition()->pluck('id')->all();

        if ($placed->count() < 3) {
            return [...$placed->pluck('id')->all(), ...$unplaced];
        }

        $points = $placed->map(fn (TripStop $stop) => [(float) $stop->lat, (float) $stop->lng])->all();
        $count = count($points);
        $route = [0];
        $open = range(1, $count - 1);

        while ($open !== []) {
            $last = $points[end($route)];
            usort($open, fn (int $a, int $b) => self::km($last, $points[$a]) <=> self::km($last, $points[$b]));
            $route[] = array_shift($open);
        }

        // 2-opt: reverse a section while that makes the whole way shorter (open path, start fixed).
        do {
            $improved = false;

            for ($i = 1; $i < $count - 1; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $before = self::km($points[$route[$i - 1]], $points[$route[$i]]) + ($j + 1 < $count ? self::km($points[$route[$j]], $points[$route[$j + 1]]) : 0);
                    $after = self::km($points[$route[$i - 1]], $points[$route[$j]]) + ($j + 1 < $count ? self::km($points[$route[$i]], $points[$route[$j + 1]]) : 0);

                    if ($after + 0.0001 < $before) {
                        array_splice($route, $i, $j - $i + 1, array_reverse(array_slice($route, $i, $j - $i + 1)));
                        $improved = true;
                    }
                }
            }
        } while ($improved);

        return [...array_map(fn (int $index) => $placed[$index]->id, $route), ...$unplaced];
    }

    /**
     * Times for the day: starts at the first entry's time (or 9:00), each entry keeps its length
     * (or the usual stay for its type), the way to the next place is added (rounded to 5 minutes).
     *
     * @param  Collection<int, TripStop>  $stops  in the planned order
     * @param  array<int, array{duration: float, distance: float}>  $legs  way to each entry, keyed by id
     * @return array<int, array{time: string, end_time: ?string}> keyed by entry id
     */
    public static function schedule(Collection $stops, array $legs, string $mode): array
    {
        $clock = self::minutes($stops->first()?->time) ?? 9 * 60;
        $times = [];
        $previous = null;

        foreach ($stops as $stop) {
            if ($previous !== null) {
                $travel = isset($legs[$stop->id]) ? $legs[$stop->id]['duration'] / 60 : self::estimate($previous, $stop, $mode);
                $clock = (int) (ceil(($clock + $travel) / 5) * 5);
            }

            $start = self::minutes($stop->time);
            $end = self::minutes($stop->end_time);
            $stay = $start !== null && $end !== null && $end > $start ? $end - $start : (self::STAY_MINUTES[$stop->kind] ?? 60);

            if ($clock >= 24 * 60) {
                break; // The rest does not fit into the day any more.
            }

            $times[$stop->id] = ['time' => self::format($clock), 'end_time' => $stay > 0 ? self::format(min($clock + $stay, 24 * 60 - 1)) : null];
            $clock += $stay;
            $previous = $stop->kind === 'note' ? $previous : $stop;
        }

        return $times;
    }

    /**
     * Minutes on the way between two entries without a calculated route (0 without places).
     */
    private static function estimate(TripStop $from, TripStop $to, string $mode): float
    {
        if (! $from->hasPosition() || ! $to->hasPosition()) {
            return 0;
        }

        return self::km([(float) $from->lat, (float) $from->lng], [(float) $to->lat, (float) $to->lng]) * 1.3 / (self::SPEED[$mode] ?? 4) * 60;
    }

    /**
     * Distance as the crow flies in km.
     *
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     */
    private static function km(array $a, array $b): float
    {
        $lat = deg2rad($b[0] - $a[0]);
        $lng = deg2rad($b[1] - $a[1]);
        $h = sin($lat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($lng / 2) ** 2;

        return 12742 * asin(min(1, sqrt($h)));
    }

    private static function minutes(?string $time): ?int
    {
        return $time && preg_match('/^(\d{1,2}):(\d{2})/', $time, $match) ? (int) $match[1] * 60 + (int) $match[2] : null;
    }

    private static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
