<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mode of the household such as "Holiday mode" or "Guests": switched by hand or by an
 * automation, and itself a trigger and condition for automations.
 */
#[Fillable(['household_id', 'key', 'name', 'icon', 'active', 'changed_at'])]
class HouseholdMode extends Model
{
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'changed_at' => 'datetime',
        ];
    }

    /**
     * Modes every household starts with.
     *
     * @return array<string, array{name: string, icon: string}>
     */
    public static function defaults(): array
    {
        return [
            'vacation' => ['name' => __('Holiday mode'), 'icon' => '🏖️'],
            'away' => ['name' => __('Nobody at home'), 'icon' => '🚪'],
            'guests' => ['name' => __('Guests'), 'icon' => '🧳'],
            'night' => ['name' => __('Night'), 'icon' => '🌙'],
        ];
    }

    /**
     * The household's modes, the default ones created on first use.
     *
     * @return Collection<int, HouseholdMode>
     */
    public static function forHousehold(int $householdId): Collection
    {
        foreach (self::defaults() as $key => $mode) {
            self::firstOrCreate(['household_id' => $householdId, 'key' => $key], $mode);
        }

        return self::where('household_id', $householdId)->orderBy('id')->get();
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
