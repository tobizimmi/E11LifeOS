<?php

namespace App\Support\SmartHome;

use App\Models\SmartHomeDay;
use Illuminate\Support\Carbon;

/**
 * Energy of a span of days in a few lines, for the morning briefing and the weekly review.
 */
class EnergySummary
{
    /**
     * @return list<string>
     */
    public static function lines(int $householdId, Carbon $from, Carbon $until): array
    {
        $days = SmartHomeDay::where('household_id', $householdId)
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $until->toDateString())->get();

        if ($days->isEmpty()) {
            return [];
        }

        $kwh = fn (float $value) => number_format($value, 1, ',', '.').' kWh';
        $lines = [__('PV :pv · consumption :home', ['pv' => $kwh((float) $days->sum('pv_kwh')), 'home' => $kwh((float) $days->sum('home_kwh'))])];
        $self = (float) $days->sum('home_kwh') + (float) $days->sum('charged_kwh');

        if ($self > 0 && $days->whereNotNull('import_kwh')->isNotEmpty()) {
            $lines[] = __('Self-sufficiency :percent % · feed-in :export', ['percent' => (int) round(max(0, 1 - $days->sum('import_kwh') / $self) * 100), 'export' => $kwh((float) $days->sum('export_kwh'))]);
        }

        if ($days->sum('charged_kwh') >= 0.1) {
            $lines[] = __('Charged into the car: :kwh', ['kwh' => $kwh((float) $days->sum('charged_kwh'))]);
        }

        return $lines;
    }
}
