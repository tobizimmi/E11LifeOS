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

#[Fillable([
    'household_id', 'created_by', 'visibility', 'title', 'description', 'ingredients', 'instructions',
    'prep_time', 'cook_time', 'servings', 'difficulty', 'image_path', 'source_url', 'source_type',
    'nutrition', 'cooked_count', 'archived_at', 'legacy_id',
])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

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
            'nutrition' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Recipe $recipe) => RecipeImages::delete($recipe->image_path));
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
