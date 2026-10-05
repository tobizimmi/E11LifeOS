<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="app-base-url" content="{{ url('/') }}">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <title>{{ $title ?? config('app.name') }}</title>
        <link rel="manifest" href="{{ asset('manifest.json') }}">
        <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full bg-gray-100 font-sans text-gray-900 antialiased dark:bg-gray-950 dark:text-gray-100">
        {{ $slot }}
    </body>
</html>
