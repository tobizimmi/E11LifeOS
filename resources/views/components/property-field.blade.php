@props(['property', 'members', 'nodes' => []])

{{-- One labelled field of the entry form, bound to form.values.<property id> of the surrounding component. --}}
@use('App\Models\CollectionProperty')

@php
    $id = 'field-'.$property->id;
    $model = 'form.values.'.$property->id;
    $input = 'block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-xs focus:border-indigo-500 focus:ring-indigo-500';
    $wide = in_array($property->type, [CollectionProperty::TYPE_MULTI_SELECT, CollectionProperty::TYPE_RELATION], true);
@endphp

<div @class(['sm:col-span-2' => $wide])>
    @if ($property->type === CollectionProperty::TYPE_CHECKBOX)
        <label for="{{ $id }}" class="flex h-full items-center gap-3 rounded-lg border border-gray-200 dark:border-gray-700 px-3 py-2.5 text-sm text-gray-700 dark:text-gray-200">
            <input id="{{ $id }}" type="checkbox" wire:model="{{ $model }}" class="h-5 w-5 rounded-sm border-gray-300 dark:border-gray-600 text-indigo-600" />
            {{ $property->name }}@if ($property->required)<span class="text-red-600" aria-hidden="true"> *</span>@endif
        </label>
    @else
        <label for="{{ $id }}" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $property->name }}@if ($property->required)<span class="text-red-600" aria-hidden="true"> *</span><span class="sr-only"> ({{ __('required') }})</span>@endif</label>

        @switch($property->type)
            @case(CollectionProperty::TYPE_NUMBER)
                <div class="relative">
                    <input id="{{ $id }}" type="number" step="any" inputmode="decimal" wire:model="{{ $model }}" class="{{ $input }} {{ \App\Support\PropertyDisplay::isCurrency($property) ? 'pe-8' : '' }}" />
                    @if (\App\Support\PropertyDisplay::isCurrency($property))
                        <span class="pointer-events-none absolute inset-y-0 end-3 flex items-center text-sm text-gray-400">€</span>
                    @endif
                </div>
                @break

            @case(CollectionProperty::TYPE_DATE)
                <input id="{{ $id }}" type="date" wire:model="{{ $model }}" class="{{ $input }}" />
                @if ($property->remindDays() !== null)
                    <p class="mt-1 text-xs text-gray-400">🔔 {{ $property->remindDays() === 0 ? __('Reminder on the day') : trans_choice('Reminder :count day before|Reminder :count days before', $property->remindDays()) }}</p>
                @endif
                @break

            @case(CollectionProperty::TYPE_URL)
                <input id="{{ $id }}" type="url" inputmode="url" placeholder="https://" wire:model="{{ $model }}" class="{{ $input }}" />
                @break

            @case(CollectionProperty::TYPE_PERSON)
                <select id="{{ $id }}" wire:model="{{ $model }}" class="{{ $input }}">
                    <option value="">{{ __('Nobody') }}</option>
                    @foreach ($members as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                    @endforeach
                </select>
                @break

            @case(CollectionProperty::TYPE_SELECT)
                <div class="flex gap-2">
                    <select id="{{ $id }}" wire:model="{{ $model }}" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach ($property->selectOptions() as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    <button type="button" x-data x-on:click="const name = window.prompt(@js(__('Name of the new option:'))); if (name && name.trim()) { $wire.addFormOption({{ $property->id }}, name.trim()) }" class="shrink-0 rounded-lg px-3 text-sm text-gray-500 ring-1 ring-gray-300 hover:bg-gray-50 dark:ring-gray-600 dark:hover:bg-gray-700" title="{{ __('New option …') }}" aria-label="{{ __('New option …') }}">＋</button>
                </div>
                @break

            @case(CollectionProperty::TYPE_MULTI_SELECT)
                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ $property->name }}">
                    @foreach ($property->selectOptions() as $option)
                        <label class="cursor-pointer">
                            <input type="checkbox" value="{{ $option['id'] }}" wire:model="{{ $model }}" class="peer sr-only" />
                            <span class="inline-flex items-center rounded-full px-3 py-1 text-sm ring-1 ring-gray-300 dark:ring-gray-600 text-gray-600 dark:text-gray-300 peer-checked:bg-indigo-600 peer-checked:text-white peer-checked:ring-indigo-600 peer-focus-visible:ring-2">{{ $option['name'] }}</span>
                        </label>
                    @endforeach
                    <button type="button" x-data x-on:click="const name = window.prompt(@js(__('Name of the new option:'))); if (name && name.trim()) { $wire.addFormOption({{ $property->id }}, name.trim()) }" class="rounded-full px-3 py-1 text-sm text-indigo-600 dark:text-indigo-400 ring-1 ring-dashed ring-indigo-300">+ {{ __('New option …') }}</button>
                </div>
                @break

            @case(CollectionProperty::TYPE_RELATION)
                <div
                    class="relative"
                    x-data="{
                        open: false, query: '', results: [],
                        keys: $wire.entangle('{{ $model }}'),
                        names: @js(collect($nodes)->map(fn ($node) => ($node['icon'] ?? '').' '.$node['title'])->all()),
                        async search() { this.results = await $wire.linkTargets(this.query) },
                        add(node) { const key = node.type + ':' + node.id; this.names[key] = node.icon + ' ' + node.title; this.keys = [...new Set([...(this.keys || []), key])]; this.open = false; this.query = '' },
                        remove(key) { this.keys = (this.keys || []).filter((other) => other !== key) },
                    }"
                    x-on:click.outside="open = false"
                >
                    <div class="flex flex-wrap items-center gap-1.5">
                        <template x-for="key in (keys || [])" :key="key">
                            <span class="inline-flex items-center gap-1 rounded-full bg-indigo-50 dark:bg-indigo-900/40 px-2.5 py-1 text-sm text-indigo-700 dark:text-indigo-200">
                                <span x-text="names[key] || key"></span>
                                <button type="button" x-on:click="remove(key)" class="text-indigo-400 hover:text-red-600" aria-label="{{ __('Remove') }}">×</button>
                            </span>
                        </template>
                        <button type="button" x-on:click="open = true; $nextTick(() => $refs.search.focus()); search()" class="rounded-full px-3 py-1 text-sm text-indigo-600 dark:text-indigo-400 ring-1 ring-indigo-300">+ {{ __('Link with …') }}</button>
                    </div>
                    <div x-show="open" x-cloak class="absolute z-30 mt-1 w-full max-w-sm rounded-lg bg-white dark:bg-gray-800 p-2 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700">
                        <input x-ref="search" type="search" x-model="query" x-on:input.debounce.250ms="search()" placeholder="{{ __('Search …') }}" class="{{ $input }} text-sm" />
                        <ul class="mt-1 max-h-56 overflow-y-auto">
                            <template x-for="node in results" :key="node.type + node.id">
                                <li><button type="button" x-on:click="add(node)" class="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-start text-sm hover:bg-indigo-50 dark:hover:bg-gray-700"><span x-text="node.icon"></span><span class="flex-1 truncate" x-text="node.title"></span><span class="text-xs text-gray-400" x-text="node.kind"></span></button></li>
                            </template>
                        </ul>
                    </div>
                </div>
                @break

            @case(CollectionProperty::TYPE_FILE)
            @case(CollectionProperty::TYPE_IMAGE)
                <p class="rounded-lg border border-dashed border-gray-300 px-3 py-2.5 text-sm text-gray-400 dark:border-gray-600">
                    {{ $property->type === CollectionProperty::TYPE_IMAGE ? '🖼️' : '📎' }} {{ __('Add after saving (open the entry).') }}
                </p>
                @break

            @default
                <input id="{{ $id }}" type="text" wire:model="{{ $model }}" class="{{ $input }}" />
        @endswitch
    @endif
    @if ($property->help)
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $property->help }}</p>
    @endif
    <x-input-error :messages="$errors->get($model)" class="mt-1" />
</div>
