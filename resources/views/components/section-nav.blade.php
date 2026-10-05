{{--
    Sub-navigation of a section, the same everywhere.
    - Up to four sections: a segmented control that always fits (from three on, phones show the
      icon above the label).
    - More: phones get one button with the current section that opens a sheet listing all
      sections in groups (no sideways scrolling, large tap targets); larger screens show all
      sections in one wrapping bar, groups separated by a hairline.

    $groups: [group label ('' for none) => [key => [icon, label, href?, badge?, click?]]].
    Without href an item sets the Livewire property $tabProperty to its key, or runs `click`.
--}}
@props(['groups', 'active', 'label', 'tabProperty' => 'tab', 'sticky' => false])

@php
    $all = collect($groups)->flatMap(fn ($items) => $items);
    $current = $all->get($active) ?? ['', ''];
    $count = $all->count();
    $link = function (string $key, array $item) use ($tabProperty) {
        [$icon, $text] = $item;
        $href = $item[2] ?? null;
        $click = $item[4] ?? null;

        return match (true) {
            $href !== null => 'href="'.e($href).'" wire:navigate',
            $click !== null => 'href="#" wire:click.prevent="'.e($click).'"',
            default => 'href="#" wire:click.prevent="$set(\''.$tabProperty.'\', \''.e($key).'\')"',
        };
    };
    $badge = fn (array $item) => isset($item[3]) && $item[3] !== null && $item[3] !== '' ? $item[3] : null;
@endphp

<nav {{ $attributes->class(['e11-section-nav', 'sticky top-14 z-10 lg:top-2' => $sticky]) }} aria-label="{{ $label }}" x-data="{ open: false }" x-on:keydown.escape.window="open = false">
    @if ($count <= 4)
        <div class="flex gap-1 rounded-xl bg-gray-100 p-1 dark:bg-gray-800">
            @foreach ($all as $key => $item)
                <a {!! $link((string) $key, $item) !!} @if ((string) $key === (string) $active) aria-current="page" @endif
                    @class(['flex min-h-10 min-w-0 flex-1 items-center justify-center rounded-lg py-1.5 text-center font-medium transition-colors', 'flex-col gap-0.5 px-1 sm:flex-row sm:gap-1.5 sm:px-3 sm:text-sm' => $count >= 3, 'text-xs' => $count === 3, 'text-[11px] tracking-tight' => $count === 4, 'gap-1.5 px-3 text-sm' => $count < 3, 'bg-white text-gray-900 shadow-xs dark:bg-gray-700 dark:text-gray-100' => (string) $key === (string) $active, 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100' => (string) $key !== (string) $active])>
                    <span aria-hidden="true">{{ $item[0] }}</span><span class="max-w-full leading-tight hyphens-auto">{{ $item[1] }}</span>@if ($badge($item))<span class="text-xs opacity-70">{{ $badge($item) }}</span>@endif
                </a>
            @endforeach
        </div>
    @else
        {{-- Phone --}}
        <button type="button" x-on:click="open = true" aria-haspopup="dialog" x-bind:aria-expanded="open"
            @class(['flex w-full items-center gap-3 rounded-xl px-4 py-3 text-start shadow-xs ring-1 ring-gray-200 active:scale-[0.99] dark:ring-gray-700 md:hidden', 'bg-white/90 backdrop-blur-sm dark:bg-gray-800/90' => $sticky, 'bg-white dark:bg-gray-800' => ! $sticky])>
            <span class="text-lg" aria-hidden="true">{{ $current[0] }}</span>
            <span class="min-w-0 flex-1">
                <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $current[1] }}</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $label }} · {{ trans_choice(':count section|:count sections', $count) }}</span>
            </span>
            <svg class="h-5 w-5 shrink-0 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
        </button>

        {{-- On <body>: an ancestor with a backdrop filter (e.g. the page header) would otherwise anchor "fixed" to itself. --}}
        <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 md:hidden" role="dialog" aria-modal="true" aria-label="{{ $label }}" x-on:keydown.escape.window="open = false">
            <div class="absolute inset-0 bg-black/40" x-show="open" x-transition.opacity x-on:click="open = false"></div>
            <div x-show="open" x-trap.noscroll="open"
                x-transition:enter="transition duration-300 ease-out motion-reduce:transition-none" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                x-transition:leave="transition duration-200 ease-in motion-reduce:transition-none" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
                class="absolute inset-x-0 bottom-0 max-h-[80vh] overflow-y-auto overscroll-contain rounded-t-2xl bg-white pb-[max(1rem,env(safe-area-inset-bottom))] shadow-2xl dark:bg-gray-900">
                <div class="sticky top-0 bg-white/90 px-4 pt-2 pb-2 backdrop-blur dark:bg-gray-900/90">
                    <span class="mx-auto block h-1 w-10 rounded-full bg-gray-300 dark:bg-gray-600" aria-hidden="true"></span>
                </div>
                <div class="flex items-center justify-between px-4 pb-1">
                    <p class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $label }}</p>
                    <button type="button" x-on:click="open = false" class="rounded-full px-2 py-1 text-sm text-indigo-600 dark:text-indigo-400">{{ __('Close') }}</button>
                </div>
                @foreach ($groups as $group => $items)
                    @if ($group !== '')
                        <p class="px-4 pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $group }}</p>
                    @elseif (! $loop->first)
                        <div class="h-3"></div>
                    @endif
                    <ul class="mx-3 overflow-hidden rounded-xl bg-gray-50 dark:bg-gray-800">
                        @foreach ($items as $key => $item)
                            <li class="border-b border-gray-200/70 last:border-0 dark:border-gray-700/70">
                                <a {!! $link((string) $key, $item) !!} x-on:click="open = false" @if ((string) $key === (string) $active) aria-current="page" @endif
                                    class="flex min-h-11 items-center gap-3 px-4 py-2.5 text-[15px] text-gray-900 active:bg-gray-200 dark:text-gray-100 dark:active:bg-gray-700">
                                    <span class="w-6 text-center" aria-hidden="true">{{ $item[0] }}</span>
                                    <span class="flex-1">{{ $item[1] }}</span>
                                    @if ($badge($item))<span class="text-sm text-gray-500 dark:text-gray-400">{{ $badge($item) }}</span>@endif
                                    @if ((string) $key === (string) $active)
                                        <svg class="h-5 w-5 text-indigo-600 dark:text-indigo-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.58l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
                {{ $slot }}
            </div>
        </div>
        </template>

        {{-- Tablet and desktop: one bar, groups separated by a hairline --}}
        <div @class(['hidden flex-wrap items-center gap-1 rounded-xl p-1 shadow-xs ring-1 ring-gray-200 dark:ring-gray-700 md:flex', 'bg-white/90 backdrop-blur-sm dark:bg-gray-800/90' => $sticky, 'bg-white dark:bg-gray-800' => ! $sticky])>
            @foreach ($groups as $group => $items)
                @if (! $loop->first)
                    <span class="mx-1 h-5 w-px bg-gray-200 dark:bg-gray-700" aria-hidden="true"></span>
                @endif
                @foreach ($items as $key => $item)
                    <a {!! $link((string) $key, $item) !!} @if ((string) $key === (string) $active) aria-current="page" @endif @if ($loop->first && $group !== '') title="{{ $group }}" @endif
                        @class(['rounded-lg px-3 py-1.5 text-sm whitespace-nowrap transition-colors', 'bg-indigo-600 text-white' => (string) $key === (string) $active, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => (string) $key !== (string) $active])>{{ $item[0] }} {{ $item[1] }}@if ($badge($item)) <span class="opacity-70">{{ $badge($item) }}</span>@endif</a>
                @endforeach
            @endforeach
        </div>
    @endif
</nav>
