@props(['close', 'label', 'size' => 'lg'])

{{--
    Shared popup for entries, forms and tasks: a sheet from the bottom on phones, a centered
    window on larger screens. $close is the Livewire method that closes it (also on Escape,
    except while typing). Slots: header (left of the close button), footer (sticky actions).
--}}
<div
    class="fixed inset-0 z-40 flex items-end justify-center sm:items-center sm:p-6"
    x-data
    x-on:keydown.escape.window="if (! $event.target.closest('.ProseMirror, input, textarea, select')) $wire.{{ $close }}()"
    {{ $attributes->only('wire:key') }}
>
    <div class="absolute inset-0 bg-gray-900/40 backdrop-blur-[1px]" wire:click="{{ $close }}" aria-hidden="true"></div>
    <section
        role="dialog"
        aria-modal="true"
        aria-label="{{ $label }}"
        @class([
            'relative flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-2xl bg-white shadow-2xl dark:bg-gray-800 sm:max-h-[88vh] sm:rounded-2xl',
            'sm:max-w-md' => $size === 'md',
            'sm:max-w-2xl' => $size === 'lg',
        ])
    >
        <header class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 dark:border-gray-700">
            <div class="flex min-w-0 flex-1 items-center gap-2 text-sm text-gray-500 dark:text-gray-400">{{ $header ?? '' }}</div>
            <button type="button" wire:click="{{ $close }}" class="-me-2 rounded-lg p-2 text-xl leading-none text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200" aria-label="{{ __('Close') }}">×</button>
        </header>

        <div class="flex-1 overflow-y-auto px-5 py-5">
            {{ $slot }}
        </div>

        @isset($footer)
            <footer class="flex flex-wrap items-center gap-2 border-t border-gray-100 bg-gray-50/80 px-5 py-3 dark:border-gray-700 dark:bg-gray-900/40" style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom))">
                {{ $footer }}
            </footer>
        @endisset
    </section>
</div>
