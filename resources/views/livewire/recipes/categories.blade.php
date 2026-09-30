<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\RecipeCategory;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public string $name = '';

    public string $icon = '';

    private function findCategory(int $id): RecipeCategory
    {
        return RecipeCategory::where('household_id', $this->householdId)->findOrFail($id);
    }

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('recipe_categories', 'name')->where('household_id', $this->householdId)],
            'icon' => ['nullable', 'string', 'max:16'],
        ]);

        RecipeCategory::create([
            'household_id' => $this->householdId,
            'name' => trim($this->name),
            'icon' => trim($this->icon) ?: null,
            'position' => (int) RecipeCategory::where('household_id', $this->householdId)->max('position') + 1,
        ]);

        $this->reset('name', 'icon');
    }

    public function rename(int $id, string $name): void
    {
        $name = mb_substr(trim($name), 0, 100);
        $category = $this->findCategory($id);

        if ($name !== '' && ! RecipeCategory::where('household_id', $this->householdId)->where('name', $name)->whereKeyNot($id)->exists()) {
            $category->update(['name' => $name]);
        }
    }

    public function changeIcon(int $id, string $icon): void
    {
        $this->findCategory($id)->update(['icon' => mb_substr(trim($icon), 0, 16) ?: null]);
    }

    public function move(int $id, int $direction): void
    {
        $categories = RecipeCategory::where('household_id', $this->householdId)->orderBy('position')->orderBy('name')->get()->values();
        $index = $categories->search(fn (RecipeCategory $category) => $category->id === $id);
        $target = $index + $direction;

        if ($index === false || ! isset($categories[$target])) {
            return;
        }

        $reordered = $categories->all();
        [$reordered[$index], $reordered[$target]] = [$reordered[$target], $reordered[$index]];

        foreach ($reordered as $position => $category) {
            $category->update(['position' => $position]);
        }
    }

    /**
     * Recipes stay; they only lose this category.
     */
    public function delete(int $id): void
    {
        $this->findCategory($id)->delete();
    }

    public function with(): array
    {
        return [
            'categories' => RecipeCategory::where('household_id', $this->householdId)->withCount('recipes')->orderBy('position')->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            🍲 {{ __('Recipe categories') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
            <a href="{{ route('recipes.index') }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 hover:underline">&larr; {{ __('All recipes') }}</a>

            <form wire:submit="create" class="flex flex-wrap items-end gap-2 rounded-lg bg-white dark:bg-gray-800 p-4 shadow-sm">
                <div class="w-20">
                    <x-input-label for="icon" :value="__('Icon')" />
                    <x-text-input wire:model="icon" id="icon" class="mt-1 block w-full text-center" maxlength="16" placeholder="🥗" />
                </div>
                <div class="flex-1 min-w-[10rem]">
                    <x-input-label for="name" :value="__('New category')" />
                    <x-text-input wire:model="name" id="name" class="mt-1 block w-full" required placeholder="{{ __('e.g. Desserts') }}" />
                </div>
                <x-primary-button>{{ __('Add') }}</x-primary-button>
                <x-input-error :messages="$errors->get('name')" class="w-full" />
            </form>

            <ul class="divide-y divide-gray-100 dark:divide-gray-700 rounded-lg bg-white dark:bg-gray-800 shadow-sm">
                @forelse ($categories as $category)
                    <li wire:key="category-{{ $category->id }}" class="flex items-center gap-2 p-3">
                        <input type="text" value="{{ $category->icon }}" maxlength="16" aria-label="{{ __('Icon') }}" wire:change="changeIcon({{ $category->id }}, $event.target.value)" class="w-14 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-center" />
                        <input type="text" value="{{ $category->name }}" aria-label="{{ __('Name') }}" wire:change="rename({{ $category->id }}, $event.target.value)" class="flex-1 min-w-0 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" />
                        <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count recipe|:count recipes', $category->recipes_count) }}</span>
                        <div class="flex shrink-0 text-gray-400">
                            <button type="button" wire:click="move({{ $category->id }}, -1)" class="px-1 hover:text-gray-700 disabled:opacity-30" @disabled($loop->first) aria-label="{{ __('Move up') }}">↑</button>
                            <button type="button" wire:click="move({{ $category->id }}, 1)" class="px-1 hover:text-gray-700 disabled:opacity-30" @disabled($loop->last) aria-label="{{ __('Move down') }}">↓</button>
                            <button type="button" wire:click="delete({{ $category->id }})" wire:confirm="{{ __('Delete this category? The recipes stay.') }}" class="px-1 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                        </div>
                    </li>
                @empty
                    <li class="p-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No categories yet.') }}</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
