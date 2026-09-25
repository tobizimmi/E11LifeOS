@props(['title', 'addHref', 'allHref' => null, 'addLabel' => null])

<div class="space-y-0.5">
    <div class="group flex items-center justify-between px-2 pb-1">
        @if ($allHref)
            <a href="{{ $allHref }}" wire:navigate class="text-xs font-semibold uppercase tracking-wide text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">{{ $title }}</a>
        @else
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $title }}</h3>
        @endif
        <a href="{{ $addHref }}" wire:navigate class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" title="{{ $addLabel ?? __('New page') }}">
            <span aria-hidden="true">+</span><span class="sr-only">{{ $addLabel ?? __('New page') }}</span>
        </a>
    </div>
    {{ $slot }}
</div>
