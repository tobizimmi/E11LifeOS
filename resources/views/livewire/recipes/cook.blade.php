<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Recipe;
use App\Support\Pantry;
use App\Support\StepTimers;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $recipeId;

    /**
     * Servings chosen on the recipe page (portion calculator).
     */
    #[Url]
    public ?int $servings = null;

    public bool $finished = false;

    /**
     * What the cooking takes from the pantry (confirmed on the last screen).
     *
     * @var list<array{item_id: int, name: string, ingredient: string, before: float, used: float, after: float, unit: string, low: bool}>
     */
    #[Locked]
    public array $pantryUsage = [];

    public int $rating = 0;

    public string $comment = '';

    public function mount(int $recipe): void
    {
        $this->resolveHousehold();

        $model = $this->findRecipe($recipe);
        $this->recipeId = $model->id;
        $this->servings = $this->servings ? max(1, min(99, $this->servings)) : $model->servings;
        $this->rating = (int) $model->ratings()->where('user_id', Auth::id())->value('rating');
    }

    private function findRecipe(?int $id = null): Recipe
    {
        return Recipe::query()->visibleTo(Auth::user(), (int) $this->householdId)->findOrFail($id ?? $this->recipeId);
    }

    /**
     * Last step done: count the cooking and ask for a rating.
     */
    public function finish(): void
    {
        if (! $this->finished) {
            $recipe = $this->findRecipe();
            $recipe->markCooked();
            $this->pantryUsage = Pantry::usage($recipe, $this->servings ?: max(1, (int) $recipe->servings), Auth::user(), (int) $this->householdId);
            $this->finished = true;
        }
    }

    public function takeFromPantry(): void
    {
        Pantry::take($this->pantryUsage, (int) $this->householdId);
        $this->pantryUsage = [];
        $this->dispatch('toast', message: __('The pantry is updated.'));
    }

    public function rate(): void
    {
        $this->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->findRecipe()->rateBy(Auth::user(), $this->rating, $this->comment, cookedToday: true);

        $this->redirectRoute('recipes.show', ['recipe' => $this->recipeId], navigate: true);
    }

    public function with(): array
    {
        $recipe = $this->findRecipe();

        return [
            'recipe' => $recipe,
            'ingredients' => $recipe->scaledIngredients($this->servings),
            'steps' => collect($recipe->steps())
                ->map(fn (string $text) => ['text' => $text, 'timers' => StepTimers::detect($text)])
                ->all(),
        ];
    }
}; ?>

<div
    x-data="cookingMode({{ count($steps) }})"
    x-on:keydown.window="onKey($event)"
    class="fixed inset-0 z-[60] flex flex-col bg-gray-950 text-gray-100"
>
    {{-- Top bar --}}
    <div class="flex items-center gap-3 border-b border-gray-800 px-4 py-3">
        <a href="{{ route('recipes.show', $recipe) }}" wire:navigate class="rounded-md px-2 py-1 text-gray-400 hover:bg-gray-800 hover:text-white" aria-label="{{ __('Close cooking mode') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></a>
        <div class="min-w-0 flex-1">
            <p class="truncate font-semibold">{{ $recipe->title }}</p>
            <p class="text-xs text-gray-400" x-text="step === 0 ? @js(__('Ingredients')) : @js(__('Step')) + ' ' + step + ' / ' + total"></p>
        </div>
        <span class="hidden text-xs text-gray-500 sm:inline" x-show="wakeLock" x-cloak>☀ {{ __('Screen stays on') }}</span>
    </div>
    <div class="h-1 bg-gray-800" aria-hidden="true">
        <div class="h-1 bg-indigo-500 transition-all" :style="'width: ' + (total ? step / total * 100 : 100) + '%'"></div>
    </div>

    @if ($finished)
        {{-- Done: rate the recipe --}}
        <div class="flex flex-1 items-center justify-center overflow-y-auto p-6">
            <form wire:submit="rate" class="w-full max-w-md space-y-5 text-center">
                <p class="text-5xl" aria-hidden="true">🎉</p>
                <h2 class="text-2xl font-bold">{{ __('Enjoy your meal!') }}</h2>
                @if ($pantryUsage !== [])
                    <div class="rounded-2xl bg-gray-900 p-4 text-left ring-1 ring-gray-700">
                        <p class="text-sm font-semibold">🥫 {{ __('Take from the pantry?') }}</p>
                        <ul class="mt-2 space-y-1 text-sm text-gray-300">
                            @foreach ($pantryUsage as $use)
                                <li class="flex justify-between gap-2" wire:key="cook-pantry-{{ $use['item_id'] }}"><span>{{ $use['name'] }}</span><span class="tabular-nums">{{ \App\Support\IngredientAmount::format($use['before']) }} → {{ \App\Support\IngredientAmount::format($use['after']) }} {{ $use['unit'] }}</span></li>
                            @endforeach
                        </ul>
                        <button type="button" wire:click="takeFromPantry" class="mt-3 rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-500">{{ __('Take from the pantry') }}</button>
                    </div>
                @endif
                <p class="text-gray-400">{{ __('How did it turn out?') }}</p>
                <div class="flex justify-center gap-2" role="radiogroup" aria-label="{{ __('Rating') }}">
                    @for ($star = 1; $star <= 5; $star++)
                        <button type="button" wire:click="$set('rating', {{ $star }})" role="radio" aria-checked="{{ $rating === $star ? 'true' : 'false' }}" aria-label="{{ trans_choice(':count star|:count stars', $star) }}" @class(['text-4xl', 'text-amber-400' => $star <= $rating, 'text-gray-600' => $star > $rating])>★</button>
                    @endfor
                </div>
                <x-input-error :messages="$errors->get('rating')" />
                <textarea wire:model="comment" rows="3" class="block w-full rounded-md border-gray-700 bg-gray-900 text-gray-100" placeholder="{{ __('Note for next time (optional)') }}" aria-label="{{ __('Comment') }}"></textarea>
                <div class="flex items-center justify-center gap-4">
                    <button type="submit" class="rounded-md bg-indigo-600 px-5 py-2.5 font-medium text-white hover:bg-indigo-500">{{ __('Save rating') }}</button>
                    <a href="{{ route('recipes.show', $recipe) }}" wire:navigate class="text-sm text-gray-400 underline">{{ __('Skip') }}</a>
                </div>
            </form>
        </div>
    @else
        <div class="flex-1 overflow-y-auto">
            <div class="mx-auto max-w-3xl px-5 py-8 sm:py-12">
                {{-- Step 0: ingredients --}}
                <section x-show="step === 0">
                    <h2 class="text-2xl font-bold">{{ __('Ingredients') }}</h2>
                    @if ($servings)
                        <p class="mt-1 text-gray-400">{{ trans_choice(':count serving|:count servings', $servings) }}</p>
                    @endif
                    <ul class="mt-6 space-y-3 text-xl" x-data="{ ticked: [] }">
                        @forelse ($ingredients as $index => $ingredient)
                            @if ($ingredient['isSection'])
                                <li class="pt-3 text-sm font-semibold uppercase tracking-wide text-gray-400">{{ $ingredient['name'] }}</li>
                            @else
                                <li>
                                    <label class="flex cursor-pointer items-baseline gap-3" :class="ticked.includes({{ $index }}) && 'line-through text-gray-500'">
                                        <input type="checkbox" class="h-5 w-5 translate-y-0.5 rounded border-gray-600 bg-gray-900 text-indigo-500" x-on:change="ticked.includes({{ $index }}) ? ticked.splice(ticked.indexOf({{ $index }}), 1) : ticked.push({{ $index }})" />
                                        <span><span class="font-semibold">{{ trim($ingredient['amount'].' '.$ingredient['unit']) }}</span> {{ $ingredient['name'] }}</span>
                                    </label>
                                </li>
                            @endif
                        @empty
                            <li class="text-gray-400">{{ __('No ingredients yet.') }}</li>
                        @endforelse
                    </ul>
                </section>

                {{-- Steps --}}
                @foreach ($steps as $number => $recipeStep)
                    <section x-show="step === {{ $number + 1 }}" x-cloak>
                        <p class="text-sm font-semibold uppercase tracking-wide text-indigo-400">{{ __('Step :number', ['number' => $number + 1]) }}</p>
                        <p class="mt-4 whitespace-pre-line text-2xl leading-relaxed sm:text-3xl">{{ $recipeStep['text'] }}</p>
                        @if ($recipeStep['timers'] !== [])
                            <div class="mt-8 flex flex-wrap gap-2">
                                @foreach ($recipeStep['timers'] as $timer)
                                    <button type="button" x-on:click="startTimer({{ $timer['seconds'] }}, @js(__('Step :number', ['number' => $number + 1]).' · '.$timer['label']))" class="rounded-full bg-indigo-600 px-4 py-2 font-medium text-white hover:bg-indigo-500">⏱ {{ $timer['label'] }}</button>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        </div>

        {{-- Timers --}}
        <div class="border-t border-gray-800 px-4 py-3">
            <div class="mx-auto flex max-w-3xl flex-wrap items-center gap-2">
                <template x-for="timer in timers" :key="timer.id">
                    <div class="flex items-center gap-2 rounded-full px-3 py-1.5" :class="timer.remaining === 0 ? 'bg-red-600 animate-pulse' : 'bg-gray-800'">
                        <span class="max-w-[10rem] truncate text-xs text-gray-300" x-text="timer.label"></span>
                        <span class="font-mono text-lg tabular-nums" x-text="format(timer.remaining)"></span>
                        <button type="button" x-show="timer.remaining > 0" x-on:click="toggleTimer(timer)" class="text-gray-300 hover:text-white" :aria-label="timer.endsAt ? @js(__('Pause')) : @js(__('Resume'))" x-text="timer.endsAt ? '⏸' : '▶'"></button>
                        <button type="button" x-on:click="removeTimer(timer)" class="text-gray-300 hover:text-white" aria-label="{{ __('Remove timer') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                    </div>
                </template>
                <span class="text-xs text-gray-500">{{ __('Timer') }}:</span>
                @foreach ([1, 5, 10, 15, 30] as $minutes)
                    <button type="button" x-on:click="startTimer({{ $minutes * 60 }}, @js(__(':count min', ['count' => $minutes])))" class="rounded-full border border-gray-700 px-3 py-1 text-sm text-gray-300 hover:bg-gray-800">{{ __(':count min', ['count' => $minutes]) }}</button>
                @endforeach
            </div>
        </div>

        {{-- Navigation --}}
        <div class="flex items-center gap-3 border-t border-gray-800 px-4 py-3">
            <button type="button" x-on:click="previous()" :disabled="step === 0" class="rounded-md border border-gray-700 px-4 py-3 text-lg disabled:opacity-30">&larr; {{ __('Back') }}</button>
            <span class="flex-1"></span>
            <button type="button" x-show="step < total" x-on:click="next()" class="rounded-md bg-indigo-600 px-6 py-3 text-lg font-medium text-white hover:bg-indigo-500">{{ __('Next') }} &rarr;</button>
            <button type="button" x-show="step >= total" x-cloak wire:click="finish" class="rounded-md bg-green-600 px-6 py-3 text-lg font-medium text-white hover:bg-green-500">✓ {{ __('Finish cooking') }}</button>
        </div>
    @endif
</div>
