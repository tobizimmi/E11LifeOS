<?php

namespace App\Support\Life;

use App\Models\Meter;
use App\Models\MeterReading;
use Illuminate\Support\Collection;

/**
 * Consumption of a meter from its readings: use per day between two readings, the projection for a
 * year, the yearly costs from tariff and base fee and whether the monthly advance payment fits.
 */
class MeterStats
{
    /**
     * Readings further back than this do not count for the projection.
     */
    private const WINDOW_DAYS = 400;

    /**
     * @return array{latest: ?float, periods: list<array{from: string, to: string, days: int, consumption: float, per_day: float}>, yearly: ?float, yearly_cost: ?float, advances: ?float, balance: ?float, suggested_advance: ?float}
     */
    public static function for(Meter $meter): array
    {
        /** @var Collection<int, MeterReading> $readings */
        $readings = ($meter->relationLoaded('readings') ? $meter->readings : $meter->readings()->get())->sortBy('read_on')->values();
        $periods = [];

        foreach ($readings as $index => $reading) {
            if ($index === 0) {
                continue;
            }

            $previous = $readings[$index - 1];
            $days = (int) $previous->read_on->diffInDays($reading->read_on);

            if ($days > 0) {
                $consumption = round($reading->value - $previous->value, 3);
                $periods[] = ['from' => $previous->read_on->toDateString(), 'to' => $reading->read_on->toDateString(), 'days' => $days, 'consumption' => $consumption, 'per_day' => round($consumption / $days, 3)];
            }
        }

        $recent = $readings->filter(fn (MeterReading $reading) => $reading->read_on->gte(today()->subDays(self::WINDOW_DAYS)))->values();
        $yearly = null;

        if ($recent->count() >= 2 && ($span = (int) $recent->first()->read_on->diffInDays($recent->last()->read_on)) >= 14) {
            $yearly = round(($recent->last()->value - $recent->first()->value) / $span * 365, 1);
        }

        $yearlyCost = $yearly !== null && $meter->price_per_unit !== null && $meter->kind !== 'pv'
            ? round($yearly * $meter->price_per_unit + (float) $meter->base_fee_monthly * 12, 2)
            : null;
        $advances = $meter->monthly_advance !== null ? round($meter->monthly_advance * 12, 2) : null;

        return [
            'latest' => $readings->last()?->value,
            'periods' => array_reverse($periods),
            'yearly' => $yearly,
            'yearly_cost' => $yearlyCost,
            'advances' => $advances,
            'balance' => $yearlyCost !== null && $advances !== null ? round($advances - $yearlyCost, 2) : null,
            'suggested_advance' => $yearlyCost !== null ? (float) ceil($yearlyCost / 12) : null,
        ];
    }
}
