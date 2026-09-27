<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\Recipe;
use App\Models\ShoppingListEntry;
use App\Models\Tag;
use App\Support\Addons;
use App\Support\BrainAi;
use App\Support\Portfolio\PortfolioLink;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    use InteractsWithHousehold;

    private const MIN_QUERY_LENGTH = 2;

    private const RESULTS_PER_GROUP = 5;

    public string $query = '';

    protected function requiresHousehold(): bool
    {
        return false;
    }

    /**
     * The palette is closed most of the time; a household broadcast must not re-run the search.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [];
    }

    /**
     * Search results grouped by module, each entry with a label and a target URL.
     *
     * @return array<string, array<int, array{label: string, url: string, private: bool}>>
     */
    private function results(): array
    {
        $term = trim($this->query);

        if ($this->householdId === null || mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $entryUrl = fn (Item $item) => route('collections.show', ['collection' => $item->collection_id, 'entry' => $item->id]);

        $groups = [
            __('Pages') => $this->itemResults(Item::TYPE_NOTE, $term, fn (Item $item) => route('pages.index', ['item' => $item->id])),
            __('To-Do') => $this->itemResults(Item::TYPE_TODO, $term, $entryUrl),
            __('Collections') => $this->itemResults(Item::TYPE_ENTRY, $term, $entryUrl),
            __('Inbox') => $this->itemResults(Item::TYPE_INBOX, $term, fn () => route('inbox.index')),
            __('Recipes') => Auth::user()->canUseAddon(Addons::RECIPES)
                ? Recipe::query()->visibleTo(Auth::user(), $this->householdId)->matching($term)->orderBy('title')->limit(self::RESULTS_PER_GROUP)->get()
                    ->map(fn (Recipe $recipe) => ['label' => '🍳 '.$recipe->title, 'url' => route('recipes.show', $recipe), 'private' => $recipe->isPrivate()])
                    ->all()
                : [],
            __('Portfolio') => Auth::user()->canUseAddon(Addons::PORTFOLIO)
                ? PortfolioLink::search(Auth::user(), $term, self::RESULTS_PER_GROUP)
                : [],
            __('Shopping List') => ShoppingListEntry::where('household_id', $this->householdId)
                ->whereRaw('LOWER(label) LIKE ?', ['%'.mb_strtolower($term).'%'])
                ->orderBy('checked')
                ->limit(self::RESULTS_PER_GROUP)
                ->get()
                ->map(fn (ShoppingListEntry $entry) => [
                    'label' => $entry->label,
                    'url' => route('shopping-list.index'),
                    'private' => false,
                ])
                ->all(),
            __('Tags') => Tag::usedOnItemsVisibleTo(Auth::user(), $this->householdId)
                ->where('name', 'like', '%'.mb_strtolower(ltrim($term, '#')).'%')
                ->orderBy('name')
                ->limit(self::RESULTS_PER_GROUP)
                ->get()
                ->map(fn (Tag $tag) => [
                    'label' => '#'.$tag->name,
                    'url' => route('pages.index', ['tag' => $tag->name]),
                    'private' => false,
                ])
                ->all(),
        ];

        return array_filter($groups);
    }

    /**
     * @param  callable(Item): string  $url
     * @return array<int, array{label: string, url: string, private: bool}>
     */
    private function itemResults(string $type, string $term, callable $url): array
    {
        return $this->visibleItems($type)
            ->matching($term)
            ->latest('updated_at')
            ->limit(self::RESULTS_PER_GROUP)
            ->get()
            ->map(fn (Item $item) => [
                'label' => $item->title,
                'url' => $url($item),
                'private' => $item->isPrivate(),
            ])
            ->all();
    }

    public function with(): array
    {
        return [
            'groups' => $this->results(),
            'searched' => mb_strlen(trim($this->query)) >= self::MIN_QUERY_LENGTH,
            'canAsk' => $this->householdId !== null && BrainAi::isConfigured(),
        ];
    }
}; ?>

<div
    x-data="{
        open: false,
        show() {
            this.open = true;
            this.$nextTick(() => this.$refs.input.focus());
        },
        results() {
            return [...this.$refs.results.querySelectorAll('[data-search-result]')];
        },
        move(step) {
            const results = this.results();
            if (results.length === 0) return;
            const index = results.indexOf(document.activeElement);
            const next = index === -1 ? (step > 0 ? 0 : results.length - 1) : (index + step + results.length) % results.length;
            results[next].focus();
        },
    }"
    x-on:keydown.window.meta.k.prevent="show()"
    x-on:keydown.window.ctrl.k.prevent="show()"
    x-on:keydown.escape.window="open = false"
    x-on:open-search.window="show()"
>
    <div
        x-show="open"
        x-cloak
        x-transition.opacity
        class="fixed inset-0 z-50 bg-gray-900/50 flex items-start justify-center p-4 pt-[10vh]"
        x-on:click.self="open = false"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Search') }}"
    >
        <div class="w-full max-w-xl bg-white dark:bg-gray-800 rounded-lg shadow-xl overflow-hidden" x-on:keydown.down.prevent="move(1)" x-on:keydown.up.prevent="move(-1)">
            <input
                x-ref="input"
                wire:model.live.debounce.250ms="query"
                type="search"
                placeholder="{{ __('Search pages, tasks, inbox, shopping list...') }}"
                class="w-full border-0 border-b border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 px-4 py-3 focus:ring-0"
                x-on:keydown.enter.prevent="results()[0]?.click()"
            />

            <div x-ref="results" class="max-h-[60vh] overflow-y-auto">
                @forelse ($groups as $group => $entries)
                    <div class="py-2" wire:key="search-group-{{ $loop->index }}">
                        <h4 class="px-4 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $group }}</h4>
                        @foreach ($entries as $entry)
                            <a
                                href="{{ $entry['url'] }}"
                                wire:navigate
                                data-search-result
                                x-on:click="open = false"
                                class="flex items-center gap-2 px-4 py-2 text-gray-900 dark:text-gray-100 hover:bg-indigo-50 dark:hover:bg-gray-700 focus:bg-indigo-50 dark:focus:bg-gray-700 focus:outline-none"
                            >
                                <span class="truncate">{{ $entry['label'] }}</span>
                                @if ($entry['private'])
                                    <x-private-badge />
                                @endif
                            </a>
                        @endforeach
                    </div>
                @empty
                    <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">
                        {{ $searched ? __('No results.') : __('Type at least two characters.') }}
                    </p>
                @endforelse
                @if ($searched && $canAsk)
                    <a
                        href="{{ route('brain.ask', ['q' => trim($query)]) }}"
                        wire:navigate
                        data-search-result
                        x-on:click="open = false"
                        class="flex items-center gap-2 px-4 py-2 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-50 dark:hover:bg-gray-700 focus:bg-indigo-50 dark:focus:bg-gray-700 focus:outline-none"
                    >✨ <span class="truncate">{{ __('Ask your Second Brain: “:question”', ['question' => trim($query)]) }}</span></a>
                @endif
            </div>

            <p class="px-4 py-2 text-xs text-gray-400 border-t border-gray-100 dark:border-gray-700">
                {{ __('↑↓ to navigate, Enter to open, Esc to close') }}
            </p>
        </div>
    </div>
</div>
