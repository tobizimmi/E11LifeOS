<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>🔒 {{ $trip->title }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">
        <main class="mx-auto max-w-sm px-4 py-16">
            <form method="POST" action="{{ route('trips.folder.unlock', $token) }}" class="space-y-4 rounded-2xl bg-white p-6 shadow-sm dark:bg-gray-800">
                @csrf
                <p class="text-4xl" aria-hidden="true">🔒</p>
                <h1 class="text-xl font-semibold">{{ $trip->title }}</h1>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('This trip folder is protected. Please enter the password you received from the organiser.') }}</p>
                <label class="block text-sm">{{ __('Password') }}
                    <input type="password" name="password" required autofocus autocomplete="current-password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                </label>
                @error('password')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
                <button type="submit" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">{{ __('Open folder') }}</button>
            </form>
        </main>
    </body>
</html>
