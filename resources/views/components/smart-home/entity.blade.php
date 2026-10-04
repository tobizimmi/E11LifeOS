@props([
    'entity',
    // tile, button, value, gauge, slider, history, row
    'display' => 'tile',
    'mayControl' => false,
    'title' => null,
    // ['min' => float, 'max' => float, 'hours' => int]
    'options' => [],
    // list of ['label' => string, 'value' => float] for the history chart
    'history' => [],
])

@php
    $name = $title ?: $entity->name;
    $critical = \App\Support\SmartHome\EntityControl::isCritical($entity);
    $tappable = $mayControl && ($entity->isSwitchable() || $entity->isRunnable() || $critical);
    $on = $entity->isOn() && ! $entity->isRunnable();
    $press = $tappable ? 'wire:click="pressEntity('.$entity->id.', '.($critical ? 'true' : 'false').')"'.($critical ? ' wire:confirm="'.e(__('Really switch :name?', ['name' => $name])).'"' : '') : 'disabled';
    $state = $entity->isRunnable() ? __('Start') : $entity->displayState();
    $slider = $mayControl ? match ($entity->domain()) {
        'light' => ['min' => 1, 'max' => 100, 'step' => 1, 'value' => round(((int) ($entity->ha_attributes['brightness'] ?? 0)) / 2.55), 'unit' => ' %'],
        'climate' => ['min' => 5, 'max' => 30, 'step' => 0.5, 'value' => (float) ($entity->ha_attributes['temperature'] ?? 20), 'unit' => ' °C'],
        'cover' => isset($entity->ha_attributes['current_position']) ? ['min' => 0, 'max' => 100, 'step' => 5, 'value' => (int) $entity->ha_attributes['current_position'], 'unit' => ' %'] : null,
        default => null,
    } : null;
    $current = $entity->domain() === 'climate' && isset($entity->ha_attributes['current_temperature']) ? number_format((float) $entity->ha_attributes['current_temperature'], 1, ',', '.').' °C' : null;
    $surface = $on ? 'bg-amber-100 dark:bg-amber-900/40' : 'bg-white dark:bg-gray-800';
@endphp

@if ($display === 'camera')
    {{-- Picture through the server (token stays there), refreshed every ten seconds. --}}
    <div {{ $attributes->merge(['class' => 'relative flex h-full flex-col overflow-hidden rounded-2xl bg-gray-900 shadow-xs']) }}
         data-src="{{ route('smart-home.camera', $entity) }}" x-data="{ src: $el.dataset.src, stamp: Date.now(), failed: false }" x-init="setInterval(() => stamp = Date.now(), 10000)">
        <img :src="src + '?t=' + stamp" x-on:error="failed = true" x-on:load="failed = false" alt="{{ $name }}" class="h-full min-h-32 w-full flex-1 object-cover" loading="lazy">
        <p x-show="failed" x-cloak class="absolute inset-0 flex items-center justify-center text-sm text-gray-300">{{ __('Camera not reachable') }}</p>
        <span class="absolute bottom-2 start-2 rounded-full bg-black/50 px-2 py-0.5 text-xs text-white">📷 {{ $name }}</span>
    </div>
@elseif ($display === 'button')
    <div {{ $attributes->merge(['class' => "flex h-full flex-col items-center justify-center gap-2 rounded-2xl p-4 shadow-xs $surface"]) }}>
        <button type="button" {!! $press !!} aria-pressed="{{ $on ? 'true' : 'false' }}"
            @class(['flex size-20 items-center justify-center rounded-full text-4xl shadow-sm transition', 'bg-amber-400 text-white dark:bg-amber-500' => $on, 'bg-gray-100 dark:bg-gray-700' => ! $on, 'active:scale-95' => $tappable, 'cursor-default opacity-70' => ! $tappable])>{{ $entity->icon() }}</button>
        <span class="text-center text-sm font-medium text-gray-900 dark:text-gray-100">{{ $name }}</span>
        <span class="text-xs text-gray-600 dark:text-gray-400">{{ $state }}</span>
    </div>
@elseif ($display === 'value')
    <div {{ $attributes->merge(['class' => 'flex h-full flex-col justify-between rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800']) }}>
        <span class="text-sm text-gray-600 dark:text-gray-400">{{ $entity->icon() }} {{ $name }}</span>
        <span class="mt-2 text-3xl font-semibold tracking-tight tabular-nums text-gray-900 dark:text-gray-100">{{ $state }}</span>
        @if ($current)<span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Currently :value', ['value' => $current]) }}</span>@endif
    </div>
@elseif ($display === 'gauge')
    @php
        $min = (float) ($options['min'] ?? 0);
        $max = (float) ($options['max'] ?? ($entity->unit === '°C' ? 40 : 100));
        $share = is_numeric($entity->state) && $max > $min ? max(0, min(1, ((float) $entity->state - $min) / ($max - $min))) : 0;
        // Three-quarter ring: 270° of a circle with radius 40 (circumference 251.33).
        $arc = 188.5;
    @endphp
    <div {{ $attributes->merge(['class' => '@container flex h-full flex-col items-center justify-center rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800']) }}>
        <svg viewBox="0 0 100 100" class="h-auto w-full max-w-28 flex-1 @[16rem]:max-w-44" role="img" aria-label="{{ $name }}: {{ $state }}">
            <circle cx="50" cy="50" r="40" fill="none" stroke-width="9" stroke-linecap="round" class="stroke-gray-200 dark:stroke-gray-700" stroke-dasharray="{{ $arc }} 251.33" transform="rotate(135 50 50)" />
            <circle cx="50" cy="50" r="40" fill="none" stroke-width="9" stroke-linecap="round" class="stroke-indigo-500" stroke-dasharray="{{ round($arc * $share, 2) }} 251.33" transform="rotate(135 50 50)" />
            <text x="50" y="54" text-anchor="middle" class="fill-gray-900 text-[13px] font-semibold dark:fill-gray-100">{{ $state }}</text>
        </svg>
        <span class="-mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $name }}</span>
    </div>
@elseif ($display === 'slider')
    <div {{ $attributes->merge(['class' => "flex h-full flex-col justify-between rounded-2xl p-4 shadow-xs $surface"]) }}>
        <button type="button" {!! $press !!} @class(['flex items-center gap-2 text-start', 'active:scale-[0.98]' => $tappable, 'cursor-default' => ! $tappable])>
            <span class="text-2xl">{{ $entity->icon() }}</span>
            <span>
                <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $name }}</span>
                <span class="block text-xs text-gray-600 dark:text-gray-400">{{ $state }}@if ($current) · {{ $current }}@endif</span>
            </span>
        </button>
        @if ($slider)
            <label class="mt-3 block text-sm text-gray-700 dark:text-gray-300" x-data="{ value: {{ $slider['value'] }} }">
                <span class="text-2xl font-semibold tabular-nums" x-text="value + '{{ $slider['unit'] }}'"></span>
                <input type="range" min="{{ $slider['min'] }}" max="{{ $slider['max'] }}" step="{{ $slider['step'] }}" x-model="value" x-on:change="$wire.setEntityValue({{ $entity->id }}, Number(value))" class="mt-1 h-8 w-full accent-indigo-600" aria-label="{{ $name }}">
            </label>
        @endif
    </div>
@elseif ($display === 'history')
    <div {{ $attributes->merge(['class' => 'flex h-full flex-col rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800']) }}>
        <div class="flex items-baseline justify-between gap-2">
            <span class="text-sm text-gray-600 dark:text-gray-400">{{ $entity->icon() }} {{ $name }}</span>
            <span class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $state }}</span>
        </div>
        <x-line-chart class="mt-2" :points="$history" :value-label="$name" :currency="false" :suffix="$entity->unit ? ' '.$entity->unit : ''" :height="120" />
    </div>
@elseif ($display === 'row')
    <div {{ $attributes->merge(['class' => "flex h-full items-center gap-3 rounded-2xl px-4 py-3 shadow-xs $surface"]) }}>
        <span class="text-xl">{{ $entity->icon() }}</span>
        <span class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $name }}</span>
        @if ($tappable && $entity->isSwitchable())
            <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ $name }}" {!! $press !!}
                @class(['relative inline-flex h-7 w-12 shrink-0 rounded-full transition', 'bg-emerald-500' => $on, 'bg-gray-300 dark:bg-gray-600' => ! $on])>
                <span @class(['absolute top-0.5 size-6 rounded-full bg-white shadow transition-all', 'start-[1.375rem]' => $on, 'start-0.5' => ! $on])></span>
            </button>
        @elseif ($tappable)
            <button type="button" {!! $press !!} class="rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-800 active:scale-95 dark:bg-gray-700 dark:text-gray-100">{{ $state }}</button>
        @else
            <span class="text-sm tabular-nums text-gray-700 dark:text-gray-300">{{ $state }}</span>
        @endif
    </div>
@else
    <div {{ $attributes->merge(['class' => "flex h-full min-h-20 flex-col justify-between rounded-2xl p-3 shadow-xs transition $surface"]) }}>
        <button type="button" {!! $press !!} @class(['flex flex-col items-start text-start', 'active:scale-[0.97]' => $tappable, 'cursor-default' => ! $tappable])>
            <span class="text-xl">{{ $entity->icon() }}</span>
            <span class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $name }}</span>
            <span class="text-xs text-gray-600 dark:text-gray-400">{{ $state }}@if ($current) · {{ $current }}@endif</span>
        </button>
        @if ($slider)
            <label class="mt-2 block text-xs text-gray-600 dark:text-gray-400" x-data="{ value: {{ $slider['value'] }} }">
                <span x-text="value + '{{ $slider['unit'] }}'"></span>
                <input type="range" min="{{ $slider['min'] }}" max="{{ $slider['max'] }}" step="{{ $slider['step'] }}" x-model="value" x-on:change="$wire.setEntityValue({{ $entity->id }}, Number(value))" class="w-full accent-indigo-600" aria-label="{{ $name }}">
            </label>
        @endif
    </div>
@endif
