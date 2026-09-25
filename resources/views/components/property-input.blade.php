@props(['property', 'item', 'members', 'compact' => false])

{{-- Inline editor for one property value; changes call updateProperty() on the surrounding Livewire component. --}}
@use('App\Models\CollectionProperty')

@php
    $value = $item->propertyValue($property);
    $call = fn (string $js) => "updateProperty({$item->id}, {$property->id}, {$js})";
    $field = 'block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 '
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

    @case(CollectionProperty::TYPE_CHECKBOX)
        <input
            type="checkbox"
            aria-label="{{ $label }}"
            @checked($value)
            wire:change="{{ $call('$event.target.checked') }}"
            class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 shadow-sm focus:ring-indigo-500"
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

    @default
        <input type="text" aria-label="{{ $label }}" value="{{ $value }}" wire:change="{{ $call('$event.target.value') }}" class="{{ $field }}" />
@endswitch
