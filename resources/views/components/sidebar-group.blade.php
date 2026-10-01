{{-- A collapsible group of the sidebar; open when one of its pages is shown, otherwise as last chosen on this device. --}}
@props(['title', 'section', 'active' => false, 'default' => false])

<div class="space-y-0.5"
    x-data="{ open: @js((bool) $active) || (() => { try { const saved = localStorage.getItem('e11-nav-{{ $section }}'); return saved === null ? @js((bool) $default) : saved === '1'; } catch (e) { return @js((bool) $default); } })() }"
    x-init="$watch('open', value => { try { localStorage.setItem('e11-nav-{{ $section }}', value ? '1' : '0'); } catch (e) {} })"
>
    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" class="flex w-full items-center justify-between rounded-md px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
        <span>{{ $title }}</span>
        <svg class="h-3.5 w-3.5 transition-transform" x-bind:class="open ? 'rotate-90' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
    </button>
    <div x-show="open" x-cloak class="space-y-0.5">
        {{ $slot }}
    </div>
</div>
