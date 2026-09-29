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
            __('Real estate') => \App\Support\RealEstate\RealEstateLink::search(Auth::user(), $this->householdId, $term, self::RESULTS_PER_GROUP),
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

    /**
     * Quick actions on the typed text: create a task, a shopping item or an inbox entry.
     */
    public function createTask(): void
    {
        abort_if($this->householdId === null || trim($this->query) === '', 422);
        $task = \App\Support\QuickCapture::task(Auth::user(), $this->householdId, trim($this->query));
        $this->finish($task ? __('Task “:title” created.', ['title' => $task->title]) : __('Nothing to create.'));
    }

    public function addToShopping(): void
    {
        abort_if($this->householdId === null || trim($this->query) === '', 422);
        $list = \App\Models\ShoppingList::defaultFor($this->householdId);
        \App\Support\ShoppingLists::add($list, trim($this->query), null, Auth::id());
        $this->finish(__(':item is on the list “:list”.', ['item' => trim($this->query), 'list' => $list->name]));
    }

    public function addToInbox(): void
    {
        abort_if($this->householdId === null || trim($this->query) === '', 422);
        \App\Support\QuickCapture::inbox(Auth::user(), $this->householdId, trim($this->query));
        $this->finish(__('Saved to the inbox.'));
    }

    private function finish(string $message): void
    {
        $this->query = '';
        $this->dispatch('toast', message: $message);
        $this->dispatch('close-search');
        $this->dispatch('item-captured');
    }

    /**
     * Places to jump to, filtered by the typed text (all of them while nothing is typed).
     *
     * @return list<array{label: string, url: string, keywords: string}>
     */
    private function destinations(): array
    {
        $user = Auth::user();
        $places = [
            ['🏠 '.__('Dashboard'), route('dashboard'), 'home start overview übersicht'],
            ['✅ '.__('To-Do'), route('tasks.index'), 'tasks aufgaben todo'],
            ['📥 '.__('Inbox'), route('inbox.index'), 'inbox eingang'],
            ['📅 '.__('Calendar'), route('calendar.index'), 'kalender termine calendar monat woche'],
            ['🔔 '.__('Notifications'), route('notifications.index'), 'benachrichtigungen glocke notifications'],
            ['💎 '.__('Net worth'), route('net-worth.index'), 'vermögen nettovermögen net worth schulden'],
            ['📑 '.__('Contract costs'), route('contracts.index'), 'verträge kosten kündigung fristen contracts'],
            ['⚖️ '.__('Quote comparisons'), route('quotes.index'), 'angebote vergleich angebotsvergleich quotes kaufen anschaffung handwerker'],
            ['✉️ '.__('Letters'), route('letters.index'), 'brief kündigung widerspruch adresse letter vorlage'],
            ['🛒 '.__('Shopping List'), route('shopping-list.index'), 'einkauf shopping liste'],
            ['🏷️ '.__('Offers'), route('shopping-list.offers'), 'angebote prospekt offers'],
            ['📄 '.__('New page'), route('pages.index', ['create' => 1]), 'seite page notiz note neu'],
            ['🗂 '.__('Collections'), route('collections.index'), 'sammlungen collections'],
            ['✨ '.__('Ask your Second Brain'), route('brain.ask'), 'ki ai frage'],
            ['👥 '.__('Members'), route('household.show'), 'haushalt mitglieder members'],
            ['⚙️ '.__('Profile'), route('profile'), 'profil einstellungen settings'],
        ];

        if ($user->canUseAddon(Addons::RECIPES)) {
            $places[] = ['🍳 '.__('Recipes'), route('recipes.index'), 'rezepte kochen recipes'];
            $places[] = ['📅 '.__('Meal plan'), route('recipes.plan'), 'wochenplan essen meal plan'];
            $places[] = ['➕ '.__('New recipe'), route('recipes.create'), 'rezept neu recipe'];
        }

        if ($user->canUseAddon(Addons::FINANCE)) {
            $places[] = ['💶 '.__('Finances'), route('finance.index'), 'finanzen geld konto finance'];
        }

        if ($user->canUseAddon(Addons::REAL_ESTATE)) {
            $places[] = ['🏠 '.__('Real estate'), route('real-estate.index'), 'immobilien haus miete'];
        }

        if ($user->canUseAddon(Addons::PORTFOLIO)) {
            $places[] = ['📈 '.__('Portfolio'), route('portfolio.index'), 'depot aktien portfolio'];
        }

        if ($user->canUseAddon(Addons::BUSINESS)) {
            $places[] = ['💼 '.__('Small business'), route('business.index'), 'business gewerbe'];
            $places[] = ['🧾 '.__('New invoice'), route('business.invoices.create'), 'rechnung invoice neu'];
        }

        if ($user->isAdmin()) {
            $places[] = ['🛠 '.__('Server settings'), route('admin.settings'), 'server admin einstellungen vpn'];
        }

        $term = mb_strtolower(trim($this->query));

        return collect($places)
            ->map(fn (array $place) => ['label' => $place[0], 'url' => $place[1], 'keywords' => mb_strtolower($place[0].' '.$place[2])])
            ->filter(fn (array $place) => $term === '' || str_contains($place['keywords'], $term))
            ->take($term === '' ? 12 : 5)
            ->values()->all();
    }

    public function with(): array
    {
        return [
            'destinations' => $this->householdId !== null ? $this->destinations() : [],
            'typed' => trim($this->query),
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
    x-on:close-search.window="open = false"
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
                placeholder="{{ __('Search, jump to or create …') }}"
                class="w-full border-0 border-b border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 px-4 py-3 focus:ring-0"
                x-on:keydown.enter.prevent="results()[0]?.click()"
            />

            <div x-ref="results" class="max-h-[60vh] overflow-y-auto">
                @if ($typed !== '')
                    <div class="py-2">
                        <h4 class="px-4 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Create') }}</h4>
                        @foreach ([['createTask', '✅', __('Task: “:text”', ['text' => $typed])], ['addToShopping', '🛒', __('Shopping list: “:text”', ['text' => $typed])], ['addToInbox', '📥', __('Inbox: “:text”', ['text' => $typed])]] as [$method, $icon, $label])
                            <button type="button" wire:click="{{ $method }}" data-search-result class="flex w-full items-center gap-2 px-4 py-2 text-start text-gray-900 hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none dark:text-gray-100 dark:hover:bg-gray-700 dark:focus:bg-gray-700">
                                <span aria-hidden="true">{{ $icon }}</span> <span class="truncate">{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
                @if ($destinations !== [])
                    <div class="py-2">
                        <h4 class="px-4 py-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Go to') }}</h4>
                        @foreach ($destinations as $destination)
                            <a href="{{ $destination['url'] }}" wire:navigate data-search-result x-on:click="open = false" class="flex items-center gap-2 px-4 py-2 text-gray-900 hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none dark:text-gray-100 dark:hover:bg-gray-700 dark:focus:bg-gray-700">
                                <span class="truncate">{{ $destination['label'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
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
                    @if ($searched)
                        <p class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No results.') }}</p>
                    @endif
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
                {{ __('↑↓ to navigate, Enter to open, Esc to close') }} · {{ __('? shows all shortcuts') }}
            </p>
        </div>
    </div>
</div>
