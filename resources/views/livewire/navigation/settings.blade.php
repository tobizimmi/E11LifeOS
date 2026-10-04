<?php

use App\Support\Navigation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The member's menu: ready-made selections, and per module whether it is pinned to the top or
 * hidden. Hidden modules stay reachable through the search (Ctrl K).
 */
new #[Layout('layouts.app')] class extends Component
{
    public function applyPreset(string $preset): void
    {
        abort_unless(array_key_exists($preset, Navigation::presets()), 404);
        Navigation::applyPreset(Auth::user(), $preset);
        $this->dispatch('toast', message: __('Menu updated.'));
        $this->redirect(route('navigation.settings'), navigate: true);
    }

    public function togglePinned(string $key): void
    {
        abort_unless(array_key_exists($key, Navigation::modules(Auth::user())), 404);
        Navigation::togglePinned(Auth::user(), $key);
        $this->redirect(route('navigation.settings'), navigate: true);
    }

    public function toggleHidden(string $key): void
    {
        abort_unless(array_key_exists($key, Navigation::modules(Auth::user())) && Navigation::isHideable($key), 404);
        Navigation::toggleHidden(Auth::user(), $key);
        $this->redirect(route('navigation.settings'), navigate: true);
    }

    public function with(): array
    {
        return [
            'modules' => Navigation::modules(Auth::user()),
            'groups' => Navigation::groups(),
            'preferences' => Navigation::preferences(Auth::user()),
            'presets' => Navigation::presets(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Customize menu') }}</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Pin what you use every day, hide what you never need. Hidden modules stay reachable through the search (Ctrl K).') }}</p>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl space-y-5 px-4 sm:px-6 lg:px-8">
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Quick start') }}</h3>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach ($presets as $key => $preset)
                        <button type="button" wire:click="applyPreset('{{ $key }}')" wire:key="preset-{{ $key }}" class="rounded-xl border border-gray-200 p-3 text-start hover:border-indigo-400 hover:bg-indigo-50/50 dark:border-gray-700 dark:hover:bg-indigo-950/30">
                            <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $preset['label'] }}</span>
                            <span class="block text-xs text-gray-600 dark:text-gray-400">{{ $preset['description'] }}</span>
                        </button>
                    @endforeach
                </div>
            </section>

            @foreach ($groups as $group => $title)
                @php($groupModules = collect($modules)->where('group', $group))
                @continue($groupModules->isEmpty())
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" wire:key="group-{{ $group }}">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $title }}</h3>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($groupModules as $key => $module)
                            @php($hidden = in_array($key, $preferences['hidden'], true))
                            @php($pinned = in_array($key, $preferences['pinned'], true))
                            <li class="flex items-center gap-3 py-2" wire:key="module-{{ $key }}">
                                <x-icon :name="$module['icon']" @class(['h-5 w-5', 'text-gray-300 dark:text-gray-600' => $hidden, 'text-gray-500 dark:text-gray-400' => ! $hidden]) />
                                <span @class(['flex-1 text-sm', 'text-gray-400 line-through dark:text-gray-500' => $hidden, 'text-gray-900 dark:text-gray-100' => ! $hidden])>{{ $module['label'] }}</span>
                                @unless ($hidden)
                                    <button type="button" wire:click="togglePinned('{{ $key }}')" aria-pressed="{{ $pinned ? 'true' : 'false' }}" @class(['rounded-full px-2.5 py-1 text-xs', 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $pinned, 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => ! $pinned])>{{ $pinned ? '📌 '.__('Pinned') : __('Pin') }}</button>
                                @endunless
                                @if (\App\Support\Navigation::isHideable($key))
                                    <button type="button" role="switch" aria-checked="{{ $hidden ? 'false' : 'true' }}" aria-label="{{ __('Show :module in the menu', ['module' => $module['label']]) }}" wire:click="toggleHidden('{{ $key }}')"
                                        @class(['relative inline-flex h-6 w-11 shrink-0 rounded-full transition', 'bg-emerald-500' => ! $hidden, 'bg-gray-300 dark:bg-gray-600' => $hidden])>
                                        <span @class(['absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', 'start-[1.375rem]' => ! $hidden, 'start-0.5' => $hidden])></span>
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    </div>
</div>
