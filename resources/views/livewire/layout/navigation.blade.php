<?php

use App\Livewire\Actions\Logout;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Support\PageTree;
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

    /**
     * @var array<string, string>
     */
    protected $listeners = ['item-captured' => '$refresh'];

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
            return ['tree' => null, 'sharedRoots' => [], 'privateRoots' => [], 'openIds' => [], 'inboxCount' => 0];
        }

        $tree = new PageTree(
            $this->visibleItems(Item::TYPE_NOTE)->get(['id', 'parent_id', 'title', 'icon', 'visibility', 'created_by'])
        );
        $roots = collect($tree->roots());

        return [
            'tree' => $tree,
            'sharedRoots' => $roots->reject(fn (Item $page) => $page->isPrivate())->values()->all(),
            'privateRoots' => $roots->filter(fn (Item $page) => $page->isPrivate())->values()->all(),
            'openIds' => $this->currentPageId
                ? collect($tree->ancestors($this->currentPageId))->pluck('id')->push($this->currentPageId)->all()
                : [],
            'inboxCount' => $this->visibleItems(Item::TYPE_INBOX)->count(),
        ];
    }
}; ?>

<div x-data="{ sidebarOpen: false }" x-on:keydown.escape.window="sidebarOpen = false">
    {{-- Mobile top bar --}}
    <div class="lg:hidden sticky top-0 z-30 flex h-14 items-center justify-between gap-2 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 px-3">
        <button type="button" x-on:click="sidebarOpen = true" class="p-2 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('Open menu') }}">
            <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>
        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-200">
            <x-application-logo class="h-7 w-auto fill-current" />
            {{ config('app.name') }}
        </a>
        <button type="button" x-on:click="$dispatch('open-search')" class="p-2 rounded-md text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('Search') }}">
            <x-search-icon class="h-6 w-6" />
        </button>
    </div>

    {{-- Backdrop (mobile) --}}
    <div x-show="sidebarOpen" x-cloak x-transition.opacity x-on:click="sidebarOpen = false" class="lg:hidden fixed inset-0 z-40 bg-gray-900/50"></div>

    {{-- Sidebar --}}
    <aside
        class="fixed inset-y-0 start-0 z-50 flex w-72 lg:w-64 flex-col border-e border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 transition-transform lg:translate-x-0"
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        x-cloak
        aria-label="{{ __('Main navigation') }}"
    >
        <div class="flex h-14 items-center justify-between gap-2 px-4 border-b border-gray-100 dark:border-gray-700">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-200">
                <x-application-logo class="h-7 w-auto fill-current" />
                {{ config('app.name') }}
            </a>
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
                <x-sidebar-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>🏠 {{ __('Dashboard') }}</x-sidebar-link>
                <x-sidebar-link :href="route('inbox.index')" :active="request()->routeIs('inbox.index')" :badge="$inboxCount ?: null" wire:navigate>📥 {{ __('Inbox') }}</x-sidebar-link>
            </div>

            @if ($householdId !== null)
                <div class="space-y-0.5">
                    <h3 class="px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('Household') }}</h3>
                    <x-sidebar-link :href="route('todos.index')" :active="request()->routeIs('todos.index')" wire:navigate>✅ {{ __('To-Do') }}</x-sidebar-link>
                    <x-sidebar-link :href="route('shopping-list.index')" :active="request()->routeIs('shopping-list.index')" wire:navigate>🛒 {{ __('Shopping List') }}</x-sidebar-link>
                    <x-sidebar-link :href="route('household.show')" :active="request()->routeIs('household.show')" wire:navigate>👥 {{ __('Members') }}</x-sidebar-link>
                </div>

                <x-sidebar-section :title="__('Pages')" :add-href="route('pages.index', ['create' => 1])" :all-href="route('pages.index')">
                    @if ($sharedRoots !== [])
                        <x-page-tree :tree="$tree" :pages="$sharedRoots" :current-id="$currentPageId" :open-ids="$openIds" />
                    @else
                        <p class="px-2 text-xs text-gray-400">{{ __('No shared pages yet.') }}</p>
                    @endif
                </x-sidebar-section>

                <x-sidebar-section :title="__('Private')" :add-href="route('pages.index', ['create' => 1, 'private' => 1])">
                    @if ($privateRoots !== [])
                        <x-page-tree :tree="$tree" :pages="$privateRoots" :current-id="$currentPageId" :open-ids="$openIds" :private-section="true" />
                    @else
                        <p class="px-2 text-xs text-gray-400">{{ __('Only you can see pages here.') }}</p>
                    @endif
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
                <button type="button" wire:click="logout" class="w-full rounded-md px-2 py-1.5 text-start text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/60">
                    {{ __('Log Out') }}
                </button>
            </div>
        </div>
    </aside>

    <livewire:search-palette />
</div>
