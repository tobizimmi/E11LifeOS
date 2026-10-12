<?php

use App\Livewire\Actions\Logout;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Collection;
use App\Models\Household;
use App\Models\Item;
use App\Models\Space;
use App\Support\InAppNotifications;
use App\Support\Navigation;
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

        Navigation::recordVisit(Auth::user(), request()->route()?->getName());
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

        $this->redirect(route('login'), navigate: true);
    }

    /**
     * The modules of the menu with the member's pinned, hidden and often used ones.
     *
     * @return array<string, mixed>
     */
    private function navigation(?int $todayTaskCount = null, int $inboxCount = 0, int $shoppingCount = 0, ?int $tasksCollectionId = null): array
    {
        $user = Auth::user();
        $modules = Navigation::modules($user);
        $preferences = Navigation::preferences($user);

        return [
            'navModules' => $modules,
            'navHidden' => $preferences['hidden'],
            'navPinned' => array_values(array_filter($preferences['pinned'], fn (string $key) => isset($modules[$key]))),
            'navFrequent' => Navigation::frequent($user),
            'navBadges' => ['inbox' => $inboxCount, 'tasks' => $todayTaskCount, 'shopping' => $shoppingCount],
            'navActive' => fn (string $key) => isset($modules[$key]) && (request()->routeIs(...$modules[$key]['active']) || ($key === 'tasks' && $tasksCollectionId !== null && $this->currentCollectionId === $tasksCollectionId)),
        ];
    }

    public function with(): array
    {
        if ($this->householdId === null) {
            return $this->navigation() + ['tree' => null, 'sharedRoots' => [], 'privateRoots' => [], 'openIds' => [], 'inboxCount' => 0, 'shoppingCount' => 0, 'tasksCollection' => null, 'todayTaskCount' => 0, 'collections' => collect(), 'spaces' => collect(), 'unreadNotifications' => InAppNotifications::unreadCount(Auth::user())];
        }

        $tree = new PageTree(
            $this->visibleItems(Item::TYPE_NOTE)->get(['id', 'parent_id', 'title', 'icon', 'visibility', 'created_by', 'position'])
        );
        $roots = collect($tree->roots());
        $tasksCollection = Collection::tasksFor($this->householdId)->load('properties');
        $inboxCount = $this->visibleItems(Item::TYPE_INBOX)->count();
        $shoppingCount = \App\Models\ShoppingListEntry::where('household_id', $this->householdId)->where('checked', false)->count();
        $todayTaskCount = (new TaskLists($tasksCollection, Auth::user()))->counts()[TaskLists::TODAY];

        return $this->navigation($todayTaskCount, $inboxCount, $shoppingCount, $tasksCollection->id) + [
            'tree' => $tree,
            'sharedRoots' => $roots->reject(fn (Item $page) => $page->isPrivate())->values()->all(),
            'privateRoots' => $roots->filter(fn (Item $page) => $page->isPrivate())->values()->all(),
            'openIds' => $this->currentPageId
                ? collect($tree->ancestors($this->currentPageId))->pluck('id')->push($this->currentPageId)->all()
                : [],
            'inboxCount' => $inboxCount,
            'shoppingCount' => $shoppingCount,
            'tasksCollection' => $tasksCollection,
            'unreadNotifications' => InAppNotifications::unreadCount(Auth::user()),
            'todayTaskCount' => $todayTaskCount,
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
    <div class="e11-material lg:hidden fixed inset-x-0 top-0 z-30 flex h-14 items-center justify-between gap-2 border-b bg-white/80 backdrop-blur-sm transition-colors duration-200 dark:bg-gray-900/80 px-3" :class="$store.chrome.compact ? 'border-gray-200/70 dark:border-gray-800' : 'border-transparent'">
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
        class="e11-material fixed inset-y-0 start-0 z-50 flex w-72 lg:w-64 flex-col border-e border-gray-200/70 dark:border-gray-800 bg-white/90 backdrop-blur-sm dark:bg-gray-900 transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] lg:translate-x-0"
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
                @foreach ($navModules as $key => $module)
                    @if ($module['group'] === 'start' && ! in_array($key, $navHidden, true))
                        <x-sidebar-module :key="$key" :module="$module" :badge="$navBadges[$key] ?? null" :active="$navActive($key)" />
                    @endif
                @endforeach
            </div>

            @if ($householdId !== null)
                @if ($navPinned !== [] || $navFrequent !== [])
                    <div class="space-y-0.5">
                        <p class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ $navPinned !== [] ? __('Pinned') : __('Often used') }}</p>
                        @foreach ([...$navPinned, ...$navFrequent] as $key)
                            @isset($navModules[$key])
                                <x-sidebar-module :key="$key" :module="$navModules[$key]" :badge="$navBadges[$key] ?? null" :active="$navActive($key)" :pinned="in_array($key, $navPinned, true)" wire:key="nav-top-{{ $key }}" />
                            @endisset
                        @endforeach
                    </div>
                @endif

                @foreach (\App\Support\Navigation::groups() as $group => $title)
                    @continue($group === 'start')
                    @php($groupModules = collect($navModules)->filter(fn ($module, $key) => $module['group'] === $group && ! in_array($key, $navHidden, true) && ! in_array($key, $navPinned, true)))
                    @if ($groupModules->isNotEmpty())
                        <x-sidebar-group :title="$title" :section="$group" :default="$group === 'everyday'" :active="$groupModules->keys()->contains(fn ($key) => $navActive($key))">
                            @foreach ($groupModules as $key => $module)
                                <x-sidebar-module :key="$key" :module="$module" :badge="$navBadges[$key] ?? null" :active="$navActive($key)" wire:key="nav-{{ $key }}" />
                            @endforeach
                        </x-sidebar-group>
                    @endif
                @endforeach

                <a href="{{ route('navigation.settings') }}" wire:navigate class="flex items-center gap-2 rounded-md px-2 py-1.5 text-xs text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:text-gray-500 dark:hover:bg-gray-700/60 dark:hover:text-gray-300">⚙ {{ __('Customize menu') }}</a>

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
            {{-- Version with a dot until the changes of a new version were looked at. --}}
            <a href="{{ route('changelog') }}" wire:navigate class="mb-1 flex items-center gap-1.5 px-2 text-[11px] text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300"
                x-data="{ fresh: false }" x-init="fresh = (() => { try { return localStorage.getItem('e11-seen-version') !== @js(\App\Support\AppVersion::version()) } catch (e) { return false } })()" x-on:version-seen.window="fresh = false">
                <span x-show="fresh" x-cloak class="h-1.5 w-1.5 rounded-full bg-indigo-500" aria-hidden="true"></span>
                <span>{{ \App\Support\AppVersion::label() }}</span>
                <span x-show="fresh" x-cloak class="font-medium text-indigo-600 dark:text-indigo-400">{{ __('New') }}</span>
            </a>
            <a href="{{ route('support.index', ['from' => request()->path()]) }}" wire:navigate @class(['mb-1 flex items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-gray-100 dark:hover:bg-gray-700/60', 'bg-gray-100 font-medium text-gray-900 dark:bg-gray-700/60 dark:text-gray-100' => request()->routeIs('support.*'), 'text-gray-700 dark:text-gray-300' => ! request()->routeIs('support.*')])>
                <span class="flex items-center gap-2"><span aria-hidden="true">💬</span> {{ __('Help & support') }}</span>
                @if ($supportUnread = \App\Models\SupportTicket::where('user_id', auth()->id())->where('requester_unread', true)->count())
                    <span class="rounded-full bg-indigo-600 px-1.5 text-[11px] font-semibold text-white" aria-label="{{ trans_choice(':count new answer|:count new answers', $supportUnread) }}">{{ $supportUnread }}</span>
                @endif
            </a>
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
                @if (collect(\App\Support\Modules\ModuleAccess::plans())->isNotEmpty())
                    <x-sidebar-link :href="route('modules.index')" :active="request()->routeIs('modules.index')" wire:navigate>{{ __('Modules & subscription') }}</x-sidebar-link>
                @endif
                @can('admin')
                    <x-sidebar-link :href="route('admin.overview')" :active="request()->routeIs('admin.*')" wire:navigate>{{ __('Administration') }}</x-sidebar-link>
                @endcan
                <x-sidebar-link :href="route('changelog')" :active="request()->routeIs('changelog')" wire:navigate>{{ __('What\'s new') }}</x-sidebar-link>
                <button type="button" wire:click="logout" class="w-full rounded-md px-2 py-1.5 text-start text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/60">
                    {{ __('Log Out') }}
                </button>
            </div>
        </div>
    </aside>

    <livewire:search-palette />
</div>
