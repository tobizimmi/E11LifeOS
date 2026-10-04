{{-- Fields of one trigger, condition or action in the automation editor ($path = wire:model prefix). --}}
@php
    $type = $block['type'] ?? '';
    $operators = \App\Support\Automation\AutomationCatalog::OPERATORS;
    $entityList = 'automation-entities';
@endphp

@if ($kind !== 'action' && $type === 'calendar')
    @foreach (\App\Support\Automation\AutomationCatalog::calendarSources() as $source => $label)
        <label class="flex items-center gap-1"><input type="checkbox" value="{{ $source }}" wire:model="{{ $path }}.sources" class="rounded-sm border-gray-300 text-indigo-600"> {{ $label }}</label>
    @endforeach
    <input type="text" wire:model="{{ $path }}.keyword" class="w-36 {{ $small }}" placeholder="{{ __('Holiday') }}" aria-label="{{ __('Word in the title') }}">
    @if ($kind === 'trigger')
        <select wire:model="{{ $path }}.edge" class="{{ $small }}" aria-label="{{ __('When') }}">
            <option value="start">{{ __('begins') }}</option>
            <option value="end">{{ __('ends') }}</option>
        </select>
    @else
        <select wire:model="{{ $path }}.active" class="{{ $small }}" aria-label="{{ __('Condition') }}">
            <option value="1">{{ __('is entered today') }}</option>
            <option value="0">{{ __('is not entered today') }}</option>
        </select>
    @endif
@elseif ($type === 'mode')
    <select wire:model="{{ $path }}.key" class="{{ $small }}" aria-label="{{ __('Mode') }}">
        @foreach ($modes as $mode)
            <option value="{{ $mode->key }}">{{ $mode->icon }} {{ $mode->name }}</option>
        @endforeach
    </select>
    @if ($kind === 'trigger')
        <select wire:model="{{ $path }}.edge" class="{{ $small }}"><option value="on">{{ __('is switched on') }}</option><option value="off">{{ __('is switched off') }}</option></select>
    @else
        <select wire:model="{{ $path }}.active" class="{{ $small }}"><option value="1">{{ $kind === 'action' ? __('switch on') : __('is on') }}</option><option value="0">{{ $kind === 'action' ? __('switch off') : __('is off') }}</option></select>
    @endif
@elseif ($type === 'time')
    <input type="time" wire:model="{{ $path }}.at" class="{{ $small }}" aria-label="{{ __('Time') }}">
    @foreach ($weekdays as $day => $label)
        <label class="flex items-center gap-0.5"><input type="checkbox" value="{{ $day }}" wire:model="{{ $path }}.days" class="rounded-sm border-gray-300 text-indigo-600"> {{ $label }}</label>
    @endforeach
    <span class="text-xs text-gray-500">{{ __('no day = every day') }}</span>
@elseif ($type === 'time_window' || $type === 'presence_start')
    @if ($type === 'presence_start')
        <div class="flex w-full flex-wrap gap-2">
            @forelse ($entities->filter(fn ($entity) => in_array($entity->domain(), ['light', 'switch'], true)) as $entity)
                <label class="flex items-center gap-1"><input type="checkbox" value="{{ $entity->entity_id }}" wire:model="{{ $path }}.entities" class="rounded-sm border-gray-300 text-indigo-600"> {{ $entity->icon() }} {{ $entity->name }}</label>
            @empty
                <span class="text-xs text-amber-700 dark:text-amber-300">{{ __('Add lights under Smart home › Connections & devices first.') }}</span>
            @endforelse
        </div>
    @endif
    {{ __('from') }} <input type="time" wire:model="{{ $path }}.from" class="{{ $small }}"> {{ __('to') }} <input type="time" wire:model="{{ $path }}.until" class="{{ $small }}">
@elseif ($type === 'weekdays')
    @foreach ($weekdays as $day => $label)
        <label class="flex items-center gap-0.5"><input type="checkbox" value="{{ $day }}" wire:model="{{ $path }}.days" class="rounded-sm border-gray-300 text-indigo-600"> {{ $label }}</label>
    @endforeach
@elseif ($type === 'entity')
    <input type="text" list="{{ $entityList }}" wire:model="{{ $path }}.entity_id" class="w-56 {{ $small }}" placeholder="binary_sensor.fenster" aria-label="{{ __('Entity') }}">
    <select wire:model="{{ $path }}.operator" class="{{ $small }}">@foreach ($operators as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
    <input type="text" wire:model="{{ $path }}.value" class="w-24 {{ $small }}" placeholder="on" aria-label="{{ __('Value') }}">
@elseif ($type === 'evcc')
    <select wire:model="{{ $path }}.metric" class="{{ $small }}">@foreach (\App\Support\Automation\AutomationCatalog::evccMetrics() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
    <select wire:model="{{ $path }}.operator" class="{{ $small }}">@foreach ($operators as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
    <input type="text" wire:model="{{ $path }}.value" class="w-24 {{ $small }}" aria-label="{{ __('Value') }}">
@elseif ($type === 'ha_service')
    <input type="text" wire:model="{{ $path }}.service" class="w-44 {{ $small }}" placeholder="light.turn_on" aria-label="{{ __('Home Assistant service') }}">
    <input type="text" list="{{ $entityList }}" wire:model="{{ $path }}.entity_id" class="w-52 {{ $small }}" placeholder="light.wohnzimmer" aria-label="{{ __('Entity') }}">
    <input type="text" wire:model="{{ $path }}.data" class="w-56 {{ $small }}" placeholder='{"brightness_pct": 40}' aria-label="{{ __('Extra data (JSON)') }}">
@elseif ($type === 'evcc_mode')
    {{ __('Charging point') }} <input type="number" min="1" max="9" wire:model="{{ $path }}.loadpoint" class="w-16 {{ $small }}">
    <select wire:model="{{ $path }}.mode" class="{{ $small }}">@foreach (\App\Support\Automation\AutomationCatalog::evccModes() as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select>
@elseif ($type === 'evcc_limit')
    {{ __('Charging point') }} <input type="number" min="1" max="9" wire:model="{{ $path }}.loadpoint" class="w-16 {{ $small }}">
    <input type="number" min="20" max="100" step="5" wire:model="{{ $path }}.soc" class="w-20 {{ $small }}"> %
@elseif ($type === 'push')
    <select wire:model="{{ $path }}.to" class="{{ $small }}"><option value="me">{{ __('to me') }}</option><option value="household">{{ __('to the whole household') }}</option></select>
    <input type="text" wire:model="{{ $path }}.title" class="w-48 {{ $small }}" placeholder="{{ __('Title') }}">
    <input type="text" wire:model="{{ $path }}.body" class="w-56 {{ $small }}" placeholder="{{ __('Text') }}">
    <label class="flex items-center gap-1"><input type="checkbox" wire:model="{{ $path }}.urgent" class="rounded-sm border-gray-300 text-indigo-600"> {{ __('urgent') }}</label>
@elseif ($type === 'task')
    <input type="text" wire:model="{{ $path }}.title" class="w-56 {{ $small }}" placeholder="{{ __('e.g. Water the plants') }}">
    <select wire:model="{{ $path }}.due" class="{{ $small }}"><option value="today">{{ __('due today') }}</option><option value="tomorrow">{{ __('due tomorrow') }}</option><option value="">{{ __('no date') }}</option></select>
@elseif ($type === 'manual')
    <span class="text-xs text-gray-500">{{ __('Runs only with “Run now”.') }}</span>
@endif

@once
    <datalist id="automation-entities">
        @foreach ($entities as $entity)
            <option value="{{ $entity->entity_id }}">{{ $entity->name }}</option>
        @endforeach
    </datalist>
@endonce
