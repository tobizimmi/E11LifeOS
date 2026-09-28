@props(['title', 'addHref', 'allHref' => null, 'addLabel' => null])

{{-- Collapsible sidebar group; the open/closed state is remembered per group in this browser. --}}
<div class="space-y-0.5" x-data="{
    key: 'sidebar:' + @js($title),
    open: true,
    init() { try { this.open = localStorage.getItem(this.key) !== '0' } catch (e) {} },
    toggle() { this.open = ! this.open; try { localStorage.setItem(this.key, this.open ? '1' : '0') } catch (e) {} },
}">
    <div class="group flex items-center justify-between px-2 pb-1">
        <button type="button" x-on:click="toggle()" class="flex items-center gap-1 text-xs font-semibold uppercase tracking-wide text-gray-400 hover:text-gray-600 dark:hover:text-gray-200" :aria-expanded="open">
            <svg class="h-3 w-3 transition-transform" :class="open ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
            {{ $title }}
        </button>
        <span class="flex items-center gap-2">
            @if ($allHref)
                <a href="{{ $allHref }}" wire:navigate class="text-[11px] text-gray-400 opacity-0 transition group-hover:opacity-100 hover:text-gray-700 dark:hover:text-gray-200">{{ __('All') }}</a>
            @endif
            <a href="{{ $addHref }}" wire:navigate class="text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" title="{{ $addLabel ?? __('New page') }}">
                <span aria-hidden="true">+</span><span class="sr-only">{{ $addLabel ?? __('New page') }}</span>
            </a>
        </span>
    </div>
    <div x-show="open">
        {{ $slot }}
    </div>
</div>
