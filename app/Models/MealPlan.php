<?php

namespace App\Models;

use Database\Factories\MealPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * A planned meal of a day: a recipe, or a free-text entry such as "Leftovers".
 *
 * @property int $id
 * @property int $household_id
 * @property ?int $created_by
 * @property string $visibility
 * @property Carbon $date
 * @property string $meal_type
 * @property ?int $recipe_id
 * @property string $title
 * @property ?int $servings
 * @property ?string $note
 */
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

    /**
     * Nutrition of one day per person: one serving of each planned recipe added up
     * (empty when no planned recipe has nutrition values; "partial" when some have none).
     *
     * @param  SupportCollection<int, MealPlan>  $entries
     * @return array{calories?: float, protein?: float, carbs?: float, fat?: float, partial?: bool}
     */
    public static function nutritionPerPerson(SupportCollection $entries): array
    {
        $values = $entries->filter(fn (MealPlan $entry) => $entry->recipe instanceof Recipe)
            ->map(fn (MealPlan $entry) => $entry->recipe instanceof Recipe ? $entry->recipe->nutritionValues() : []);
        $withValues = $values->filter(fn (array $nutrition) => isset($nutrition['calories']));

        if ($withValues->isEmpty()) {
            return [];
        }

        $sum = [];

        foreach (['calories', 'protein', 'carbs', 'fat'] as $key) {
            if ($withValues->contains(fn (array $nutrition) => isset($nutrition[$key]))) {
                $sum[$key] = (float) $withValues->sum(fn (array $nutrition) => $nutrition[$key] ?? 0);
            }
        }

        return [...$sum, 'partial' => $withValues->count() < $values->count()];
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
