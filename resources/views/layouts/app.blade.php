<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- PWA (app.url()/asset() so this also works when deployed under a URL path, e.g. /E11LifeOS) -->
        <meta name="app-base-url" content="{{ url('/') }}">
        <meta name="e11-authenticated" content="1">
        <meta name="e11-offline-sent" content="{{ __(':count entries captured offline were sent') }}">
        <link rel="manifest" href="{{ asset('manifest.json') }}">
        <meta name="theme-color" content="#4f46e5">
        <link rel="icon" href="{{ asset('icons/icon-192.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-slate-50 dark:bg-gray-950">
        <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[100] focus:rounded-md focus:bg-indigo-600 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white">{{ __('Skip to content') }}</a>
        <div class="min-h-screen bg-gradient-to-b from-indigo-50/60 via-slate-50 to-slate-50 dark:from-gray-950 dark:via-gray-950 dark:to-gray-950">
            <livewire:layout.navigation />
            {{-- Tasks open in place from any page: links with data-open-task="id" (app.js) --}}
            <livewire:tasks.quick-sheet />

            {{-- Shown while the device is offline (the shopping list stays readable from the cache). --}}
            <div
                x-data="{ online: navigator.onLine, waiting: window.E11Offline?.items().length ?? 0 }"
                x-on:online.window="online = true"
                x-on:offline.window="online = false"
                x-on:offline-queue.window="waiting = $event.detail.count"
                x-show="! online || waiting > 0"
                x-cloak
                role="status"
                class="lg:ps-64 sticky top-14 lg:top-0 z-20 bg-amber-100 dark:bg-amber-900/60 px-4 py-2 text-center text-sm text-amber-900 dark:text-amber-100"
            >
                <span x-show="! online">{{ __('You are offline – you see the last loaded state. New inbox entries and tasks are kept and sent once you are online.') }}</span>
                <span x-show="waiting > 0" x-text="@js(__('Waiting to be sent: :count')).replace(':count', waiting)"></span>
            </div>

            <div class="lg:ps-64">
                <!-- Page Heading -->
                @if (isset($header))
                    {{-- Large title on phones; once it scrolls away, the top bar shows it small (e11Chrome) --}}
                    <header data-page-header class="border-b border-gray-200/70 bg-white/60 backdrop-blur dark:border-gray-800 dark:bg-gray-900/60"
                        x-data
                        x-init="$store.chrome.watch($el)">
                        <div class="max-w-7xl mx-auto py-5 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endif

                <!-- Page Content (room for the tab bar on phones) -->
                <main id="main-content" tabindex="-1" class="pb-20 focus:outline-none lg:pb-0">
                    {{ $slot }}
                </main>

                <!-- Short confirmations dispatched by components ($this->dispatch('toast', message: ...)) -->
                <div
                    x-data="{ message: '', action: null, visible: false, timer: null }"
                    x-on:toast.window="message = $event.detail.message; action = $event.detail.action ?? null; visible = true; clearTimeout(timer); timer = setTimeout(() => visible = false, action ? 6000 : 4000)"
                    x-show="visible"
                    x-cloak
                    x-transition.opacity
                    role="status"
                    aria-live="polite"
                    class="fixed bottom-20 lg:bottom-4 left-1/2 z-50 -translate-x-1/2 flex items-center gap-3 rounded-lg bg-gray-900 px-4 py-2 text-sm text-white shadow-lg dark:bg-gray-100 dark:text-gray-900"
                >
                    <span x-text="message"></span>
                    {{-- Optional action, e.g. "Undo": dispatches a Livewire event ($this->dispatch('toast', message: ..., action: ['label' => ..., 'event' => ..., 'params' => [...]])) --}}
                    <button type="button" x-show="action" x-text="action?.label" x-on:click="Livewire.dispatch(action.event, action.params ?? {}); visible = false" class="font-semibold text-indigo-300 dark:text-indigo-700 hover:underline"></button>
                </div>
            </div>

            {{-- Tab bar on phones: the most used places and "create" in thumb reach --}}
            <nav class="e11-material fixed inset-x-0 bottom-0 z-30 border-t border-gray-200/70 bg-white/90 backdrop-blur lg:hidden dark:border-gray-800 dark:bg-gray-900/90" style="padding-bottom: env(safe-area-inset-bottom)" aria-label="{{ __('Quick navigation') }}">
                <ul class="mx-auto grid max-w-md grid-cols-5 text-[11px]">
                    @foreach ([['dashboard', 'home', __('Start')], ['tasks.index', 'check-circle', __('Tasks')], [null, 'plus', __('Create')], ['shopping-list.index', 'shopping-cart', __('Shopping trip')]] as [$tabRoute, $tabIcon, $tabLabel])
                        <li>
                            @if ($tabRoute)
                                <a href="{{ route($tabRoute) }}" wire:navigate @class(['flex flex-col items-center gap-0.5 py-2', 'text-indigo-600 dark:text-indigo-400' => request()->routeIs($tabRoute.'*'), 'text-gray-600 dark:text-gray-400' => ! request()->routeIs($tabRoute.'*')])>
                                    <x-icon :name="$tabIcon" class="h-6 w-6" />{{ $tabLabel }}
                                </a>
                            @else
                                <button type="button" x-data x-on:click="$dispatch('open-search')" class="flex w-full flex-col items-center gap-0.5 py-1.5 text-gray-600 dark:text-gray-400">
                                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-white shadow" aria-hidden="true"><x-icon :name="$tabIcon" class="h-5 w-5" stroke-width="2" /></span>{{ $tabLabel }}
                                </button>
                            @endif
                        </li>
                    @endforeach
                    <li>
                        <button type="button" x-data x-on:click="$dispatch('open-sidebar')" class="flex w-full flex-col items-center gap-0.5 py-2 text-gray-600 dark:text-gray-400">
                            <x-icon name="bars-3" class="h-6 w-6" />{{ __('More') }}
                        </button>
                    </li>
                </ul>
            </nav>

            {{-- Keyboard shortcuts: "/" search, "g" + letter to jump, "?" for this overview --}}
            <div
                x-data="{
                    help: false,
                    pending: null,
                    routes: @js(['h' => route('dashboard'), 't' => route('tasks.index'), 'k' => route('calendar.index'), 'e' => route('shopping-list.index'), 'i' => route('inbox.index'), 'p' => route('pages.index'), 'r' => route('recipes.index'), 'f' => route('finance.index'), 'c' => route('collections.index')]),
                    typing(event) { return event.target.closest('input, textarea, select, [contenteditable], .ProseMirror') },
                    key(event) {
                        if (event.metaKey || event.ctrlKey || event.altKey || this.typing(event)) return;
                        if (this.pending === 'g' && this.routes[event.key]) { event.preventDefault(); this.pending = null; Livewire.navigate(this.routes[event.key]); return; }
                        this.pending = null;
                        if (event.key === '/') { event.preventDefault(); $dispatch('open-search') }
                        else if (event.key === 'n') { event.preventDefault(); $dispatch('open-search') }
                        else if (event.key === '?') { event.preventDefault(); this.help = ! this.help }
                        else if (event.key === 'g') { this.pending = 'g'; setTimeout(() => this.pending = null, 1200) }
                    },
                }"
                x-on:keydown.window="key($event)"
                x-on:keydown.escape.window="help = false"
            >
                <div x-show="help" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/40 p-4" x-on:click.self="help = false" role="dialog" aria-modal="true" aria-label="{{ __('Keyboard shortcuts') }}">
                    <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800">
                        <div class="flex items-center justify-between">
                            <h2 class="font-semibold text-gray-900 dark:text-gray-100">⌨️ {{ __('Keyboard shortcuts') }}</h2>
                            <button type="button" x-on:click="help = false" class="text-xl text-gray-400" aria-label="{{ __('Close') }}">×</button>
                        </div>
                        <dl class="mt-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                            @foreach ([['Ctrl K · /', __('Search, jump to or create')], ['N', __('Create something new')], ['G H', __('Dashboard')], ['G T', __('To-Do')], ['G K', __('Calendar')], ['G E', __('Shopping List')], ['G I', __('Inbox')], ['G P', __('Pages')], ['G C', __('Collections')], ['G R', __('Recipes')], ['G F', __('Finances')], ['?', __('This overview')], ['Esc', __('Close')]] as [$shortcut, $meaning])
                                <dt><kbd class="rounded border border-gray-200 bg-gray-50 px-1.5 py-0.5 font-mono text-xs text-gray-700 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">{{ $shortcut }}</kbd></dt>
                                <dd class="text-gray-700 dark:text-gray-300">{{ $meaning }}</dd>
                            @endforeach
                        </dl>
                    </div>
                </div>
            </div>
        </div>
        @auth
            @if (config('security.idle_minutes') > 0)
                {{-- Signs this browser out after the idle time (security.idle_minutes); the timer lives in app.js. --}}
                <form id="idle-logout" method="POST" action="{{ route('logout.idle') }}" class="hidden" data-idle-minutes="{{ (int) config('security.idle_minutes') }}" data-navigate-once>@csrf</form>
            @endif
        @endauth
    </body>
</html>
