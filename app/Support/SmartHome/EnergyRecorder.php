<?php

namespace App\Support\SmartHome;

use App\Models\Meter;
use App\Models\MeterReading;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeDay;
use App\Models\SmartHomeEntity;
use App\Models\SmartHomeReading;
use App\Models\Vehicle;
use App\Models\VehicleLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Records evcc power values and numeric Home Assistant sensors every five minutes, adds them up
 * to the energy of each day and hands the results to the other modules: meter readings ("At home
 * › Consumption") and charging entries for the vehicles.
 */
class EnergyRecorder
{
    /** evcc values recorded as curves (watts or percent). */
    public const METRICS = ['pv', 'home', 'grid', 'battery', 'batterySoc', 'charge', 'vehicleSoc'];

    private const KEEP_DAYS = 35;

    /**
     * Stores the current values of all households (every five minutes).
     */
    public function record(Carbon $now): void
    {
        SmartHomeConnection::where('type', SmartHomeConnection::EVCC)->get()->each(function (SmartHomeConnection $connection) use ($now) {
            try {
                $state = $connection->evcc()->state();
            } catch (Throwable) {
                return;
            }

            $values = [
                'pv' => $state['pv'], 'home' => $state['home'], 'grid' => $state['grid'], 'battery' => $state['battery'], 'batterySoc' => $state['batterySoc'],
                'charge' => collect($state['loadpoints'])->sum('power'),
                'vehicleSoc' => $state['loadpoints'][0]['vehicleSoc'] ?? null,
            ];

            foreach ($values as $metric => $value) {
                if ($value !== null) {
                    SmartHomeReading::create(['household_id' => $connection->household_id, 'metric' => 'evcc.'.$metric, 'value' => (float) $value, 'recorded_at' => $now]);
                }
            }
        });

        SmartHomeEntity::query()->whereNotNull('state')->get()
            ->filter(fn (SmartHomeEntity $entity) => is_numeric($entity->state))
            ->each(fn (SmartHomeEntity $entity) => SmartHomeReading::create(['household_id' => $entity->household_id, 'metric' => 'ha:'.$entity->entity_id, 'value' => (float) $entity->state, 'recorded_at' => $now]));
    }

    /**
     * Energy of a day from the recorded power values (kWh): PV, consumption, grid import and
     * feed-in, charged into the car.
     *
     * @return array{pv_kwh: ?float, home_kwh: ?float, import_kwh: ?float, export_kwh: ?float, charged_kwh: ?float}
     */
    public static function energy(int $householdId, Carbon $day): array
    {
        $readings = SmartHomeReading::where('household_id', $householdId)
            ->whereIn('metric', ['evcc.pv', 'evcc.home', 'evcc.grid', 'evcc.charge'])
            ->whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('recorded_at')->get()->groupBy('metric');

        $integrate = function (string $metric, ?callable $map = null) use ($readings): ?float {
            /** @var Collection<int, SmartHomeReading>|null $points */
            $points = $readings->get($metric);

            if (! $points || $points->isEmpty()) {
                return null;
            }

            $kwh = 0.0;
            $previous = null;

            foreach ($points as $point) {
                // Each value counts until the next one, at most ten minutes (gaps count as nothing).
                if ($previous) {
                    $hours = min(600, $previous->recorded_at->diffInSeconds($point->recorded_at)) / 3600;
                    $kwh += ($map ? $map($previous->value) : $previous->value) * $hours / 1000;
                }

                $previous = $point;
            }

            return round($kwh + ($map ? $map($previous->value) : $previous->value) * (5 / 60) / 1000, 2);
        };

        return [
            'pv_kwh' => $integrate('evcc.pv', fn (float $watts) => max(0, $watts)),
            'home_kwh' => $integrate('evcc.home', fn (float $watts) => max(0, $watts)),
            'import_kwh' => $integrate('evcc.grid', fn (float $watts) => max(0, $watts)),
            'export_kwh' => $integrate('evcc.grid', fn (float $watts) => max(0, -$watts)),
            'charged_kwh' => $integrate('evcc.charge', fn (float $watts) => max(0, $watts)),
        ];
    }

    /**
     * Closes a day for all households: daily energy, meter readings, charging entries, and removes
     * old curves.
     */
    public function closeDay(Carbon $day): void
    {
        $households = SmartHomeReading::whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])->distinct()->pluck('household_id');

        foreach ($households as $householdId) {
            $energy = self::energy($householdId, $day);

            if (array_filter($energy, fn ($value) => $value !== null) !== []) {
                $existing = SmartHomeDay::where('household_id', $householdId)->whereDate('date', $day->toDateString())->first();
                $existing ? $existing->update($energy) : SmartHomeDay::create(['household_id' => $householdId, 'date' => $day->toDateString()] + $energy);
                $this->logCharging($householdId, $day, (float) $energy['charged_kwh']);
            }
        }

        $this->recordMeters($day);
        SmartHomeReading::where('recorded_at', '<', $day->copy()->subDays(self::KEEP_DAYS))->delete();
    }

    /**
     * A charging entry in the log of the car assigned to the charging point (once per day).
     */
    private function logCharging(int $householdId, Carbon $day, float $kwh): void
    {
        $connection = SmartHomeConnection::where('household_id', $householdId)->where('type', SmartHomeConnection::EVCC)->orderBy('id')->first();
        $vehicleId = (int) (($connection->vehicle_map ?? [])[1] ?? 0);
        $vehicle = $vehicleId ? Vehicle::where('household_id', $householdId)->find($vehicleId) : null;

        if (! $vehicle || $kwh < 0.1) {
            return;
        }

        $price = Meter::where('household_id', $householdId)->where('kind', 'electricity')->whereNotNull('price_per_unit')->value('price_per_unit');
        $note = __('Charged at home (evcc)');

        $values = ['quantity' => round($kwh, 2), 'amount' => $price !== null ? round($kwh * (float) $price, 2) : null];
        // whereDate: SQLite stores date casts with a time, so a plain updateOrCreate would miss the row.
        $log = VehicleLog::where('vehicle_id', $vehicle->id)->where('kind', 'fuel')->where('notes', $note)->whereDate('date', $day->toDateString())->first();
        $log ? $log->update($values) : VehicleLog::create(['vehicle_id' => $vehicle->id, 'kind' => 'fuel', 'date' => $day->toDateString(), 'notes' => $note] + $values);
    }

    /**
     * Meter readings from Home Assistant sensors assigned to a meter (state at the end of the day).
     */
    private function recordMeters(Carbon $day): void
    {
        SmartHomeEntity::whereNotNull('meter_id')->with('connection')->get()->each(function (SmartHomeEntity $entity) use ($day) {
            if (! is_numeric($entity->state) || ! Meter::where('household_id', $entity->household_id)->whereKey($entity->meter_id)->exists()) {
                return;
            }

            if (! MeterReading::where('meter_id', $entity->meter_id)->whereDate('read_on', $day->toDateString())->exists()) {
                MeterReading::create(['meter_id' => $entity->meter_id, 'read_on' => $day->toDateString(), 'value' => (float) $entity->state]);
            }
        });
    }

    /**
     * The newest recorded evcc values (at most 15 minutes old), e.g. ['pv' => 4200.0, 'vehicleSoc' => 61.0].
     *
     * @return array<string, float>
     */
    public static function latest(int $householdId): array
    {
        return SmartHomeReading::where('household_id', $householdId)->where('metric', 'like', 'evcc.%')
            ->where('recorded_at', '>=', now()->subMinutes(15))->orderByDesc('recorded_at')->get()
            ->unique('metric')->mapWithKeys(fn (SmartHomeReading $reading) => [substr($reading->metric, 5) => $reading->value])->all();
    }

    /**
     * Curves of a day for the chart: PV and consumption in kW per five minutes.
     *
     * @return list<array{label: string, value: float, compare: ?float}>
     */
    public static function dayCurve(int $householdId, Carbon $day): array
    {
        return SmartHomeReading::where('household_id', $householdId)->whereIn('metric', ['evcc.pv', 'evcc.home'])
            ->whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('recorded_at')->get()
            ->groupBy(fn (SmartHomeReading $reading) => $reading->recorded_at->format('H:i'))
            ->map(fn (Collection $points, string $time) => [
                'label' => $time,
                'value' => round(max(0, (float) $points->firstWhere('metric', 'evcc.pv')?->value) / 1000, 2),
                'compare' => ($home = $points->firstWhere('metric', 'evcc.home')) ? round($home->value / 1000, 2) : null,
            ])->values()->all();
    }
}
