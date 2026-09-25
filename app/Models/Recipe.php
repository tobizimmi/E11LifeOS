<?php

namespace App\Models;

use App\Support\IngredientAmount;
use App\Support\RecipeImages;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'household_id', 'created_by', 'visibility', 'title', 'description', 'ingredients', 'instructions',
    'prep_time', 'cook_time', 'servings', 'difficulty', 'image_path', 'additional_images', 'source_url', 'source_type',
    'nutrition', 'cooked_count', 'archived_at', 'legacy_id',
])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    public const MAX_ADDITIONAL_IMAGES = 12;

    /**
     * Disk for recipe photos (served through RecipeImageController, which checks access).
     */
    public const IMAGE_DISK = 'local';

    protected $attributes = [
        'visibility' => Item::VISIBILITY_SHARED,
    ];

    protected function casts(): array
    {
        return [
            'ingredients' => 'array',
            'instructions' => 'array',
            'additional_images' => 'array',
            'nutrition' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (Recipe $recipe) {
            RecipeImages::delete($recipe->image_path);
            array_map(RecipeImages::delete(...), $recipe->additional_images ?? []);
        });
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(RecipeCategory::class, 'recipe_category_recipe');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'recipe_favorites')->withPivot('created_at');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(RecipeRating::class);
    }

    public function mealPlans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    /**
     * Shared recipes of the household plus the user's own private ones.
     *
     * @param  Builder<Recipe>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('recipes.household_id', $householdId)
            ->where(fn (Builder $query) => $query
                ->where('recipes.visibility', Item::VISIBILITY_SHARED)
                ->orWhere('recipes.created_by', $user->id));
    }

    /**
     * Title, description or any ingredient name contains the term (case-insensitive).
     *
     * @param  Builder<Recipe>  $query
     */
    public function scopeMatching(Builder $query, string $term): void
    {
        $like = '%'.mb_strtolower(trim($term)).'%';

        $query->where(fn (Builder $query) => $query
            ->whereRaw('LOWER(recipes.title) LIKE ?', [$like])
            ->orWhereRaw('LOWER(recipes.description) LIKE ?', [$like])
            ->orWhereRaw('LOWER(recipes.ingredients) LIKE ?', [$like]));
    }

    public function isPrivate(): bool
    {
        return $this->visibility === Item::VISIBILITY_PRIVATE;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function visibilityCanBeChangedBy(User $user): bool
    {
        return $this->created_by !== null && $this->created_by === $user->id;
    }

    public function isFavoriteOf(User $user): bool
    {
        return $this->relationLoaded('favoritedBy')
            ? $this->favoritedBy->contains('id', $user->id)
            : $this->favoritedBy()->whereKey($user->id)->exists();
    }

    /**
     * Known difficulties are translated; imported free text is shown as is.
     */
    public function difficultyLabel(): ?string
    {
        return match ($this->difficulty) {
            null, '' => null,
            'easy' => __('Easy'),
            'medium' => __('Medium'),
            'hard' => __('Hard'),
            default => $this->difficulty,
        };
    }

    public function totalTime(): ?int
    {
        $total = (int) $this->prep_time + (int) $this->cook_time;

        return $total > 0 ? $total : null;
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? route('recipes.image', ['recipe' => $this->id, 'v' => substr(md5($this->image_path), 0, 8)]) : null;
    }

    /**
     * URLs of the additional photos, keyed by their index in additional_images.
     *
     * @return array<int, string>
     */
    public function additionalImageUrls(): array
    {
        return collect($this->additional_images ?? [])
            ->map(fn (string $path, int $index) => route('recipes.photo', ['recipe' => $this->id, 'index' => $index, 'v' => substr(md5($path), 0, 8)]))
            ->all();
    }

    /**
     * Average rating (one decimal) or null while nobody has rated the recipe.
     */
    public function averageRating(): ?float
    {
        $average = $this->relationLoaded('ratings') ? $this->ratings->avg('rating') : $this->ratings()->avg('rating');

        return $average !== null ? round((float) $average, 1) : null;
    }

    /**
     * Saves (or replaces) the user's rating; one rating per person and recipe.
     */
    public function rateBy(User $user, int $rating, ?string $comment = null, bool $cookedToday = false): RecipeRating
    {
        $values = ['rating' => max(1, min(5, $rating)), 'comment' => trim((string) $comment) ?: null];

        if ($cookedToday) {
            $values['cooked_at'] = today();
        }

        return $this->ratings()->updateOrCreate(['user_id' => $user->id], $values);
    }

    /**
     * Copy with categories, tags and own photo files, owned by the given user.
     * Ratings, favorites, meal plans and the cooked counter start fresh.
     */
    public function duplicateFor(User $user): Recipe
    {
        return DB::transaction(function () use ($user) {
            $copy = $this->replicate(['cooked_count', 'archived_at', 'legacy_id', 'image_path', 'additional_images']);
            $copy->title = mb_substr(__(':title (copy)', ['title' => $this->title]), 0, 500);
            $copy->created_by = $user->id;
            $copy->image_path = RecipeImages::copy($this->image_path, $this->household_id);
            $copy->additional_images = array_values(array_filter(array_map(
                fn (string $path) => RecipeImages::copy($path, $this->household_id),
                $this->additional_images ?? [],
            ))) ?: null;
            $copy->save();

            $copy->categories()->sync($this->categories()->pluck('recipe_categories.id'));
            $copy->tags()->sync($this->tags()->pluck('tags.id'));

            return $copy;
        });
    }

    /**
     * How many of the given pantry items appear in the ingredient names.
     *
     * @param  array<int, string>  $pantry  Lower-case search terms.
     * @return array{matched: array<int, string>, missing: array<int, string>, percent: int}
     */
    public function pantryMatch(array $pantry): array
    {
        $names = collect($this->scaledIngredients())
            ->reject(fn (array $ingredient) => $ingredient['isSection'])
            ->pluck('name')
            ->values();

        [$matched, $missing] = $names->partition(fn (string $name) => collect($pantry)
            ->contains(fn (string $term) => str_contains(mb_strtolower($name), $term)));

        return [
            'matched' => $matched->values()->all(),
            'missing' => $missing->values()->all(),
            'percent' => $names->isEmpty() ? 0 : (int) round($matched->count() / $names->count() * 100),
        ];
    }

    /**
     * Ingredients scaled to the given servings (sections pass through unchanged).
     *
     * @return array<int, array{name: string, amount: string, unit: string, isSection: bool}>
     */
    public function scaledIngredients(?int $servings = null): array
    {
        $factor = $servings && $this->servings ? $servings / $this->servings : 1.0;

        return collect($this->ingredients ?? [])
            ->filter(fn ($ingredient) => is_array($ingredient) && trim((string) ($ingredient['name'] ?? '')) !== '')
            ->map(fn (array $ingredient) => [
                'name' => trim((string) $ingredient['name']),
                'amount' => ! empty($ingredient['isSection']) ? '' : IngredientAmount::scale((string) ($ingredient['amount'] ?? ''), $factor),
                'unit' => ! empty($ingredient['isSection']) ? '' : trim((string) ($ingredient['unit'] ?? '')),
                'isSection' => ! empty($ingredient['isSection']),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function steps(): array
    {
        return collect($this->instructions ?? [])
            ->map(fn ($step) => trim((string) (is_array($step) ? ($step['text'] ?? '') : $step)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Replaces the recipe's tags with the given names (household tags shared with pages).
     *
     * @param  array<int, string>  $names
     */
    public function syncTagNames(array $names): void
    {
        $tagIds = collect($names)
            ->map(fn (string $name) => Tag::normalizeName($name))
            ->filter()
            ->unique()
            ->map(fn (string $name) => Tag::firstOrCreate(['household_id' => $this->household_id, 'name' => $name])->id)
            ->all();

        $this->tags()->sync($tagIds);
    }
}
