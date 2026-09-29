<?php

namespace App\Support\Life;

use Illuminate\Support\Carbon;

/**
 * Library of recurring maintenance jobs at home with sensible intervals; the member picks what fits
 * the flat or house and gets reminded when a job is due.
 */
class HomeMaintenance
{
    /**
     * @return array<string, array{title: string, icon: string, interval: int, month: ?int, hint: string}>
     */
    public static function library(): array
    {
        return [
            'heating_service' => ['title' => __('Heating service'), 'icon' => '🔥', 'interval' => 12, 'month' => 9, 'hint' => __('Before the heating season; keeps efficiency and warranty')],
            'chimney_sweep' => ['title' => __('Chimney sweep'), 'icon' => '🧹', 'interval' => 12, 'month' => null, 'hint' => __('Required inspection of heating and fireplace')],
            'bleed_radiators' => ['title' => __('Bleed radiators'), 'icon' => '🌡️', 'interval' => 12, 'month' => 10, 'hint' => __('Gurgling radiators heat badly')],
            'smoke_detectors' => ['title' => __('Test smoke detectors'), 'icon' => '🚨', 'interval' => 12, 'month' => null, 'hint' => __('Press the test button, change batteries if needed')],
            'gutters' => ['title' => __('Clean gutters'), 'icon' => '🍂', 'interval' => 12, 'month' => 11, 'hint' => __('After the leaves have fallen')],
            'outdoor_tap' => ['title' => __('Drain outdoor taps'), 'icon' => '🚰', 'interval' => 12, 'month' => 11, 'hint' => __('Before the first frost')],
            'roof_check' => ['title' => __('Check roof and facade'), 'icon' => '🏠', 'interval' => 24, 'month' => 4, 'hint' => __('Loose tiles, cracks, moss')],
            'window_seals' => ['title' => __('Care for windows and seals'), 'icon' => '🪟', 'interval' => 12, 'month' => 4, 'hint' => __('Oil fittings, treat seals')],
            'ventilation_filter' => ['title' => __('Change ventilation filters'), 'icon' => '🌬️', 'interval' => 6, 'month' => null, 'hint' => __('Ventilation system, air conditioning, air purifier')],
            'hood_filter' => ['title' => __('Clean cooker hood filter'), 'icon' => '🍳', 'interval' => 3, 'month' => null, 'hint' => __('Grease filters in the dishwasher')],
            'dishwasher' => ['title' => __('Clean dishwasher filter'), 'icon' => '🍽️', 'interval' => 1, 'month' => null, 'hint' => __('Sieve out and rinse')],
            'washing_machine' => ['title' => __('Clean washing machine'), 'icon' => '🧺', 'interval' => 3, 'month' => null, 'hint' => __('Hot wash, clean fluff filter and detergent drawer')],
            'descale' => ['title' => __('Descale kettle and coffee machine'), 'icon' => '☕', 'interval' => 3, 'month' => null, 'hint' => ''],
            'fridge' => ['title' => __('Defrost fridge and freezer'), 'icon' => '🧊', 'interval' => 6, 'month' => null, 'hint' => __('Ice costs electricity')],
            'water_filter' => ['title' => __('Change water filter'), 'icon' => '💧', 'interval' => 6, 'month' => null, 'hint' => __('Backwash filter at the house connection')],
            'fire_extinguisher' => ['title' => __('Inspect fire extinguisher'), 'icon' => '🧯', 'interval' => 24, 'month' => null, 'hint' => __('Recommended every two years')],
            'pv_check' => ['title' => __('Check PV system'), 'icon' => '☀️', 'interval' => 12, 'month' => 5, 'hint' => __('Compare yield, clean modules if necessary')],
            'terrace' => ['title' => __('Oil wooden terrace'), 'icon' => '🪵', 'interval' => 12, 'month' => 5, 'hint' => ''],
            'garden_winter' => ['title' => __('Winterise the garden'), 'icon' => '🌱', 'interval' => 12, 'month' => 10, 'hint' => __('Store the hose, protect plants, clean tools')],
        ];
    }

    /**
     * First due date: the preferred month (this year or next), otherwise in a month.
     *
     * @param  array{title: string, icon: string, interval: int, month: ?int, hint: string}  $preset
     */
    public static function firstDueOn(array $preset): Carbon
    {
        if ($preset['month'] !== null) {
            $date = today()->setDate(today()->year, $preset['month'], 1);

            return $date->lt(today()->startOfMonth()) ? $date->addYear() : $date;
        }

        return today()->addMonthsNoOverflow(min($preset['interval'], 1));
    }
}
