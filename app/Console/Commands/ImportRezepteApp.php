<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Models\RecipeRating;
use App\Models\User;
use App\Support\RecipeImages;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('recipes:import-rezepteapp
    {email : LifeOS user whose household receives the recipes}
    {--source-email= : RezepteApp2 account to import from (default: the same e-mail)}
    {--all : Import the recipes of all RezepteApp2 accounts into this household}
    {--uploads-path= : RezepteApp2 upload directory on this server, to copy the photos from (default: services.rezepteapp.uploads_path)}
    {--uploads-url= : Base URL of the RezepteApp2 uploads, to download photos from (fallback, default: services.rezepteapp.uploads_url)}
    {--private : Import the recipes as private to the user instead of shared with the household}
    {--dry-run : Only show what would be imported}')]
#[Description('Import recipes, categories, tags, favorites, ratings and the meal plan from the old RezepteApp2 database (safe to run again)')]
class ImportRezepteApp extends Command
{
    private Connection $source;

    /**
     * @var array<string, int> RezepteApp2 category id => LifeOS category id
     */
    private array $categoryMap = [];

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        $household = $user?->currentHousehold();

        if (! $household) {
            $this->error('No LifeOS user with a household found for '.$this->argument('email').'.');

            return self::FAILURE;
        }

        try {
            $this->source = DB::connection('rezepteapp');
            $sourceUsers = $this->option('all')
                ? $this->source->table('users')->orderBy('email')->get()
                : $this->source->table('users')->where('email', $this->option('source-email') ?: $user->email)->limit(1)->get();
        } catch (Throwable $exception) {
            $this->error('Cannot read the RezepteApp2 database (REZEPTEAPP_DB_* in .env): '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($sourceUsers->isEmpty()) {
            $this->error('No RezepteApp2 account with e-mail '.($this->option('source-email') ?: $user->email).'.');

            return self::FAILURE;
        }

        foreach ($sourceUsers as $sourceUser) {
            $this->importAccount($user, $household, $sourceUser);
        }

        return self::SUCCESS;
    }

    /**
     * Recipes, categories, favorites, ratings and meal plan of one RezepteApp2 account.
     */
    private function importAccount(User $user, Household $household, object $sourceUser): void
    {
        $recipes = $this->source->table('recipes')->where('userId', $sourceUser->id)->orderBy('createdAt')->get();
        $recipeIds = $recipes->pluck('id')->all();
        $alreadyImported = Recipe::where('household_id', $household->id)->whereIn('legacy_id', $recipeIds)->pluck('legacy_id')->all();
        $new = $recipes->reject(fn (object $recipe) => in_array($recipe->id, $alreadyImported, true));
        $repaired = $this->option('dry-run') ? 0 : $this->repairPhotos($household, $recipes);

        $this->info(sprintf(
            '%d recipes found for %s, %d already imported, %d to import into household "%s".',
            $recipes->count(), $sourceUser->email, count($alreadyImported), $new->count(), $household->name,
        ));

        if ($this->option('dry-run')) {
            $new->each(fn (object $recipe) => $this->line(' - '.$recipe->title));

            return;
        }

        $this->importCategories($household->id, $sourceUser->id, $recipeIds);

        $favoriteIds = $this->source->table('favorites')->where('userId', $sourceUser->id)->pluck('recipeId')->all();
        $photos = 0;
        $missingPhotos = [];

        $this->withProgressBar($new, function (object $row) use ($household, $user, $favoriteIds, &$photos, &$missingPhotos) {
            $imagePath = null;

            if ($row->imageUrl) {
                $imagePath = $this->importPhoto($row->imageUrl, $household->id);
                $imagePath ? $photos++ : $missingPhotos[] = $row->title;
            }

            $additionalImages = collect($this->json($row->additionalImages ?? null) ?? [])
                ->filter(fn ($url) => is_string($url) && trim($url) !== '')
                ->take(Recipe::MAX_ADDITIONAL_IMAGES)
                ->map(fn (string $url) => $this->importPhoto($url, $household->id))
                ->filter()
                ->values()
                ->all();

            DB::transaction(function () use ($row, $household, $user, $favoriteIds, $imagePath, $additionalImages) {
                $recipe = new Recipe([
                    'household_id' => $household->id,
                    'created_by' => $user->id,
                    'visibility' => $this->option('private') ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
                    'title' => mb_substr((string) $row->title, 0, 500),
                    'description' => $row->description ?: null,
                    'ingredients' => $this->ingredients($row->ingredients),
                    'instructions' => $this->instructions($row->instructions),
                    'prep_time' => $row->prepTime ?: null,
                    'cook_time' => $row->cookTime ?: null,
                    'servings' => $row->servings ?: null,
                    'difficulty' => $row->difficulty ? mb_substr((string) $row->difficulty, 0, 50) : null,
                    'image_path' => $imagePath,
                    'additional_images' => $additionalImages ?: null,
                    'source_url' => $row->sourceUrl ? mb_substr((string) $row->sourceUrl, 0, 2000) : null,
                    'source_type' => $row->sourceType ?: null,
                    'nutrition' => $this->json($row->nutrition),
                    'cooked_count' => (int) ($row->cookedCount ?? 0),
                    'archived_at' => property_exists($row, 'archivedAt') && $row->archivedAt ? Carbon::parse($row->archivedAt) : null,
                    'legacy_id' => $row->id,
                ]);
                $recipe->created_at = $row->createdAt ? Carbon::parse($row->createdAt) : now();
                $recipe->updated_at = $row->updatedAt ? Carbon::parse($row->updatedAt) : now();
                $recipe->save();

                $categoryIds = $this->source->table('category_recipes')->where('recipeId', $row->id)->pluck('categoryId')
                    ->map(fn (string $id) => $this->categoryMap[$id] ?? null)
                    ->filter()
                    ->all();
                $recipe->categories()->sync($categoryIds);

                $recipe->syncTagNames(
                    $this->source->table('recipe_tags')->join('tags', 'tags.id', '=', 'recipe_tags.tagId')
                        ->where('recipe_tags.recipeId', $row->id)->pluck('tags.name')->all()
                );

                if (in_array($row->id, $favoriteIds, true)) {
                    $recipe->favoritedBy()->syncWithoutDetaching([$user->id => ['created_at' => now()]]);
                }
            });
        });

        // Ratings and meal plan refer to all recipes of the account, including ones imported earlier.
        $recipeMap = Recipe::where('household_id', $household->id)->whereIn('legacy_id', $recipeIds)->pluck('id', 'legacy_id')->all();
        $ratings = $this->importRatings($user, $sourceUser->id, $recipeMap);
        $mealPlans = $this->importMealPlans($household->id, $user, $sourceUser->id, $recipeMap);

        $this->newLine(2);
        $this->info(sprintf('Imported %d recipes (%d with photo) and %d categories.', $new->count(), $photos, count($this->categoryMap)));

        if ($repaired > 0) {
            $this->info(sprintf('Added missing photos to %d recipes imported earlier.', $repaired));
        }
        $this->info(sprintf('Imported %d ratings and %d meal plan entries.', $ratings, $mealPlans));

        if ($missingPhotos !== []) {
            $this->warn('Photos not found for: '.implode(', ', $missingPhotos).' – pass --uploads-path or --uploads-url.');
        }
    }

    /**
     * The account's own ratings; an existing LifeOS rating of the same recipe is kept.
     *
     * @param  array<string, int>  $recipeMap  RezepteApp2 recipe id => LifeOS recipe id
     */
    private function importRatings(User $user, string $sourceUserId, array $recipeMap): int
    {
        if (! $this->source->getSchemaBuilder()->hasTable('recipe_ratings')) {
            return 0;
        }

        $imported = 0;

        $this->source->table('recipe_ratings')->where('userId', $sourceUserId)->whereIn('recipeId', array_keys($recipeMap))->get()
            ->each(function (object $row) use ($user, $recipeMap, &$imported) {
                $rating = RecipeRating::firstOrCreate(
                    ['recipe_id' => $recipeMap[$row->recipeId], 'user_id' => $user->id],
                    [
                        'rating' => max(1, min(5, (int) $row->rating)),
                        'comment' => $row->comment ?: null,
                        'cooked_at' => $row->cookedAt ? Carbon::parse($row->cookedAt) : null,
                    ],
                );
                $imported += $rating->wasRecentlyCreated ? 1 : 0;
            });

        return $imported;
    }

    /**
     * The account's meal plan (recipes and free-text entries), matched by id so it is imported once.
     *
     * @param  array<string, int>  $recipeMap  RezepteApp2 recipe id => LifeOS recipe id
     */
    private function importMealPlans(int $householdId, User $user, string $sourceUserId, array $recipeMap): int
    {
        if (! $this->source->getSchemaBuilder()->hasTable('meal_plans')) {
            return 0;
        }

        $alreadyImported = MealPlan::where('household_id', $householdId)->whereNotNull('legacy_id')->pluck('legacy_id')->flip();
        $recipes = Recipe::whereIn('id', $recipeMap)->get(['id', 'title', 'visibility'])->keyBy('id');
        $imported = 0;

        $this->source->table('meal_plans')->where('userId', $sourceUserId)->orderBy('date')->get()
            ->reject(fn (object $row) => $alreadyImported->has($row->id))
            ->each(function (object $row) use ($householdId, $user, $recipeMap, $recipes, &$imported) {
                $recipe = $row->recipeId ? $recipes->get($recipeMap[$row->recipeId] ?? 0) : null;
                $title = $recipe?->title ?? trim((string) ($row->customTitle ?? ''));

                // Entries of recipes that belong to another account can't be linked; skip them.
                if ($title === '') {
                    return;
                }

                MealPlan::create([
                    'household_id' => $householdId,
                    'created_by' => $user->id,
                    'visibility' => $this->option('private') || $recipe?->isPrivate() ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
                    'date' => Carbon::parse($row->date)->startOfDay(),
                    'meal_type' => in_array($row->mealType, MealPlan::MEAL_TYPES, true) ? $row->mealType : 'dinner',
                    'recipe_id' => $recipe?->id,
                    'title' => mb_substr($title, 0, 500),
                    'servings' => $row->servings ?: null,
                    'note' => ($row->note ?? null) ?: null,
                    'legacy_id' => $row->id,
                ]);
                $imported++;
            });

        return $imported;
    }

    /**
     * Categories of the account plus global ones used by its recipes; matched by name,
     * so running the import again (or existing LifeOS categories) never duplicates them.
     *
     * @param  array<int, string>  $recipeIds
     */
    private function importCategories(int $householdId, string $sourceUserId, array $recipeIds): void
    {
        $usedIds = $this->source->table('category_recipes')->whereIn('recipeId', $recipeIds)->pluck('categoryId')->all();

        $this->source->table('categories')
            ->where(fn ($query) => $query->where('userId', $sourceUserId)->orWhereIn('id', $usedIds))
            ->orderBy('displayOrder')
            ->get()
            ->each(function (object $row) use ($householdId) {
                $name = mb_substr(trim((string) $row->name), 0, 191);

                if ($name === '') {
                    return;
                }

                $category = RecipeCategory::firstOrCreate(
                    ['household_id' => $householdId, 'name' => $name],
                    [
                        'icon' => $this->emojiIcon($row->icon),
                        'color' => $row->color ?: null,
                        'position' => (int) $row->displayOrder,
                        'legacy_id' => $row->id,
                    ],
                );

                $this->categoryMap[$row->id] = $category->id;
            });
    }

    /**
     * Only short text icons (emoji) are kept; uploaded icon files are dropped.
     */
    private function emojiIcon(?string $icon): ?string
    {
        $icon = trim((string) $icon);

        return $icon !== '' && mb_strlen($icon) <= 8 && ! preg_match('#^(/|https?:|data:)#i', $icon) ? $icon : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ingredients(mixed $raw): array
    {
        return collect($this->json($raw) ?? [])
            ->filter(fn ($ingredient) => is_array($ingredient) && trim((string) ($ingredient['name'] ?? '')) !== '')
            ->map(fn (array $ingredient) => ! empty($ingredient['isSection'])
                ? ['name' => trim((string) $ingredient['name']), 'isSection' => true]
                : [
                    'amount' => trim((string) ($ingredient['amount'] ?? '')),
                    'unit' => trim((string) ($ingredient['unit'] ?? '')),
                    'name' => trim((string) $ingredient['name']),
                ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{text: string}>
     */
    private function instructions(mixed $raw): array
    {
        return collect($this->json($raw) ?? [])
            ->map(fn ($step) => trim((string) (is_array($step) ? ($step['text'] ?? '') : $step)))
            ->filter()
            ->map(fn (string $text) => ['text' => $text])
            ->values()
            ->all();
    }

    private function json(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Copies the photo from the upload directory, or downloads it; returns the new path or null.
     */
    /**
     * Recipes imported earlier without photo (or whose file is gone) get it now. Photos changed
     * in LifeOS are kept; the RezepteApp2 files are only read.
     *
     * @param  Collection<int, object>  $rows
     */
    private function repairPhotos(Household $household, Collection $rows): int
    {
        $repaired = 0;
        $disk = Storage::disk(Recipe::IMAGE_DISK);
        $imported = Recipe::where('household_id', $household->id)->whereIn('legacy_id', $rows->pluck('id'))->get()->keyBy('legacy_id');

        foreach ($rows as $row) {
            $recipe = $imported[$row->id] ?? null;

            if (! $recipe) {
                continue;
            }

            $changed = false;

            if ($row->imageUrl && (! $recipe->image_path || ! $disk->exists($recipe->image_path))) {
                if ($path = $this->importPhoto($row->imageUrl, $household->id)) {
                    $recipe->image_path = $path;
                    $changed = true;
                }
            }

            $sourceImages = collect($this->json($row->additionalImages ?? null) ?? [])->filter(fn ($url) => is_string($url) && trim($url) !== '');
            $existing = collect($recipe->additional_images ?? [])->filter(fn ($path) => is_string($path) && $disk->exists($path));

            if ($sourceImages->isNotEmpty() && $existing->isEmpty()) {
                $paths = $sourceImages->take(Recipe::MAX_ADDITIONAL_IMAGES)->map(fn (string $url) => $this->importPhoto($url, $household->id))->filter()->values()->all();

                if ($paths !== []) {
                    $recipe->additional_images = $paths;
                    $changed = true;
                }
            }

            if ($changed) {
                // Keep the recipe's "last changed" date: only the photo was added.
                $recipe->timestamps = false;
                $recipe->save();
                $repaired++;
            }
        }

        return $repaired;
    }

    private function importPhoto(string $imageUrl, int $householdId): ?string
    {
        $uploadsPath = $this->option('uploads-path') ?: config('services.rezepteapp.uploads_path');
        $uploadsUrl = $this->option('uploads-url') ?: config('services.rezepteapp.uploads_url');
        $relative = str_contains($imageUrl, '/uploads/') ? substr($imageUrl, strpos($imageUrl, '/uploads/') + strlen('/uploads/')) : null;
        $bytes = null;

        if ($relative !== null && $uploadsPath && ! str_contains($relative, '..')) {
            $file = rtrim((string) $uploadsPath, '/').'/'.$relative;
            $bytes = is_file($file) ? file_get_contents($file) : null;
        }

        if ($bytes === null) {
            $url = match (true) {
                (bool) preg_match('#^https?://#i', $imageUrl) => $imageUrl,
                $relative !== null && (bool) $uploadsUrl => rtrim((string) $uploadsUrl, '/').'/'.$relative,
                default => null,
            };

            try {
                $response = $url ? Http::timeout(20)->get($url) : null;
                $bytes = $response?->successful() ? $response->body() : null;
            } catch (Throwable) {
                $bytes = null;
            }
        }

        try {
            return $bytes ? RecipeImages::store($bytes, $householdId) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
