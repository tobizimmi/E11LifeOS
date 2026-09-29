@props(['close', 'label', 'size' => 'lg'])

{{--
    Shared popup for entries, forms and tasks: a sheet from the bottom on phones, a centered
    window on larger screens. $close is the Livewire method that closes it (also on Escape,
    except while typing). It closes at once on tap (the server follows) and on phones can be
    dragged down by its grabber or header (resources/js/app.js, e11Sheet).
    Slots: header (left of the close button), footer (sticky actions).
--}}
<div
    class="fixed inset-0 z-40 flex items-end justify-center sm:items-center sm:p-6"
    x-data="e11Sheet(@js($close))"
    x-on:keydown.escape.window="if (! $event.target.closest('.ProseMirror, input, textarea, select')) close()"
    {{ $attributes->only('wire:key') }}
>
    <div x-ref="backdrop" class="absolute inset-0 bg-gray-900/40 backdrop-blur-[2px]" x-on:click="close()" aria-hidden="true"></div>
    <section
        x-ref="panel"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $label }}"
        @class([
            'relative flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl will-change-transform dark:bg-gray-800 sm:max-h-[88vh] sm:rounded-2xl',
            'sm:max-w-md' => $size === 'md',
            'sm:max-w-2xl' => $size === 'lg',
        ])
    >
        {{-- Grabber + header: drag down to close on phones --}}
        <div class="touch-none select-none" x-on:pointerdown="dragStart($event)" x-on:pointermove="dragMove($event)" x-on:pointerup="dragEnd()" x-on:pointercancel="dragEnd()">
            <div class="flex justify-center pt-2 sm:hidden" aria-hidden="true"><span class="h-1.5 w-10 rounded-full bg-gray-300 dark:bg-gray-600"></span></div>
            <header class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 dark:border-gray-700">
                <div class="flex min-w-0 flex-1 items-center gap-2 text-sm text-gray-500 dark:text-gray-400">{{ $header ?? '' }}</div>
                <button type="button" x-on:click="close()" class="-me-2 rounded-full p-2 text-xl leading-none text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200" aria-label="{{ __('Close') }}">×</button>
            </header>
        </div>

        <div class="flex-1 overflow-y-auto overscroll-contain px-5 py-5">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="flex flex-wrap items-center gap-2 border-t border-gray-100 bg-gray-50/80 px-5 py-3 dark:border-gray-700 dark:bg-gray-900/40" style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom))">
                {{ $footer }}
            </footer>
        @endisset
    </section>
</div>
