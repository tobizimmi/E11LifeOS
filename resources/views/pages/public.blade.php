<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $current->title }} · {{ $root->title }}</title>
        <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
        <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 py-6 sm:py-10 lg:flex-row">
            <aside class="lg:w-64 lg:shrink-0">
                <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $root->displayIcon() }} {{ $root->title }}</p>
                    <nav class="mt-2 space-y-0.5" aria-label="{{ __('Pages') }}">
                        @foreach ($rows as $row)
                            <a href="{{ route('pages.public', ['token' => $token]) }}?p={{ $row['page']->id }}"
                               wire:key="pub-{{ $row['page']->id }}"
                               @class([
                                   'block truncate rounded-md px-2 py-1 text-sm',
                                   'bg-indigo-50 font-medium text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' => $row['page']->id === $current->id,
                                   'text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700' => $row['page']->id !== $current->id,
                               ])
                               style="padding-inline-start: {{ 0.5 + $row['depth'] * 0.75 }}rem">
                                {{ $row['page']->displayIcon() }} {{ $row['page']->title }}
                            </a>
                        @endforeach
                    </nav>
                </div>
            </aside>

            <main class="min-w-0 flex-1 space-y-4">
                @if ($breadcrumbs !== [])
                    <nav aria-label="{{ __('Breadcrumb') }}" class="flex flex-wrap items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
                        @foreach ($breadcrumbs as $ancestor)
                            <a href="{{ route('pages.public', ['token' => $token]) }}?p={{ $ancestor->id }}" class="hover:underline">{{ $ancestor->title }}</a>
                            <span aria-hidden="true">/</span>
                        @endforeach
                        <span class="text-gray-700 dark:text-gray-300">{{ $current->title }}</span>
                    </nav>
                @endif

                <article class="rounded-2xl bg-white p-6 shadow-xs dark:bg-gray-800">
                    <h1 class="text-2xl font-bold">{{ $current->displayIcon() }} {{ $current->title }}</h1>
                    <div class="note-content mt-4 max-w-none">
                        {!! $html !!}
                    </div>
                </article>

                <p class="text-center text-xs text-gray-400 dark:text-gray-500">{{ __('Shared from :app', ['app' => config('app.name')]) }}</p>
            </main>
        </div>
    </body>
</html>
