<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user preferences for the AI meal planner (budget, store, diet, allergens and the rest).
 */
#[Fillable([
    'user_id', 'people', 'days_per_week', 'weekly_budget', 'postal_code', 'diet', 'meal_prep',
    'meals', 'supermarkets', 'allergens', 'dish_types', 'cuisines', 'disliked_ingredients', 'appliances',
])]
class MealPlanPreference extends Model
{
    protected function casts(): array
    {
        return [
            'people' => 'integer',
            'days_per_week' => 'integer',
            'weekly_budget' => 'decimal:2',
            'meal_prep' => 'boolean',
            'meals' => 'array',
            'supermarkets' => 'array',
            'allergens' => 'array',
            'dish_types' => 'array',
            'cuisines' => 'array',
            'disliked_ingredients' => 'array',
            'appliances' => 'array',
        ];
    }

    /**
     * The preferences of a user, with sensible defaults for a fresh record (not yet saved).
     */
    public static function for(User $user): self
    {
        return self::firstOrNew(['user_id' => $user->id], [
            'people' => 2,
            'days_per_week' => 5,
            'meal_prep' => false,
            'meals' => ['dinner'],
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
