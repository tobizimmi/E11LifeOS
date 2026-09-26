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
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            <livewire:layout.navigation />

            {{-- Shown while the device is offline (the shopping list stays readable from the cache). --}}
            <div
                x-data="{ online: navigator.onLine }"
                x-on:online.window="online = true"
                x-on:offline.window="online = false"
                x-show="! online"
                x-cloak
                role="status"
                class="lg:ps-64 sticky top-14 lg:top-0 z-20 bg-amber-100 dark:bg-amber-900/60 px-4 py-2 text-center text-sm text-amber-900 dark:text-amber-100"
            >
                {{ __('You are offline – you see the last loaded state. Changes are possible again once you are online.') }}
            </div>

            <div class="lg:ps-64">
                <!-- Page Heading -->
                @if (isset($header))
                    <header class="bg-white dark:bg-gray-800 shadow">
                        <div class="max-w-7xl mx-auto py-5 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endif

                <!-- Page Content -->
                <main>
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
                    class="fixed bottom-4 left-1/2 z-50 -translate-x-1/2 flex items-center gap-3 rounded-lg bg-gray-900 px-4 py-2 text-sm text-white shadow-lg dark:bg-gray-100 dark:text-gray-900"
                >
                    <span x-text="message"></span>
                    {{-- Optional action, e.g. "Undo": dispatches a Livewire event ($this->dispatch('toast', message: ..., action: ['label' => ..., 'event' => ..., 'params' => [...]])) --}}
                    <button type="button" x-show="action" x-text="action?.label" x-on:click="Livewire.dispatch(action.event, action.params ?? {}); visible = false" class="font-semibold text-indigo-300 dark:text-indigo-700 hover:underline"></button>
                </div>
            </div>
        </div>
    </body>
</html>
