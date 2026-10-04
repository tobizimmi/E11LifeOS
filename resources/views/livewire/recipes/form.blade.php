<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\RecipeCategory;
use App\Support\RecipeImages;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use WithFileUploads;

    /**
     * Null while creating a new recipe.
     */
    #[Locked]
    public ?int $recipeId = null;

    public string $title = '';

    public string $description = '';

    public ?int $prepTime = null;

    public ?int $cookTime = null;

    public ?int $servings = null;

    public string $difficulty = '';

    public string $sourceUrl = '';

    public string $tagsInput = '';

    public bool $isPrivate = false;

    /**
     * @var array<int, array{amount: string, unit: string, name: string, isSection: bool}>
     */
    public array $ingredients = [];

    /**
     * @var array<int, string>
     */
    public array $steps = [];

    /**
     * @var array<int, int|string>
     */
    public array $categoryIds = [];

    public $photo = null;

    public bool $removePhoto = false;

    /**
     * New additional photos (multi upload).
     *
     * @var array<int, mixed>
     */
    public array $newPhotos = [];

    /**
     * Indexes of existing additional photos to delete on save.
     *
     * @var array<int, int>
     */
    public array $removedPhotos = [];

    public string $newCategoryName = '';

    /**
     * Nutrition per serving (kcal / g), all optional.
     *
     * @var array{calories: ?int, protein: ?int, carbs: ?int, fat: ?int, fiber: ?int}
     */
    public array $nutrition = ['calories' => null, 'protein' => null, 'carbs' => null, 'fat' => null, 'fiber' => null];

    /**
     * Photo downloaded by the import (stored already, attached on save).
     */
    #[Locked]
    public ?string $importedImagePath = null;

    #[Locked]
    public ?string $sourceType = null;

    /** Hint from the import, e.g. that only the photo of an Instagram post could be read. */
    public ?string $importNotice = null;

    public function mount(?int $recipe = null): void
    {
        $this->resolveHousehold();

        if ($recipe === null) {
            $this->ingredients = [$this->emptyIngredient()];
            $this->steps = [''];
            $this->fillFromDraft(session()->pull('recipe_draft'));

            return;
        }

        $model = $this->findRecipe($recipe);
        $this->recipeId = $model->id;
        $this->title = $model->title;
        $this->description = (string) $model->description;
        $this->prepTime = $model->prep_time;
        $this->cookTime = $model->cook_time;
        $this->servings = $model->servings;
        $this->difficulty = (string) $model->difficulty;
        $this->sourceUrl = (string) $model->source_url;
        $this->isPrivate = $model->isPrivate();
        $this->tagsInput = $model->tags->sortBy('name')->pluck('name')->implode(', ');
        $this->categoryIds = $model->categories->pluck('id')->all();
        $this->ingredients = collect($model->scaledIngredients())->map(fn (array $ingredient) => $ingredient)->all() ?: [$this->emptyIngredient()];
        $this->steps = $model->steps() ?: [''];
        $this->nutrition = array_merge($this->nutrition, array_intersect_key(array_map(fn ($value) => is_numeric($value) ? (int) round((float) $value) : null, (array) $model->nutrition), $this->nutrition));
    }

    /**
     * Pre-fills a new recipe with the result of the import page.
     *
     * @param  array<string, mixed>|null  $draft
     */
    private function fillFromDraft(?array $draft): void
    {
        if (! $draft) {
            return;
        }

        $this->title = (string) ($draft['title'] ?? '');
        $this->description = (string) ($draft['description'] ?? '');
        $this->servings = $draft['servings'] ?? null;
        $this->prepTime = $draft['prepTime'] ?? null;
        $this->cookTime = $draft['cookTime'] ?? null;
        $this->sourceUrl = (string) ($draft['sourceUrl'] ?? '');
        $this->sourceType = $draft['sourceType'] ?? null;
        $this->importNotice = $draft['notice'] ?? null;
        $this->ingredients = collect($draft['ingredients'] ?? [])->map(fn (array $ingredient) => $ingredient + $this->emptyIngredient())->all() ?: [$this->emptyIngredient()];
        $this->steps = $draft['steps'] ?: [''];

        // Only photos the import stored for this household.
        $imagePath = (string) ($draft['imagePath'] ?? '');
        $this->importedImagePath = str_starts_with($imagePath, 'recipes/'.$this->householdId.'/') ? $imagePath : null;
    }

    private function findRecipe(int $id): Recipe
    {
        // Recipes shared by another household are used, not edited.
        return Recipe::query()->visibleTo(Auth::user(), (int) $this->householdId)->where('recipes.household_id', $this->householdId)->with('tags', 'categories')->findOrFail($id);
    }

    /**
     * @return array{amount: string, unit: string, name: string, isSection: bool}
     */
    private function emptyIngredient(bool $isSection = false): array
    {
        return ['amount' => '', 'unit' => '', 'name' => '', 'isSection' => $isSection];
    }

    public function addIngredient(bool $isSection = false): void
    {
        $this->ingredients[] = $this->emptyIngredient($isSection);
    }

    public function removeIngredient(int $index): void
    {
        unset($this->ingredients[$index]);
        $this->ingredients = array_values($this->ingredients);
    }

    public function moveIngredient(int $index, int $direction): void
    {
        $this->ingredients = $this->move($this->ingredients, $index, $direction);
    }

    public function addStep(): void
    {
        $this->steps[] = '';
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function moveStep(int $index, int $direction): void
    {
        $this->steps = $this->move($this->steps, $index, $direction);
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $list
     * @return array<int, T>
     */
    private function move(array $list, int $index, int $direction): array
    {
        $target = $index + $direction;

        if (isset($list[$index], $list[$target])) {
            [$list[$index], $list[$target]] = [$list[$target], $list[$index]];
        }

        return array_values($list);
    }

    /**
     * Manual values replace the stored ones; an estimate's extra keys (e.g. "estimated") survive
     * as long as the numbers were not changed.
     *
     * @return array<string, mixed>|null
     */
    private function nutritionAttribute(Recipe $recipe): ?array
    {
        $values = array_map(fn ($value) => $value === null || $value === '' ? null : (int) $value, $this->nutrition);

        if (array_filter($values, fn ($value) => $value !== null) === []) {
            return null;
        }

        $stored = (array) $recipe->nutrition;
        $unchanged = array_intersect_key($stored, $values) == array_filter($values, fn ($value) => $value !== null);

        return $unchanged ? $stored : $values;
    }

    public function removeNewPhoto(int $index): void
    {
        unset($this->newPhotos[$index]);
        $this->newPhotos = array_values($this->newPhotos);
    }

    public function toggleRemovedPhoto(int $index): void
    {
        $this->removedPhotos = in_array($index, $this->removedPhotos, true)
            ? array_values(array_diff($this->removedPhotos, [$index]))
            : [...$this->removedPhotos, $index];
    }

    public function createCategory(): void
    {
        $this->validate(['newCategoryName' => ['required', 'string', 'max:100']]);

        $category = RecipeCategory::firstOrCreate(
            ['household_id' => $this->householdId, 'name' => trim($this->newCategoryName)],
            ['position' => (int) RecipeCategory::where('household_id', $this->householdId)->max('position') + 1],
        );

        $this->categoryIds[] = $category->id;
        $this->categoryIds = array_values(array_unique(array_map('intval', $this->categoryIds)));
        $this->reset('newCategoryName');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:10000'],
            'prepTime' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'cookTime' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:999'],
            'difficulty' => ['nullable', 'string', 'max:50'],
            'sourceUrl' => ['nullable', 'string', 'max:2000'],
            'tagsInput' => ['nullable', 'string', 'max:1000'],
            'ingredients' => ['array', 'max:200'],
            'ingredients.*.amount' => ['nullable', 'string', 'max:50'],
            'ingredients.*.unit' => ['nullable', 'string', 'max:50'],
            'ingredients.*.name' => ['nullable', 'string', 'max:255'],
            'steps' => ['array', 'max:100'],
            'steps.*' => ['nullable', 'string', 'max:5000'],
            'categoryIds' => ['array'],
            'categoryIds.*' => ['integer', Rule::exists('recipe_categories', 'id')->where('household_id', $this->householdId)],
            'photo' => ['nullable', 'image', 'max:15360'],
            'newPhotos' => ['array', 'max:'.Recipe::MAX_ADDITIONAL_IMAGES],
            'newPhotos.*' => ['image', 'max:15360'],
            'nutrition' => ['array'],
            'nutrition.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $recipe = $this->recipeId ? $this->findRecipe($this->recipeId) : new Recipe([
            'household_id' => $this->householdId,
            'created_by' => Auth::id(),
        ]);

        $attributes = [
            'title' => trim($validated['title']),
            'description' => trim((string) $validated['description']) ?: null,
            'prep_time' => $validated['prepTime'] ?: null,
            'cook_time' => $validated['cookTime'] ?: null,
            'servings' => $validated['servings'] ?: null,
            'difficulty' => trim((string) $validated['difficulty']) ?: null,
            'source_url' => trim((string) $validated['sourceUrl']) ?: null,
            'ingredients' => collect($validated['ingredients'])
                ->map(fn (array $ingredient, int $index) => [
                    'amount' => trim((string) ($ingredient['amount'] ?? '')),
                    'unit' => trim((string) ($ingredient['unit'] ?? '')),
                    'name' => trim((string) ($ingredient['name'] ?? '')),
                    'isSection' => (bool) ($this->ingredients[$index]['isSection'] ?? false),
                ])
                ->filter(fn (array $ingredient) => $ingredient['name'] !== '')
                ->map(fn (array $ingredient) => $ingredient['isSection'] ? ['name' => $ingredient['name'], 'isSection' => true] : array_diff_key($ingredient, ['isSection' => true]))
                ->values()
                ->all(),
            'instructions' => collect($validated['steps'])
                ->map(fn ($step) => trim((string) $step))
                ->filter()
                ->map(fn (string $step) => ['text' => $step])
                ->values()
                ->all(),
            'nutrition' => $this->nutritionAttribute($recipe),
        ];

        if (! $recipe->exists && $this->sourceType) {
            $attributes['source_type'] = $this->sourceType;
        }

        if (! $recipe->exists || $recipe->visibilityCanBeChangedBy(Auth::user())) {
            $attributes['visibility'] = $this->isPrivate ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;
        }

        $oldImage = $recipe->image_path;

        if ($this->photo) {
            $attributes['image_path'] = RecipeImages::store($this->photo, (int) $this->householdId);
        } elseif ($this->removePhoto) {
            $attributes['image_path'] = null;
        } elseif (! $recipe->exists && $this->importedImagePath) {
            $attributes['image_path'] = $this->importedImagePath;
        }

        $keptPhotos = collect($recipe->additional_images ?? [])->except($this->removedPhotos);
        $droppedPhotos = collect($recipe->additional_images ?? [])->only($this->removedPhotos)->values()->all();
        $addedPhotos = collect($this->newPhotos)
            ->take(max(0, Recipe::MAX_ADDITIONAL_IMAGES - $keptPhotos->count()))
            ->map(fn ($upload) => RecipeImages::store($upload, (int) $this->householdId));
        $attributes['additional_images'] = $keptPhotos->merge($addedPhotos)->values()->all() ?: null;

        DB::transaction(function () use ($recipe, $attributes) {
            $recipe->fill($attributes)->save();
            $recipe->categories()->sync(array_map('intval', $this->categoryIds));
            $recipe->syncTagNames(explode(',', $this->tagsInput));
        });

        if (array_key_exists('image_path', $attributes) && $oldImage && $oldImage !== $attributes['image_path']) {
            RecipeImages::delete($oldImage);
        }

        array_map(RecipeImages::delete(...), $droppedPhotos);

        $this->redirectRoute('recipes.show', ['recipe' => $recipe->id], navigate: true);
    }

    public function with(): array
    {
        $recipe = $this->recipeId ? $this->findRecipe($this->recipeId) : null;

        return [
            'recipe' => $recipe,
            'categories' => RecipeCategory::where('household_id', $this->householdId)->orderBy('position')->orderBy('name')->get(),
            'canChangeVisibility' => ! $recipe || $recipe->visibilityCanBeChangedBy(Auth::user()),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Recipes') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-8">
        <form wire:submit="save" @if (! $recipeId) data-draft="recipe" data-draft-restored="{{ __('Draft restored') }}" @endif class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if ($importNotice)
                <p class="rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200" role="status">{{ $importNotice }}</p>
            @endif
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $recipeId ? __('Edit recipe') : __('New recipe') }}</h1>
                <a href="{{ $recipeId ? route('recipes.show', $recipeId) : route('recipes.index') }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 underline">{{ __('Cancel') }}</a>
            </div>

            <section class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-xs space-y-4">
                <div>
                    <x-input-label for="title" :value="__('Title')" />
                    <x-text-input wire:model="title" id="title" class="mt-1 block w-full text-lg" required autofocus />
                    <x-input-error :messages="$errors->get('title')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="description" :value="__('Description')" />
                    <textarea wire:model="description" id="description" rows="3" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-xs"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div>
                        <x-input-label for="prepTime" :value="__('Prep (min)')" />
                        <x-text-input wire:model="prepTime" id="prepTime" type="number" min="0" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('prepTime')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="cookTime" :value="__('Cooking (min)')" />
                        <x-text-input wire:model="cookTime" id="cookTime" type="number" min="0" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('cookTime')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="servings" :value="__('Servings')" />
                        <x-text-input wire:model="servings" id="servings" type="number" min="1" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('servings')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="difficulty" :value="__('Difficulty')" />
                        <select wire:model="difficulty" id="difficulty" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-xs">
                            <option value="">—</option>
                            <option value="easy">{{ __('Easy') }}</option>
                            <option value="medium">{{ __('Medium') }}</option>
                            <option value="hard">{{ __('Hard') }}</option>
                            @if ($difficulty !== '' && ! in_array($difficulty, \App\Models\Recipe::DIFFICULTIES, true))
                                <option value="{{ $difficulty }}">{{ $difficulty }}</option>
                            @endif
                        </select>
                    </div>
                </div>

                {{-- Photo --}}
                <div>
                    <x-input-label for="photo" :value="__('Photo')" />
                    <div class="mt-1 flex flex-wrap items-center gap-4">
                        @if ($photo && method_exists($photo, 'isPreviewable') && $photo->isPreviewable())
                            <img src="{{ $photo->temporaryUrl() }}" alt="" class="h-24 w-32 rounded-md object-cover" />
                        @elseif ($recipe?->imageUrl() && ! $removePhoto)
                            <img src="{{ $recipe->imageUrl() }}" alt="" class="h-24 w-32 rounded-md object-cover" />
                        @endif
                        @if ($importedImagePath && ! $photo && ! $removePhoto)
                            <span class="rounded-md bg-indigo-50 dark:bg-indigo-900/30 px-3 py-2 text-sm text-indigo-700 dark:text-indigo-200">🖼 {{ __('The photo from the import is used.') }}</span>
                        @endif
                        <div class="min-w-0 max-w-full space-y-1">
                            <input type="file" wire:model="photo" id="photo" accept="image/*" class="block text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-indigo-700" />
                            <p wire:loading wire:target="photo" class="text-xs text-gray-500">{{ __('Uploading …') }}</p>
                            @if (($recipe?->image_path || $importedImagePath) && ! $photo)
                                <label class="inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                                    <input type="checkbox" wire:model.live="removePhoto" class="rounded-sm border-gray-300 text-indigo-600" /> {{ __('Remove photo') }}
                                </label>
                            @endif
                        </div>
                    </div>
                    <x-input-error :messages="$errors->get('photo')" class="mt-1" />
                </div>

                {{-- Additional photos --}}
                <div>
                    <x-input-label for="newPhotos" :value="__('More photos')" />
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach ($recipe?->additionalImageUrls() ?? [] as $photoIndex => $photoUrl)
                            <div wire:key="existing-photo-{{ $photoIndex }}" class="relative">
                                <img src="{{ $photoUrl }}" alt="" @class(['h-20 w-20 rounded-md object-cover', 'opacity-30' => in_array($photoIndex, $removedPhotos, true)]) />
                                <button type="button" wire:click="toggleRemovedPhoto({{ $photoIndex }})" class="absolute end-1 top-1 rounded-full bg-white/90 px-1.5 text-xs text-gray-700 shadow-sm" aria-label="{{ in_array($photoIndex, $removedPhotos, true) ? __('Keep photo') : __('Remove photo') }}">{{ in_array($photoIndex, $removedPhotos, true) ? '↺' : '✕' }}</button>
                            </div>
                        @endforeach
                        @foreach ($newPhotos as $photoIndex => $newPhoto)
                            <div wire:key="new-photo-{{ $photoIndex }}" class="relative">
                                @if (method_exists($newPhoto, 'isPreviewable') && $newPhoto->isPreviewable())
                                    <img src="{{ $newPhoto->temporaryUrl() }}" alt="" class="h-20 w-20 rounded-md object-cover ring-2 ring-indigo-400" />
                                @else
                                    <div class="flex h-20 w-20 items-center justify-center rounded-md bg-gray-100 dark:bg-gray-700 text-xs text-gray-500">{{ __('Photo') }}</div>
                                @endif
                                <button type="button" wire:click="removeNewPhoto({{ $photoIndex }})" class="absolute end-1 top-1 rounded-full bg-white/90 px-1.5 text-xs text-gray-700 shadow-sm" aria-label="{{ __('Remove photo') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                            </div>
                        @endforeach
                    </div>
                    <input type="file" wire:model="newPhotos" id="newPhotos" accept="image/*" multiple class="mt-2 block text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-indigo-700" />
                    <p wire:loading wire:target="newPhotos" class="text-xs text-gray-500">{{ __('Uploading …') }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Up to :count additional photos, e.g. of the steps.', ['count' => \App\Models\Recipe::MAX_ADDITIONAL_IMAGES]) }}</p>
                    <x-input-error :messages="array_merge($errors->get('newPhotos'), $errors->get('newPhotos.*'))" class="mt-1" />
                </div>
            </section>

            {{-- Ingredients --}}
            <section class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-xs space-y-3">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Ingredients') }}</h2>
                <div class="space-y-2">
                    @foreach ($ingredients as $index => $ingredient)
                        <div wire:key="ingredient-row-{{ $index }}" class="flex items-center gap-2">
                            @if ($ingredient['isSection'] ?? false)
                                <span class="text-indigo-600" aria-hidden="true">▸</span>
                                <x-text-input wire:model="ingredients.{{ $index }}.name" class="block flex-1 font-semibold" placeholder="{{ __('Section, e.g. Dough') }}" aria-label="{{ __('Section') }}" />
                            @else
                                <x-text-input wire:model="ingredients.{{ $index }}.amount" class="block w-20" placeholder="200" aria-label="{{ __('Amount') }}" />
                                <x-text-input wire:model="ingredients.{{ $index }}.unit" class="block w-20" placeholder="g" aria-label="{{ __('Unit') }}" />
                                <x-text-input wire:model="ingredients.{{ $index }}.name" class="block flex-1 min-w-0" placeholder="{{ __('Flour') }}" aria-label="{{ __('Ingredient') }}" />
                            @endif
                            <div class="flex shrink-0 items-center text-gray-400">
                                <button type="button" wire:click="moveIngredient({{ $index }}, -1)" class="px-1 hover:text-gray-700 disabled:opacity-30" @disabled($loop->first) aria-label="{{ __('Move up') }}">↑</button>
                                <button type="button" wire:click="moveIngredient({{ $index }}, 1)" class="px-1 hover:text-gray-700 disabled:opacity-30" @disabled($loop->last) aria-label="{{ __('Move down') }}">↓</button>
                                <button type="button" wire:click="removeIngredient({{ $index }})" class="px-1 hover:text-red-600" aria-label="{{ __('Remove') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex gap-2">
                    <x-secondary-button type="button" wire:click="addIngredient">+ {{ __('Ingredient') }}</x-secondary-button>
                    <x-secondary-button type="button" wire:click="addIngredient(true)">+ {{ __('Section') }}</x-secondary-button>
                </div>
            </section>

            {{-- Steps --}}
            <section class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-xs space-y-3">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Preparation') }}</h2>
                <div class="space-y-3">
                    @foreach ($steps as $index => $step)
                        <div wire:key="step-row-{{ $index }}" class="flex items-start gap-2">
                            <span class="mt-2 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-sm font-semibold text-white">{{ $index + 1 }}</span>
                            <textarea wire:model="steps.{{ $index }}" rows="2" class="block flex-1 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 shadow-xs" placeholder="{{ __('Step :number …', ['number' => $index + 1]) }}" aria-label="{{ __('Step :number', ['number' => $index + 1]) }}"></textarea>
                            <div class="mt-2 flex shrink-0 flex-col text-gray-400">
                                <button type="button" wire:click="moveStep({{ $index }}, -1)" class="px-1 hover:text-gray-700 disabled:opacity-30" @disabled($loop->first) aria-label="{{ __('Move up') }}">↑</button>
                                <button type="button" wire:click="moveStep({{ $index }}, 1)" class="px-1 hover:text-gray-700 disabled:opacity-30" @disabled($loop->last) aria-label="{{ __('Move down') }}">↓</button>
                                <button type="button" wire:click="removeStep({{ $index }})" class="px-1 hover:text-red-600" aria-label="{{ __('Remove') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <x-secondary-button type="button" wire:click="addStep">+ {{ __('Step') }}</x-secondary-button>
            </section>

            {{-- Nutrition --}}
            <section class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-xs space-y-3">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Nutrition per serving') }} <span class="text-sm font-normal text-gray-500">({{ __('optional') }})</span></h2>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    @foreach (['calories' => __('Calories (kcal)'), 'protein' => __('Protein (g)'), 'carbs' => __('Carbohydrates (g)'), 'fat' => __('Fat (g)'), 'fiber' => __('Fiber (g)')] as $key => $label)
                        <div>
                            <x-input-label for="nutrition-{{ $key }}" :value="$label" />
                            <x-text-input wire:model="nutrition.{{ $key }}" id="nutrition-{{ $key }}" type="number" min="0" class="mt-1 block w-full" />
                        </div>
                    @endforeach
                </div>
                <x-input-error :messages="$errors->get('nutrition.*')" />
            </section>

            {{-- Organisation --}}
            <section class="rounded-lg bg-white dark:bg-gray-800 p-5 shadow-xs space-y-4">
                <div>
                    <span class="block font-medium text-sm text-gray-700 dark:text-gray-300">{{ __('Categories') }}</span>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($categories as $recipeCategory)
                            <label wire:key="category-{{ $recipeCategory->id }}" class="cursor-pointer">
                                <input type="checkbox" wire:model.live="categoryIds" value="{{ $recipeCategory->id }}" class="peer sr-only" />
                                <span class="inline-block rounded-full px-3 py-1 text-sm ring-1 ring-gray-200 dark:ring-gray-700 text-gray-700 dark:text-gray-300 peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:ring-indigo-600 peer-focus-visible:ring-2">{{ $recipeCategory->icon }} {{ $recipeCategory->name }}</span>
                            </label>
                        @endforeach
                        <div class="inline-flex items-center gap-1">
                            <x-text-input wire:model="newCategoryName" wire:keydown.enter.prevent="createCategory" class="block w-40 py-1 text-sm" placeholder="+ {{ __('New category') }}" aria-label="{{ __('New category') }}" />
                            <button type="button" wire:click="createCategory" class="text-sm text-indigo-600 dark:text-indigo-400 underline">{{ __('Add') }}</button>
                        </div>
                    </div>
                    <x-input-error :messages="$errors->get('newCategoryName')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="tagsInput" :value="__('Tags (comma-separated)')" />
                    <x-text-input wire:model="tagsInput" id="tagsInput" class="mt-1 block w-full" placeholder="{{ __('vegetarian, quick, family') }}" />
                </div>

                <div>
                    <x-input-label for="sourceUrl" :value="__('Source (link or book)')" />
                    <x-text-input wire:model="sourceUrl" id="sourceUrl" class="mt-1 block w-full" placeholder="https://" />
                </div>

                @if ($canChangeVisibility)
                    <x-private-checkbox id="isPrivate" wire:model="isPrivate" />
                @endif
            </section>

            <div class="flex items-center gap-3">
                <x-primary-button wire:loading.attr="disabled" wire:target="photo,newPhotos,save">{{ $recipeId ? __('Save changes') : __('Create recipe') }}</x-primary-button>
                <a href="{{ $recipeId ? route('recipes.show', $recipeId) : route('recipes.index') }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 underline">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>
</div>
