@props(['active' => false, 'badge' => null])

<a {{ $attributes->merge(['class' => 'flex items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm '.($active
    ? 'bg-indigo-50 text-indigo-700 font-medium dark:bg-gray-700 dark:text-white'
    : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700/60')]) }}
    @if ($active) aria-current="page" @endif
>
    <span class="truncate">{{ $slot }}</span>
    @if ($badge)
        <span class="text-xs rounded-full bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 px-1.5">{{ $badge }}</span>
    @endif
</a>
