@props(['option'])

{{-- A select option as colored pill; classes are spelled out so Tailwind keeps them. --}}
@php
    $classes = match ($option['color'] ?? 'gray') {
        'blue' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-200',
        'green' => 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-200',
        'yellow' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-200',
        'orange' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/50 dark:text-orange-200',
        'red' => 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200',
        'purple' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/50 dark:text-purple-200',
        'pink' => 'bg-pink-100 text-pink-800 dark:bg-pink-900/50 dark:text-pink-200',
        default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium $classes"]) }}>{{ $option['name'] }}</span>
