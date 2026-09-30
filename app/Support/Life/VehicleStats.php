<?php

namespace App\Support\Life;

use App\Models\Vehicle;
use App\Models\VehicleLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Figures of a vehicle: odometer, kilometres and costs of the last twelve months (log plus tax and
 * insurance), cost per month and kilometre, average consumption, logbook totals per purpose and the
 * next inspection, service and tyre change.
 */
class VehicleStats
{
    /**
     * Flat rate per business kilometre for the logbook (EÜR / income tax).
     */
    public const BUSINESS_RATE = 0.30;

    /**
     * @return array{odometer: ?int, km_12m: int, costs_12m: float, monthly: float, per_km: ?float, consumption: ?float, unit: string, by_kind: array<string, float>, trips: array<string, int>, business_allowance: float, next: list<array{kind: string, label: string, date: string, overdue: bool}>}
     */
    public static function for(Vehicle $vehicle): array
    {
        $logs = $vehicle->relationLoaded('logs') ? $vehicle->logs : $vehicle->logs()->get();
        $since = today()->subYear();
        $recent = $logs->filter(fn (VehicleLog $log) => $log->date->gt($since));

        $odometer = $logs->max('odometer') ?? $vehicle->odometer_start;
        $before = $logs->filter(fn (VehicleLog $log) => $log->date->lte($since) && $log->odometer)->max('odometer')
            ?? $recent->filter(fn (VehicleLog $log) => $log->odometer)->min('odometer')
            ?? $vehicle->odometer_start;
        $km = $odometer && $before ? max(0, $odometer - $before) : 0;

        $byKind = $recent->where('kind', '!=', 'trip')->groupBy('kind')->map(fn (Collection $group) => round((float) $group->sum('amount'), 2))->all();
        $fixed = (float) $vehicle->tax_yearly + (float) $vehicle->insurance_yearly;
        $costs = round(array_sum($byKind) + $fixed, 2);

        $trips = $logs->where('kind', 'trip')->filter(fn (VehicleLog $log) => $log->date->year === today()->year)
            ->groupBy('purpose')->map(fn (Collection $group) => (int) $group->sum('distance_km'))->all();

        return [
            'odometer' => $odometer,
            'km_12m' => $km,
            'costs_12m' => $costs,
            'monthly' => round($costs / 12, 2),
            'per_km' => $km > 0 ? round($costs / $km, 2) : null,
            'consumption' => self::consumption($logs),
            'unit' => $vehicle->isElectric() ? 'kWh' : 'l',
            'by_kind' => $byKind,
            'trips' => $trips,
            'business_allowance' => round(($trips['business'] ?? 0) * self::BUSINESS_RATE, 2),
            'next' => self::nextDates($vehicle),
        ];
    }

    /**
     * Average consumption per 100 km from the refuellings with odometer and quantity (the first fill only marks the start).
     *
     * @param  Collection<int, VehicleLog>  $logs
     */
    public static function consumption(Collection $logs): ?float
    {
        $fills = $logs->where('kind', 'fuel')->filter(fn (VehicleLog $log) => $log->odometer && $log->quantity)->sortBy('odometer')->values();

        if ($fills->count() < 2) {
            return null;
        }

        $distance = $fills->last()->odometer - $fills->first()->odometer;

        return $distance > 0 ? round($fills->skip(1)->sum('quantity') / $distance * 100, 1) : null;
    }

    /**
     * @return list<array{kind: string, label: string, date: string, overdue: bool}>
     */
    public static function nextDates(Vehicle $vehicle): array
    {
        $dates = [];

        if ($vehicle->inspection_due) {
            $dates[] = ['kind' => 'inspection', 'label' => __('Inspection (TÜV)'), 'date' => $vehicle->inspection_due->toDateString(), 'overdue' => $vehicle->inspection_due->lt(today())];
        }

        if ($vehicle->service_due) {
            $dates[] = ['kind' => 'service', 'label' => __('Service'), 'date' => $vehicle->service_due->toDateString(), 'overdue' => $vehicle->service_due->lt(today())];
        }

        if ($vehicle->tyre_reminders) {
            $change = self::nextTyreChange(today());
            $dates[] = ['kind' => 'tyres', 'label' => $change['label'], 'date' => $change['date']->toDateString(), 'overdue' => false];
        }

        usort($dates, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $dates;
    }

    /**
     * "From O to O": winter tyres in mid-October, summer tyres at Easter (1 April as a fixed day).
     *
     * @return array{date: Carbon, label: string}
     */
    public static function nextTyreChange(Carbon $from): array
    {
        $candidates = [];

        foreach ([$from->year, $from->year + 1] as $year) {
            $candidates[] = ['date' => Carbon::create($year, 4, 1), 'label' => __('Change to summer tyres')];
            $candidates[] = ['date' => Carbon::create($year, 10, 15), 'label' => __('Change to winter tyres')];
        }

        return collect($candidates)->filter(fn (array $candidate) => $candidate['date']->gte($from->copy()->startOfDay()))->sortBy('date')->first();
    }

    /**
     * Tyre changes between two days.
     *
     * @return list<array{date: Carbon, label: string}>
     */
    public static function tyreChangesBetween(Carbon $from, Carbon $until): array
    {
        $changes = [];

        for ($year = $from->year; $year <= $until->year; $year++) {
            foreach ([[4, 1, __('Change to summer tyres')], [10, 15, __('Change to winter tyres')]] as [$month, $day, $label]) {
                $date = Carbon::create($year, $month, $day);

                if ($date->between($from->copy()->startOfDay(), $until->copy()->endOfDay())) {
                    $changes[] = ['date' => $date, 'label' => $label];
                }
            }
        }

        return $changes;
    }
}
