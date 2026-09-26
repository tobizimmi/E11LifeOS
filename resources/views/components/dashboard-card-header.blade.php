@props(['title', 'href', 'count' => null])

<div class="flex items-center justify-between gap-3">
    <h3 class="font-medium text-gray-900 dark:text-gray-100">
        {{ $title }}
        @if ($count !== null)
            <span class="ms-1 text-sm font-normal text-gray-500 dark:text-gray-400">({{ $count }})</span>
        @endif
    </h3>
    <a href="{{ $href }}" wire:navigate class="shrink-0 whitespace-nowrap text-sm text-indigo-600 dark:text-indigo-400 underline">{{ __('Show all') }}</a>
</div>
