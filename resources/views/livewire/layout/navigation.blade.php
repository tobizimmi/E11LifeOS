<?php

use App\Livewire\Actions\Logout;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Collection;
use App\Models\Household;
use App\Models\Item;
use App\Models\Space;
use App\Support\Addons;
use App\Support\InAppNotifications;
use App\Support\PageMover;
use App\Support\PageTree;
use App\Support\TaskLists;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component
{
    use InteractsWithHousehold;

    /**
     * The page open in the main area, highlighted and expanded in the tree.
     */
    #[Locked]
    public ?int $currentPageId = null;

    #[Locked]
    public ?int $currentCollectionId = null;

    /**
     * @var array<string, string>
     */
    protected $listeners = ['item-captured' => '$refresh', 'notifications-read' => '$refresh'];

    protected function requiresHousehold(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $item = request()->route('item');

        if (request()->routeIs('pages.index') && is_numeric($item)) {
            $this->currentPageId = (int) $item;
        }

        $collection = request()->route('collection');

        if (request()->routeIs('collections.show') && is_numeric($collection)) {
            $this->currentCollectionId = (int) $collection;
        }
    }

    /**
     * Drag & drop in the page tree: new parent (null = top level) and index among its children.
     */
    public function movePage(int $pageId, ?int $parentId, int $index): void
    {
        $page = $this->findVisibleItem(Item::TYPE_NOTE, $pageId);

        if (! PageMover::move($page, $parentId, $index, Auth::user())) {
            $this->dispatch('toast', message: __('This page cannot be moved there.'));
        }
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }

    public function with(): array
    {
        if ($this->householdId === null) {
            return ['tree' => null, 'sharedRoots' => [], 'privateRoots' => [], 'openIds' => [], 'inboxCount' => 0, 'shoppingCount' => 0, 'hasRecipes' => false, 'hasPortfolio' => false, 'tasksCollection' => null, 'todayTaskCount' => 0, 'collections' => collect(), 'spaces' => collect(), 'unreadNotifications' => InAppNotifications::unreadCount(Auth::user())];
        }

        $tree = new PageTree(
            $this->visibleItems(Item::TYPE_NOTE)->get(['id', 'parent_id', 'title', 'icon', 'visibility', 'created_by', 'position'])
        );
        $roots = collect($tree->roots());
        $tasksCollection = Collection::tasksFor($this->householdId)->load('properties');

        return [
            'tree' => $tree,
            'sharedRoots' => $roots->reject(fn (Item $page) => $page->isPrivate())->values()->all(),
            'privateRoots' => $roots->filter(fn (Item $page) => $page->isPrivate())->values()->all(),
            'openIds' => $this->currentPageId
                ? collect($tree->ancestors($this->currentPageId))->pluck('id')->push($this->currentPageId)->all()
                : [],
            'inboxCount' => $this->visibleItems(Item::TYPE_INBOX)->count(),
            'shoppingCount' => \App\Models\ShoppingListEntry::where('household_id', $this->householdId)->where('checked', false)->count(),
            'hasRecipes' => Auth::user()->canUseAddon(Addons::RECIPES),
            'hasPortfolio' => Auth::user()->canUseAddon(Addons::PORTFOLIO),
            'hasFinance' => Auth::user()->canUseAddon(Addons::FINANCE),
            'hasBusiness' => Auth::user()->canUseAddon(Addons::BUSINESS),
            'hasRealEstate' => Auth::user()->canUseAddon(Addons::REAL_ESTATE),
            'tasksCollection' => $tasksCollection,
            'unreadNotifications' => InAppNotifications::unreadCount(Auth::user()),
            'hasNetWorth' => Auth::user()->canUseAddon(Addons::FINANCE) || Auth::user()->canUseAddon(Addons::PORTFOLIO) || Auth::user()->canUseAddon(Addons::REAL_ESTATE),
            'todayTaskCount' => (new TaskLists($tasksCollection, Auth::user()))->counts()[TaskLists::TODAY],
            'collections' => Collection::query()
                ->visibleTo(Auth::user(), $this->householdId)
                ->whereNull('system_key')
                ->orderBy('name')
                ->get(['id', 'name', 'icon', 'visibility']),
            'spaces' => Space::query()->visibleTo(Auth::user(), $this->householdId)->get(['id', 'name', 'icon']),
        ];
    }
}; ?>

<div x-data="{ sidebarOpen: false }" x-on:keydown.escape.window="sidebarOpen = false" x-on:open-sidebar.window="sidebarOpen = true">
    {{-- Mobile top bar: fixed, so the menu button stays reachable while scrolling (spacer below keeps the content clear of it) --}}
    <div class="lg:hidden h-14" aria-hidden="true"></div>
    <div class="e11-material lg:hidden fixed inset-x-0 top-0 z-30 flex h-14 items-center justify-between gap-2 border-b bg-white/80 backdrop-blur transition-colors duration-200 dark:bg-gray-900/80 px-3" :class="$store.chrome.compact ? 'border-gray-200/70 dark:border-gray-800' : 'border-transparent'">
        <button type="button" x-on:click="sidebarOpen = true" class="p-2 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('Open menu') }}">
            <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
        <div class="relative flex min-w-0 flex-1 justify-center">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2 font-semibold text-gray-800 transition duration-200 dark:text-gray-200" :class="$store.chrome.compact && $store.chrome.title ? 'opacity-0 -translate-y-1 pointer-events-none' : ''">
                <x-application-logo class="h-7 w-auto fill-current" />
                {{ config('app.name') }}
            </a>
            <span x-cloak x-show="$store.chrome.compact && $store.chrome.title" x-text="$store.chrome.title"
                x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                class="absolute inset-x-0 top-1/2 -translate-y-1/2 truncate text-center text-[17px] font-semibold text-gray-900 dark:text-gray-100"></span>
        </div>
        <div class="flex items-center">
            <x-notification-bell :count="$unreadNotifications" />
            <button type="button" x-on:click="$dispatch('open-search')" class="p-2 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('Search') }}">
                <x-search-icon class="h-6 w-6" />
            </button>
        </div>
    </div>

    {{-- Backdrop (mobile) --}}
    <div id="e11-drawer-backdrop" x-show="sidebarOpen" x-cloak x-transition.opacity.duration.300ms x-on:click="sidebarOpen = false" class="lg:hidden fixed inset-0 z-40 bg-gray-900/40"></div>

    {{-- Sidebar --}}
    <aside
        class="e11-material fixed inset-y-0 start-0 z-50 flex w-72 lg:w-64 flex-col border-e border-gray-200/70 dark:border-gray-800 bg-white/90 backdrop-blur dark:bg-gray-900 transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] lg:translate-x-0"
        x-data="e11Drawer"
        x-on:pointerdown="drawerStart($event)"
        x-on:pointermove="drawerMove($event)"
        x-on:pointerup="drawerEnd()"
        x-on:pointercancel="drawerEnd()"
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        x-cloak
        aria-label="{{ __('Main navigation') }}"
    >
        <div class="flex h-14 items-center justify-between gap-2 px-4 border-b border-gray-100 dark:border-gray-800">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-200">
                <x-application-logo class="h-7 w-auto fill-current" />
                {{ config('app.name') }}
            </a>
            <x-notification-bell :count="$unreadNotifications" class="hidden lg:block" />
            <button type="button" x-on:click="sidebarOpen = false" class="lg:hidden p-1 text-gray-400 hover:text-gray-600" aria-label="{{ __('Close menu') }}">
                <svg class="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-5">
            <div class="space-y-0.5">
                <button
                    type="button"
                    x-on:click="sidebarOpen = false; $dispatch('open-search')"
                    class="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700/60"
                >
                    <span class="flex items-center gap-2"><x-search-icon /> {{ __('Search') }}</span>
                    <kbd class="hidden lg:inline text-xs text-gray-400">Ctrl K</kbd>
                </button>
                <x-sidebar-link icon="home" :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>{{ __('Dashboard') }}</x-sidebar-link>
                <x-sidebar-link icon="inbox" :href="route('inbox.index')" :active="request()->routeIs('inbox.index')" :badge="$inboxCount ?: null" wire:navigate>{{ __('Inbox') }}</x-sidebar-link>
                <x-sidebar-link icon="sparkles" :href="route('brain.ask')" :active="request()->routeIs('brain.ask')" wire:navigate>{{ __('Ask your Second Brain') }}</x-sidebar-link>
            </div>

            @if ($householdId !== null)
                <div class="space-y-0.5">
                    <h3 class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('Everyday') }}</h3>
                    <x-sidebar-link icon="check-circle" :href="route('tasks.index')" :active="request()->routeIs('tasks.index') || $currentCollectionId === $tasksCollection->id" :badge="$todayTaskCount ?: null" wire:navigate>{{ __('To-Do') }}</x-sidebar-link>
                    <x-sidebar-link icon="calendar-days" :href="route('calendar.index')" :active="request()->routeIs('calendar.*')" wire:navigate>{{ __('Calendar') }}</x-sidebar-link>
                    <x-sidebar-link icon="shopping-cart" :href="route('shopping-list.index')" :active="request()->routeIs('shopping-list.*')" :badge="$shoppingCount ?: null" wire:navigate>{{ __('Shopping List') }}</x-sidebar-link>
                    @if ($hasRecipes)
                        <x-sidebar-link icon="book-open" :href="route('recipes.index')" :active="request()->routeIs('recipes.*')" wire:navigate>{{ __('Recipes') }}</x-sidebar-link>
                    @endif
                </div>
                <div class="space-y-0.5">
                    <h3 class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('Money & home') }}</h3>
                    @if ($hasFinance)
                        <x-sidebar-link icon="banknotes" :href="route('finance.index')" :active="request()->routeIs('finance.*')" wire:navigate>{{ __('Finances') }}</x-sidebar-link>
                    @endif
                    @if ($hasRealEstate)
                        <x-sidebar-link icon="building-office-2" :href="route('real-estate.index')" :active="request()->routeIs('real-estate.*')" wire:navigate>{{ __('Real estate') }}</x-sidebar-link>
                    @endif
                    @if ($hasPortfolio)
                        <x-sidebar-link icon="arrow-trending-up" :href="route('portfolio.index')" :active="request()->routeIs('portfolio.*')" wire:navigate>{{ __('Portfolio') }}</x-sidebar-link>
                    @endif
                    @if ($hasBusiness)
                        <x-sidebar-link icon="briefcase" :href="route('business.index')" :active="request()->routeIs('business.*')" wire:navigate>{{ __('Small business') }}</x-sidebar-link>
                    @endif
                    @if ($hasNetWorth)
                        <x-sidebar-link icon="chart-pie" :href="route('net-worth.index')" :active="request()->routeIs('net-worth.*')" wire:navigate>{{ __('Net worth') }}</x-sidebar-link>
                        <x-sidebar-link icon="arrows-right-left" :href="route('cashflow.index')" :active="request()->routeIs('cashflow.*')" wire:navigate>{{ __('Cashflow analysis') }}</x-sidebar-link>
                    @endif
                    <x-sidebar-link icon="light-bulb" :href="route('knowledge.index')" :active="request()->routeIs('knowledge.*')" wire:navigate>{{ __('Money knowledge') }}</x-sidebar-link>
                    <x-sidebar-link icon="scale" :href="route('quotes.index')" :active="request()->routeIs('quotes.*')" wire:navigate>{{ __('Quote comparisons') }}</x-sidebar-link>
                    <x-sidebar-link icon="document-text" :href="route('contracts.index')" :active="request()->routeIs('contracts.*') || request()->routeIs('letters.*')" wire:navigate>{{ __('Contract costs') }}</x-sidebar-link>
                </div>
                <div class="space-y-0.5">
                    <h3 class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('Life & provisions') }}</h3>
                    <x-sidebar-link icon="shield-check" :href="route('emergency.index')" :active="request()->routeIs('emergency.*')" wire:navigate>{{ __('Emergency folder') }}</x-sidebar-link>
                    <x-sidebar-link icon="scale" :href="route('provision.index')" :active="request()->routeIs('provision.*')" wire:navigate>{{ __('Provision check') }}</x-sidebar-link>
                    <x-sidebar-link icon="truck" :href="route('vehicles.index')" :active="request()->routeIs('vehicles.*')" wire:navigate>{{ __('Vehicles') }}</x-sidebar-link>
                    <x-sidebar-link icon="wrench-screwdriver" :href="route('house.index')" :active="request()->routeIs('house.*')" wire:navigate>{{ __('At home') }}</x-sidebar-link>
                    <x-sidebar-link icon="heart" :href="route('health.index')" :active="request()->routeIs('health.*')" wire:navigate>{{ __('Health') }}</x-sidebar-link>
                    <x-sidebar-link icon="cake" :href="route('people.index')" :active="request()->routeIs('people.*')" wire:navigate>{{ __('People & occasions') }}</x-sidebar-link>
                    <x-sidebar-link icon="paper-airplane" :href="route('trips.index')" :active="request()->routeIs('trips.*')" wire:navigate>{{ __('Trips') }}</x-sidebar-link>
                    <x-sidebar-link icon="calculator" :href="route('splits.index')" :active="request()->routeIs('splits.*')" wire:navigate>{{ __('Split costs') }}</x-sidebar-link>
                    <x-sidebar-link icon="star" :href="route('kids.index')" :active="request()->routeIs('kids.*')" wire:navigate>{{ __('Kids & pocket money') }}</x-sidebar-link>
                    <x-sidebar-link icon="sparkles" :href="route('review.index')" :active="request()->routeIs('review.*')" wire:navigate>{{ __('Year in review') }}</x-sidebar-link>
                </div>
                <div class="space-y-0.5">
                    <h3 class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('Household') }}</h3>
                    <x-sidebar-link icon="user-group" :href="route('household.show')" :active="request()->routeIs('household.show')" wire:navigate>{{ __('Members') }}</x-sidebar-link>
                </div>

                <x-sidebar-section :title="__('Spaces')" :add-href="route('spaces.index', ['create' => 1])" :all-href="route('spaces.index')" :add-label="__('New space')">
                    @forelse ($spaces as $sidebarSpace)
                        <x-sidebar-link :href="route('spaces.show', $sidebarSpace)" :active="request()->routeIs('spaces.show') && (int) request()->route('space')?->id === $sidebarSpace->id" wire:navigate wire:key="sidebar-space-{{ $sidebarSpace->id }}">
                            {{ $sidebarSpace->icon }} {{ $sidebarSpace->name }}
                        </x-sidebar-link>
                    @empty
                        <p class="px-2 text-xs text-gray-400">{{ __('Group pages and tasks, e.g. Work, Home, Club.') }}</p>
                    @endforelse
                </x-sidebar-section>

                <x-sidebar-section :title="__('Pages')" :add-href="route('pages.index', ['create' => 1])" :all-href="route('pages.index')">
                    @if ($sharedRoots !== [])
                        <x-page-tree :tree="$tree" :pages="$sharedRoots" :current-id="$currentPageId" :open-ids="$openIds" sort-group="pages-shared" />
                    @else
                        <p class="px-2 text-xs text-gray-400">{{ __('No shared pages yet.') }}</p>
                    @endif
                </x-sidebar-section>

                <x-sidebar-section :title="__('Private')" :add-href="route('pages.index', ['create' => 1, 'private' => 1])">
                    @if ($privateRoots !== [])
                        <x-page-tree :tree="$tree" :pages="$privateRoots" :current-id="$currentPageId" :open-ids="$openIds" :private-section="true" sort-group="pages-private" />
                    @else
                        <p class="px-2 text-xs text-gray-400">{{ __('Only you can see pages here.') }}</p>
                    @endif
                </x-sidebar-section>

                <x-sidebar-section :title="__('Collections')" :add-href="route('collections.index', ['create' => 1])" :all-href="route('collections.index')" :add-label="__('New collection')">
                    @forelse ($collections as $sidebarCollection)
                        <x-sidebar-link :href="route('collections.show', $sidebarCollection)" :active="$currentCollectionId === $sidebarCollection->id" wire:navigate wire:key="sidebar-collection-{{ $sidebarCollection->id }}">
                            {{ $sidebarCollection->displayIcon() }} {{ $sidebarCollection->name }}
                        </x-sidebar-link>
                    @empty
                        <p class="px-2 text-xs text-gray-400">{{ __('Tables and boards with your own properties.') }}</p>
                    @endforelse
                </x-sidebar-section>
            @endif
        </nav>

        <div class="border-t border-gray-100 dark:border-gray-700 p-3" x-data="{ menuOpen: false }">
            <button type="button" x-on:click="menuOpen = ! menuOpen" class="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/60" :aria-expanded="menuOpen">
                <span class="min-w-0 text-start">
                    <span class="block truncate font-medium" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></span>
                    <span class="block truncate text-xs text-gray-500">{{ auth()->user()->email }}</span>
                </span>
                <svg class="h-4 w-4 shrink-0 transition-transform" :class="menuOpen && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 01-1.06-.02L10 8.832 6.29 12.77a.75.75 0 11-1.08-1.04l4.25-4.5a.75.75 0 011.08 0l4.25 4.5a.75.75 0 01-.02 1.06z" clip-rule="evenodd" />
                </svg>
            </button>
            <div x-show="menuOpen" x-cloak class="mt-1 space-y-0.5">
                <x-sidebar-link :href="route('profile')" :active="request()->routeIs('profile')" wire:navigate>{{ __('Profile') }}</x-sidebar-link>
                @can('admin')
                    <x-sidebar-link :href="route('admin.settings')" :active="request()->routeIs('admin.settings')" wire:navigate>{{ __('Server settings') }}</x-sidebar-link>
                @endcan
                <button type="button" wire:click="logout" class="w-full rounded-md px-2 py-1.5 text-start text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/60">
                    {{ __('Log Out') }}
                </button>
            </div>
        </div>
    </aside>

    <livewire:search-palette />
</div>
