@props(['property', 'item', 'members', 'compact' => false, 'nodes' => []])

{{-- Inline editor for one property value; changes call updateProperty() on the surrounding Livewire component. --}}
@use('App\Models\CollectionProperty')

@php
    $value = $item->propertyValue($property);
    $call = fn (string $js) => "updateProperty({$item->id}, {$property->id}, {$js})";
    $field = 'block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 '
        .($compact ? 'border-transparent bg-transparent dark:bg-transparent py-1 px-2 text-sm hover:border-gray-300 dark:hover:border-gray-600' : 'text-sm');
    $isOverdue = $property->system_key === 'due' && $value && ! $item->done && $value < now()->toDateString();
    $label = $property->name;
@endphp

@switch($property->type)
    @case(CollectionProperty::TYPE_SELECT)
        <select
            aria-label="{{ $label }}"
            class="{{ $field }}"
            x-data
            x-on:change="
                if ($event.target.value === '__new__') {
                    const name = window.prompt(@js(__('Name of the new option:')));
                    $event.target.value = @js((string) $value);
                    if (name && name.trim()) { $wire.addOptionAndSet({{ $item->id }}, {{ $property->id }}, name.trim()); }
                } else {
                    $wire.{{ $call('$event.target.value') }};
                }
            "
        >
            <option value="">—</option>
            @foreach ($property->selectOptions() as $option)
                <option value="{{ $option['id'] }}" @selected($value === $option['id'])>{{ $option['name'] }}</option>
            @endforeach
            <option value="__new__">+ {{ __('New option …') }}</option>
        </select>
        @break

    @case(CollectionProperty::TYPE_MULTI_SELECT)
        {{-- Several options: a small popover with checkboxes. --}}
        <div
            class="relative"
            x-data="{ open: false, selected: @js(array_values((array) $value)) }"
            x-on:click.outside="open = false"
            x-on:keydown.escape.stop="open = false"
        >
            <button type="button" x-on:click="open = ! open" aria-label="{{ $label }}" :aria-expanded="open" class="{{ $field }} flex min-h-[2rem] flex-wrap items-center gap-1 text-start">
                @forelse ((array) $value as $optionId)
                    @if ($option = $property->option($optionId))
                        <x-option-badge :option="$option" />
                    @endif
                @empty
                    <span class="text-gray-400">—</span>
                @endforelse
            </button>
            <div x-show="open" x-cloak class="absolute z-30 mt-1 w-56 rounded-md bg-white dark:bg-gray-800 p-2 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700 space-y-1">
                @foreach ($property->selectOptions() as $option)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" value="{{ $option['id'] }}" x-model="selected" x-on:change="$wire.{{ $call('selected') }}" class="rounded-sm border-gray-300 dark:border-gray-600 text-indigo-600" />
                        <x-option-badge :option="$option" />
                    </label>
                @endforeach
                <button type="button" x-on:click="const name = window.prompt(@js(__('Name of the new option:'))); if (name && name.trim()) { $wire.addOptionAndSet({{ $item->id }}, {{ $property->id }}, name.trim()) }" class="text-xs text-indigo-600 dark:text-indigo-400 underline">+ {{ __('New option …') }}</button>
            </div>
        </div>
        @break

    @case(CollectionProperty::TYPE_RELATION)
        {{-- Links to pages, tasks, entries or recipes, picked by search. --}}
        <div
            class="relative"
            x-data="{
                open: false,
                query: '',
                results: [],
                keys: @js(array_values((array) $value)),
                async search() { this.results = await $wire.linkTargets(this.query) },
                add(node) { const key = node.type + ':' + node.id; if (! this.keys.includes(key)) { this.keys.push(key); $wire.{{ $call('this.keys') }} } this.open = false; this.query = '' },
                remove(key) { this.keys = this.keys.filter((other) => other !== key); $wire.{{ $call('this.keys') }} },
            }"
            x-on:click.outside="open = false"
            x-on:keydown.escape.stop="open = false"
        >
            <div class="flex flex-wrap items-center gap-1 {{ $compact ? 'px-2 py-1' : '' }}">
                @foreach ((array) $value as $key)
                    @if ($node = $nodes[$key] ?? null)
                        <span class="inline-flex max-w-full items-center gap-1 rounded-sm bg-indigo-50 dark:bg-indigo-900/40 px-1.5 py-0.5 text-xs text-indigo-800 dark:text-indigo-100" wire:key="rel-{{ $item->id }}-{{ $key }}">
                            <a href="{{ $node['url'] }}" class="truncate hover:underline">{{ $node['icon'] }} {{ $node['title'] }}</a>
                            <button type="button" x-on:click="remove(@js($key))" class="text-indigo-400 hover:text-red-600" aria-label="{{ __('Remove link') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                        </span>
                    @endif
                @endforeach
                <button type="button" x-on:click="open = true; $nextTick(() => { $refs.search.focus(); search() })" class="rounded-sm px-1 text-sm text-gray-400 hover:text-indigo-600" aria-label="{{ __('Add link') }}: {{ $label }}">+</button>
            </div>
            <div x-show="open" x-cloak class="absolute z-30 mt-1 w-72 rounded-md bg-white dark:bg-gray-800 p-2 shadow-lg ring-1 ring-gray-200 dark:ring-gray-700">
                <input x-ref="search" x-model="query" x-on:input.debounce.250ms="search()" type="search" placeholder="{{ __('Search pages, tasks, entries, recipes …') }}" class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-sm" />
                <ul class="mt-1 max-h-60 overflow-y-auto">
                    <template x-for="node in results" :key="node.type + node.id">
                        <li>
                            <button type="button" x-on:click="add(node)" class="flex w-full items-center gap-2 rounded-sm px-2 py-1 text-start text-sm hover:bg-gray-100 dark:hover:bg-gray-700">
                                <span x-text="node.icon"></span>
                                <span class="min-w-0 flex-1 truncate" x-text="node.title"></span>
                                <span class="shrink-0 text-xs text-gray-400" x-text="node.kind"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
        @break

    @case(CollectionProperty::TYPE_CHECKBOX)
        <input
            type="checkbox"
            aria-label="{{ $label }}"
            @checked($value)
            wire:change="{{ $call('$event.target.checked') }}"
            class="rounded-sm border-gray-300 dark:border-gray-600 text-indigo-600 shadow-xs focus:ring-indigo-500"
        />
        @break

    @case(CollectionProperty::TYPE_PERSON)
        <select aria-label="{{ $label }}" class="{{ $field }}" wire:change="{{ $call('$event.target.value') }}">
            <option value="">—</option>
            @foreach ($members as $member)
                <option value="{{ $member->id }}" @selected((int) $value === $member->id)>{{ $member->name }}</option>
            @endforeach
        </select>
        @break

    @case(CollectionProperty::TYPE_DATE)
        <input
            type="date"
            aria-label="{{ $label }}"
            value="{{ $value }}"
            wire:change="{{ $call('$event.target.value') }}"
            @class([$field, 'text-red-600 dark:text-red-400 font-medium' => $isOverdue])
            @if ($isOverdue) title="{{ __('Overdue') }}" @endif
        />
        @break

    @case(CollectionProperty::TYPE_NUMBER)
        <input type="number" step="any" aria-label="{{ $label }}" value="{{ $value }}" wire:change="{{ $call('$event.target.value') }}" class="{{ $field }}" />
        @break

    @case(CollectionProperty::TYPE_URL)
        <div class="flex items-center gap-1">
            <input type="url" aria-label="{{ $label }}" value="{{ $value }}" placeholder="https://" wire:change="{{ $call('$event.target.value') }}" class="{{ $field }}" />
            @if ($value)
                <a href="{{ $value }}" target="_blank" rel="noopener noreferrer" class="shrink-0 text-indigo-600 dark:text-indigo-400" title="{{ __('Open link') }}">↗</a>
            @endif
        </div>
        @break

    @case(CollectionProperty::TYPE_LONG_TEXT)
        <textarea aria-label="{{ $label }}" rows="{{ $compact ? 1 : 3 }}" wire:change="{{ $call('$event.target.value') }}" class="{{ $field }}">{{ $value }}</textarea>
        @break

    @case(CollectionProperty::TYPE_RATING)
        <select aria-label="{{ $label }}" class="{{ $field }}" wire:change="{{ $call('$event.target.value') }}">
            <option value="">—</option>
            @for ($i = 1; $i <= 5; $i++)
                <option value="{{ $i }}" @selected((int) $value === $i)>{{ str_repeat('★', $i) }}</option>
            @endfor
        </select>
        @break

    @case(CollectionProperty::TYPE_PROGRESS)
        <div class="flex items-center gap-1">
            <input type="number" min="0" max="100" step="5" aria-label="{{ $label }}" value="{{ $value }}" wire:change="{{ $call('$event.target.value') }}" class="{{ $field }}" />
            <span class="text-xs text-gray-400">%</span>
        </div>
        @break

    @case(CollectionProperty::TYPE_FORMULA)
        {{-- Calculated from other columns; read-only. --}}
        @php $formula = \App\Support\PropertyDisplay::format($property, $item); @endphp
        <span class="inline-flex items-center gap-1 px-2 py-1 text-sm text-gray-700 dark:text-gray-200" title="{{ $property->formulaExpression() }}">
            @if ($formula)
                ƒ {{ $formula['text'] }}
            @else
                <span class="text-gray-400">—</span>
            @endif
        </span>
        @break

    @case(CollectionProperty::TYPE_FILE)
    @case(CollectionProperty::TYPE_IMAGE)
        {{-- Files are managed in the entry detail view; the cell shows how many there are. --}}
        @php $count = count(array_filter((array) $value, 'is_numeric')); @endphp
        <span class="inline-flex items-center gap-1 px-2 py-1 text-sm text-gray-500 dark:text-gray-400">
            @if ($count > 0)
                {{ $property->type === CollectionProperty::TYPE_IMAGE ? '🖼️' : '📎' }}
                {{ $property->type === CollectionProperty::TYPE_IMAGE
                    ? trans_choice(':count image|:count images', $count)
                    : trans_choice(':count file|:count files', $count) }}
            @else
                <span class="text-gray-400">—</span>
            @endif
        </span>
        @break

    @default
        <input type="text" aria-label="{{ $label }}" value="{{ $value }}" wire:change="{{ $call('$event.target.value') }}" class="{{ $field }}" />
@endswitch
