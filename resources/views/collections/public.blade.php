<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $collection->name }} · {{ config('app.name') }}</title>
        <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
        <main class="max-w-5xl mx-auto px-4 py-6 sm:py-10 space-y-4">
            <header>
                <h1 class="text-2xl font-bold">{{ $collection->displayIcon() }} {{ $collection->name }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ trans_choice(':count entry|:count entries', $rows->count()) }} · {{ __('Shared from :app', ['app' => config('app.name')]) }}</p>
            </header>

            @if ($rows->isEmpty())
                <p class="rounded-lg bg-white p-6 text-sm text-gray-500 shadow-xs dark:bg-gray-800 dark:text-gray-400">{{ __('No entries yet.') }}</p>
            @else
                <ul class="grid gap-3 sm:grid-cols-2">
                    @foreach ($rows as $row)
                        <li class="rounded-lg bg-white p-4 shadow-xs dark:bg-gray-800">
                            <p @class(['font-semibold', 'line-through text-gray-400' => $row['entry']->done])>{{ $row['entry']->icon }} {{ $row['entry']->title }}</p>
                            <dl class="mt-2 space-y-1 text-sm">
                                @foreach ($properties as $property)
                                    @continue(! $row['values'][$property->id])
                                    <div class="flex gap-2">
                                        <dt class="w-36 shrink-0 text-gray-500 dark:text-gray-400">{{ $property->name }}</dt>
                                        <dd class="min-w-0 break-words text-gray-800 dark:text-gray-200">
                                            @if ($row['values'][$property->id]['url'])
                                                <a href="{{ $row['values'][$property->id]['url'] }}" rel="noopener noreferrer nofollow" target="_blank" class="text-indigo-600 underline dark:text-indigo-400">{{ $row['values'][$property->id]['text'] }}</a>
                                            @else
                                                {{ $row['values'][$property->id]['text'] }}
                                            @endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                            @if ($row['entry']->body() !== '')
                                <p class="mt-2 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit($row['entry']->body(), 600) }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </main>
    </body>
</html>
