@props(['active' => false, 'badge' => null, 'icon' => null])

<a {{ $attributes->merge(['class' => 'group flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-sm pointer-coarse:py-2.5 '.($active
    ? 'bg-indigo-50 text-indigo-700 font-medium dark:bg-gray-700 dark:text-white'
    : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700/60')]) }}
    @if ($active) aria-current="page" @endif
>
    <span class="flex min-w-0 items-center gap-2.5">
        @if ($icon)
            <x-icon :name="$icon" @class(['h-5 w-5', 'text-indigo-600 dark:text-indigo-300' => $active, 'text-gray-400 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300' => ! $active]) />
        @endif
        <span class="truncate">{{ $slot }}</span>
    </span>
    @if ($badge)
        <span class="rounded-full bg-gray-200 px-1.5 text-xs tabular-nums text-gray-700 dark:bg-gray-600 dark:text-gray-200">{{ $badge }}</span>
    @endif
</a>
