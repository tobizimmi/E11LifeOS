<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One card of a Smart Home dashboard: a device (shown as tile, button, value, gauge, slider,
 * history or row), a heading or the energy flow of evcc – in one of four sizes.
 *
 * @property array{min?: float, max?: float, hours?: int}|null $options
 * @property-read SmartHomeEntity|null $entity
 */
#[Fillable(['smart_home_dashboard_id', 'smart_home_entity_id', 'kind', 'display', 'size', 'title', 'options', 'position'])]
class SmartHomeDashboardCard extends Model
{
    public const KINDS = ['entity', 'heading', 'energy'];

    public const SIZES = ['small', 'medium', 'large', 'full'];

    public const HISTORY_HOURS = [6, 24, 48, 168];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SmartHomeEntity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(SmartHomeEntity::class, 'smart_home_entity_id');
    }

    /**
     * Labels of all displays.
     *
     * @return array<string, string>
     */
    public static function displayLabels(): array
    {
        return [
            'tile' => __('Tile'),
            'button' => __('Large button'),
            'value' => __('Large value'),
            'gauge' => __('Gauge'),
            'slider' => __('Slider'),
            'history' => __('History chart'),
            'row' => __('Compact row'),
        ];
    }

    /**
     * Labels of the sizes.
     *
     * @return array<string, string>
     */
    public static function sizeLabels(): array
    {
        return [
            'small' => __('Small'),
            'medium' => __('Wide'),
            'large' => __('Large'),
            'full' => __('Full width'),
        ];
    }

    /**
     * Displays that make sense for a device: buttons only for what can be switched, gauges and
     * charts only for numbers, sliders only for lights, heating and covers.
     *
     * @return list<string>
     */
    public static function displaysFor(SmartHomeEntity $entity): array
    {
        $numeric = is_numeric($entity->state);
        $pressable = $entity->isSwitchable() || $entity->isRunnable() || in_array($entity->domain(), ['lock', 'cover', 'valve', 'alarm_control_panel'], true);

        return array_values(array_filter([
            'tile',
            $pressable ? 'button' : null,
            'value',
            $numeric ? 'gauge' : null,
            in_array($entity->domain(), ['light', 'climate', 'cover'], true) ? 'slider' : null,
            $numeric ? 'history' : null,
            'row',
        ]));
    }

    /**
     * What a newly added device shows by default.
     */
    public static function defaultDisplay(SmartHomeEntity $entity): string
    {
        return match (true) {
            in_array($entity->domain(), ['sensor', 'input_number', 'number'], true) && is_numeric($entity->state) => 'value',
            default => 'tile',
        };
    }

    /**
     * Grid classes of the size (2 columns on phones, 4 from tablets on).
     */
    public function sizeClasses(): string
    {
        return match ($this->size) {
            'medium' => 'col-span-2',
            'large' => 'col-span-2 row-span-2 sm:min-h-64',
            'full' => 'col-span-full',
            default => 'col-span-1',
        };
    }
}
