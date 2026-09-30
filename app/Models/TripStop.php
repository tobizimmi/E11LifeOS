<?php

namespace App\Models;

use Database\Factories\TripStopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of the day-by-day trip plan: a sight, a restaurant, an activity … With no day it is an idea.
 *
 * @property int $id
 * @property int $trip_id
 * @property ?Carbon $day
 * @property ?string $time
 * @property ?string $end_time
 * @property string $kind
 * @property string $title
 * @property ?string $location
 * @property ?string $notes
 * @property ?float $cost
 * @property bool $done
 * @property int $position
 */
#[Fillable(['trip_id', 'day', 'time', 'end_time', 'kind', 'title', 'location', 'notes', 'cost', 'done', 'position', 'created_by'])]
class TripStop extends Model
{
    /** @use HasFactory<TripStopFactory> */
    use HasFactory;

    public const KINDS = ['sight', 'activity', 'food', 'accommodation', 'transport', 'shopping', 'note'];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'notes' => 'encrypted',
            'cost' => 'float',
            'done' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string, color: string}>
     */
    public static function kinds(): array
    {
        return [
            'sight' => ['label' => __('Sight'), 'icon' => '🏛️', 'color' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-200'],
            'activity' => ['label' => __('Activity'), 'icon' => '🎯', 'color' => 'bg-violet-100 text-violet-700 dark:bg-violet-900/50 dark:text-violet-200'],
            'food' => ['label' => __('Food & drink'), 'icon' => '🍽️', 'color' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/50 dark:text-orange-200'],
            'accommodation' => ['label' => __('Accommodation'), 'icon' => '🏨', 'color' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-200'],
            'transport' => ['label' => __('Getting there'), 'icon' => '🚗', 'color' => 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200'],
            'shopping' => ['label' => __('Shopping'), 'icon' => '🛍️', 'color' => 'bg-pink-100 text-pink-700 dark:bg-pink-900/50 dark:text-pink-200'],
            'note' => ['label' => __('Note'), 'icon' => '📝', 'color' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-200'],
        ];
    }

    public function mapsUrl(): ?string
    {
        $query = trim((string) ($this->location ?: null));

        return $query !== '' ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query) : null;
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
