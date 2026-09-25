<?php

namespace App\Models;

use Database\Factories\MealPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection as SupportCollection;

#[Fillable(['household_id', 'created_by', 'visibility', 'date', 'meal_type', 'recipe_id', 'title', 'servings', 'note', 'legacy_id'])]
class MealPlan extends Model
{
    /** @use HasFactory<MealPlanFactory> */
    use HasFactory;

    public const MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'snack'];

    protected $attributes = [
        'visibility' => Item::VISIBILITY_SHARED,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Shared entries of the household plus the user's own private ones.
     *
     * @param  Builder<MealPlan>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('meal_plans.household_id', $householdId)
            ->where(fn (Builder $query) => $query
                ->where('meal_plans.visibility', Item::VISIBILITY_SHARED)
                ->orWhere('meal_plans.created_by', $user->id));
    }

    /**
     * Shared entries may point to a recipe another member made private later:
     * such entries keep their title but lose the recipe link for this user.
     *
     * @param  SupportCollection<int, MealPlan>  $entries
     * @return SupportCollection<int, MealPlan>
     */
    public static function withVisibleRecipes(SupportCollection $entries, User $user, int $householdId): SupportCollection
    {
        $visibleRecipeIds = Recipe::query()->visibleTo($user, $householdId)
            ->whereIn('id', $entries->pluck('recipe_id')->filter()->unique())
            ->pluck('id')
            ->flip();

        return $entries->each(function (MealPlan $entry) use ($visibleRecipeIds) {
            if ($entry->recipe_id && ! $visibleRecipeIds->has($entry->recipe_id)) {
                $entry->setRelation('recipe', null);
            }
        });
    }

    public function isPrivate(): bool
    {
        return $this->visibility === Item::VISIBILITY_PRIVATE;
    }

    public static function mealTypeLabel(string $mealType): string
    {
        return match ($mealType) {
            'breakfast' => __('Breakfast'),
            'lunch' => __('Lunch'),
            'dinner' => __('Dinner'),
            'snack' => __('Snack'),
            default => $mealType,
        };
    }
}
