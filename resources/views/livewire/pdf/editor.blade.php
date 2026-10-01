<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Recipe;
use App\Support\PdfTheme;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * PDF editor: accent colour, font and footer for all PDFs of the household, plus the layout of the
 * recipe PDF – with a live preview of a real recipe.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /** @var array<string, mixed> */
    public array $general = [];

    /** @var array<string, mixed> */
    public array $recipe = [];

    public ?int $previewRecipeId = null;

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->general = PdfTheme::for($this->householdId, 'general');
        $this->recipe = PdfTheme::for($this->householdId, 'recipe');
        $this->previewRecipeId = $this->recipes()->first()?->id;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Recipe>
     */
    private function recipes(): \Illuminate\Database\Eloquent\Collection
    {
        return Recipe::query()->visibleTo(Auth::user(), (int) $this->householdId)->whereNull('archived_at')
            ->orderByRaw('image_path IS NULL')->orderByDesc('cooked_count')->limit(50)->get(['id', 'title', 'image_path']);
    }

    public function save(): void
    {
        PdfTheme::save((int) $this->householdId, 'general', $this->general);
        PdfTheme::save((int) $this->householdId, 'recipe', $this->recipe);
        $this->general = PdfTheme::for($this->householdId, 'general');
        $this->recipe = PdfTheme::for($this->householdId, 'recipe');
        $this->dispatch('toast', message: __('PDF design saved.'));
    }

    public function resetDesign(): void
    {
        $this->general = PdfTheme::defaults('general');
        $this->recipe = PdfTheme::defaults('recipe');
    }

    public function with(): array
    {
        $recipes = $this->recipes();
        $previewId = $recipes->contains('id', $this->previewRecipeId) ? $this->previewRecipeId : $recipes->first()?->id;
        $draft = json_encode(['general' => PdfTheme::clean('general', $this->general), 'recipe' => PdfTheme::clean('recipe', $this->recipe)]);

        return [
            'recipes' => $recipes,
            'previewUrl' => $previewId ? route('recipes.pdf', ['recipe' => $previewId, 'format' => 'html', 'draft' => $draft]) : null,
            'pdfUrl' => $previewId ? route('recipes.pdf', ['recipe' => $previewId, 'inline' => 1, 'draft' => $draft]) : null,
            'fonts' => PdfTheme::FONTS,
            'colors' => PdfTheme::COLORS,
        ];
    }
}; ?>

@php
    $card = 'rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
    $segment = fn (bool $active) => $active ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-600 dark:text-white' : 'text-gray-600 hover:text-gray-900 dark:text-gray-300';
@endphp

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('PDF design') }}</h2>
            <x-primary-button type="button" wire:click="save" class="shrink-0">{{ __('Save') }}</x-primary-button>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-5 lg:px-8">
            <div class="space-y-5 lg:col-span-2">
                <section class="{{ $card }} space-y-4">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('All PDFs') }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Recipes, reports, emergency folder, invoices, letters …') }}</p>
                    </div>

                    <div>
                        <span class="{{ $label }}">{{ __('Accent colour') }}</span>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            @foreach ($colors as $color)
                                <button type="button" wire:click="$set('general.accent', '{{ $color }}')" class="h-8 w-8 rounded-full ring-2 ring-offset-2 dark:ring-offset-gray-800 {{ $general['accent'] === $color ? 'ring-gray-900 dark:ring-white' : 'ring-transparent' }}" style="background: {{ $color }}" aria-label="{{ $color }}" aria-pressed="{{ $general['accent'] === $color ? 'true' : 'false' }}"></button>
                            @endforeach
                            <label class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <input type="color" wire:model.live.debounce.300ms="general.accent" class="h-8 w-10 cursor-pointer rounded border-0 bg-transparent p-0" aria-label="{{ __('Own colour') }}">
                                {{ __('Own') }}
                            </label>
                        </div>
                    </div>

                    <div>
                        <span class="{{ $label }}">{{ __('Font') }}</span>
                        <div class="mt-2 inline-flex flex-wrap rounded-xl bg-gray-100 p-1 dark:bg-gray-700" role="group">
                            @foreach ($fonts as $key => $font)
                                <button type="button" wire:click="$set('general.font', '{{ $key }}')" class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $segment($general['font'] === $key) }}" style="font-family: {{ $font['css'] }}">{{ $font['label'] }}</button>
                            @endforeach
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Helvetica looks like the system font. Texts with special letters (e.g. ő, ł, Greek) use DejaVu automatically.') }}</p>
                    </div>

                    <label class="{{ $label }}">{{ __('Footer') }}
                        <input type="text" wire:model.live.debounce.500ms="general.footer" maxlength="120" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" placeholder="{{ __('e.g. Family Zimmermann') }}">
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model.live="general.date" class="rounded border-gray-300 text-indigo-600"> {{ __('Date in the footer') }}</label>
                </section>

                <section class="{{ $card }} space-y-4">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🍳 {{ __('Recipe PDF') }}</h3>

                    @foreach ([
                        'layout' => [__('Layout'), ['columns' => __('Side by side'), 'stacked' => __('One below the other')]],
                        'photo' => [__('Photo'), ['large' => __('Large'), 'small' => __('Small'), 'none' => __('None')]],
                        'paper' => [__('Paper size'), ['A4' => 'A4', 'A5' => __('A5 (recipe card)')]],
                    ] as $key => [$title, $options])
                        <div>
                            <span class="{{ $label }}">{{ $title }}</span>
                            <div class="mt-2 inline-flex flex-wrap rounded-xl bg-gray-100 p-1 dark:bg-gray-700" role="group">
                                @foreach ($options as $value => $optionLabel)
                                    <button type="button" wire:click="$set('recipe.{{ $key }}', '{{ $value }}')" class="rounded-lg px-3 py-1.5 text-sm font-medium {{ $segment($recipe[$key] === $value) }}" aria-pressed="{{ $recipe[$key] === $value ? 'true' : 'false' }}">{{ $optionLabel }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <div>
                        <span class="{{ $label }}">{{ __('Show') }}</span>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            @foreach (['description' => __('Description'), 'nutrition' => __('Nutrition'), 'tags' => __('Tags'), 'source' => __('Source'), 'notes' => __('Lines for notes')] as $key => $optionLabel)
                                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model.live="recipe.{{ $key }}" class="rounded border-gray-300 text-indigo-600"> {{ $optionLabel }}</label>
                            @endforeach
                        </div>
                    </div>
                </section>

                <div class="flex flex-wrap items-center gap-3">
                    <x-primary-button type="button" wire:click="save">{{ __('Save') }}</x-primary-button>
                    <button type="button" wire:click="resetDesign" class="text-sm text-gray-500 underline">{{ __('Back to the standard design') }}</button>
                </div>
            </div>

            <section class="{{ $card }} lg:col-span-3">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Preview') }}</h3>
                    @if ($recipes->isNotEmpty())
                        <select wire:model.live="previewRecipeId" class="rounded-lg border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Recipe for the preview') }}">
                            @foreach ($recipes as $option)
                                <option value="{{ $option->id }}">{{ $option->title }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
                @if ($previewUrl)
                    <iframe src="{{ $previewUrl }}" wire:key="preview-{{ md5($previewUrl) }}" title="{{ __('Preview') }}" class="h-[75vh] w-full rounded-xl bg-gray-100 ring-1 ring-gray-200 dark:ring-gray-700"></iframe>
                    <a href="{{ $pdfUrl }}" target="_blank" rel="noopener" class="mt-2 inline-block text-xs text-indigo-600 underline dark:text-indigo-400">📄 {{ __('Open as PDF') }}</a>
                @else
                    <p class="rounded-xl bg-gray-50 p-6 text-center text-sm text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">{{ __('Add a recipe to see the preview.') }}</p>
                @endif
            </section>
        </div>
    </div>
</div>
