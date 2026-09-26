@props([
    // list of ['label' => string, 'value' => float, 'compare' => ?float]
    'points' => [],
    'valueLabel' => __('Value'),
    'compareLabel' => null,
    'height' => 220,
    'currency' => true,
])

@php
    $values = collect($points)->flatMap(fn ($point) => array_filter([$point['value'], $point['compare'] ?? null], fn ($value) => $value !== null));
    $min = $values->min() ?? 0;
    $max = $values->max() ?? 1;
    $padding = ($max - $min) * 0.08 ?: max(1, abs($max) * 0.05);
    $low = $min - $padding;
    $high = $max + $padding;
    $count = count($points);
    $x = fn (int $index) => $count > 1 ? round($index / ($count - 1) * 1000, 2) : 500;
    $y = fn (float $value) => round(($high - $value) / ($high - $low) * 100, 3);
    $line = fn (string $key) => collect($points)->map(fn ($point, $index) => isset($point[$key]) ? $x($index).','.$y((float) $point[$key]) : null)->filter()->implode(' ');
    $hasCompare = $compareLabel !== null && collect($points)->contains(fn ($point) => isset($point['compare']));
    $rising = $count > 1 && $points[$count - 1]['value'] >= ($hasCompare ? ($points[$count - 1]['compare'] ?? $points[0]['value']) : $points[0]['value']);
    $format = fn (float $value) => number_format($value, $currency ? 2 : 0, ',', '.').($currency ? ' €' : '');
    $ticks = collect([0, 0.5, 1])->map(fn ($share) => $low + ($high - $low) * $share);
@endphp

<div {{ $attributes->merge(['class' => 'line-chart relative']) }}
     x-data="{ index: null, points: {{ \Illuminate\Support\Js::from(collect($points)->map(fn ($point) => ['label' => $point['label'], 'value' => $format($point['value']), 'compare' => isset($point['compare']) ? $format($point['compare']) : null])->values()) }},
               move(clientX, element) { const box = element.getBoundingClientRect(); const share = Math.min(1, Math.max(0, (clientX - box.left) / box.width)); this.index = Math.round(share * (this.points.length - 1)); } }">
    <style>
        .line-chart { --chart-up: #0f8a5f; --chart-down: #c2410c; --chart-compare: #9ca3af; --chart-grid: #e5e7eb; }
        @media (prefers-color-scheme: dark) { :root:where(:not([data-theme="light"])) .line-chart { --chart-up: #34c38f; --chart-down: #f97316; --chart-compare: #6b7280; --chart-grid: #374151; } }
        :root[data-theme="dark"] .line-chart { --chart-up: #34c38f; --chart-down: #f97316; --chart-compare: #6b7280; --chart-grid: #374151; }
    </style>

    @if ($count < 2)
        <div class="flex items-center justify-center rounded-xl bg-gray-50 text-sm text-gray-500 dark:bg-gray-900/40 dark:text-gray-400" style="height: {{ $height }}px">{{ __('Not enough data for a chart yet.') }}</div>
    @else
        @if ($hasCompare)
            <div class="mb-2 flex flex-wrap gap-4 text-xs text-gray-600 dark:text-gray-300">
                <span class="flex items-center gap-1.5"><span class="h-0.5 w-4 rounded" style="background: {{ $rising ? 'var(--chart-up)' : 'var(--chart-down)' }}"></span>{{ $valueLabel }}</span>
                <span class="flex items-center gap-1.5"><span class="h-0 w-4 border-t-2 border-dashed" style="border-color: var(--chart-compare)"></span>{{ $compareLabel }}</span>
            </div>
        @endif
        <div class="flex gap-2">
            <div class="relative flex-1" style="height: {{ $height }}px" x-on:mousemove="move($event.clientX, $el)" x-on:mouseleave="index = null" x-on:touchmove.passive="move($event.touches[0].clientX, $el)" x-on:touchend="index = null">
                <svg viewBox="0 0 1000 100" preserveAspectRatio="none" class="absolute inset-0 h-full w-full overflow-visible" role="img" aria-label="{{ $valueLabel }}: {{ $points[0]['label'] }} {{ $format($points[0]['value']) }} – {{ $points[$count - 1]['label'] }} {{ $format($points[$count - 1]['value']) }}">
                    @foreach ($ticks as $tick)
                        <line x1="0" x2="1000" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}" stroke="var(--chart-grid)" stroke-width="1" vector-effect="non-scaling-stroke" />
                    @endforeach
                    @if ($hasCompare)
                        <polyline points="{{ $line('compare') }}" fill="none" stroke="var(--chart-compare)" stroke-width="1.5" stroke-dasharray="4 4" vector-effect="non-scaling-stroke" />
                    @endif
                    <polyline points="{{ $line('value') }}" fill="none" stroke="{{ $rising ? 'var(--chart-up)' : 'var(--chart-down)' }}" stroke-width="2" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                </svg>
                <template x-if="index !== null">
                    <div class="pointer-events-none absolute inset-y-0" :style="`left: ${points.length > 1 ? index / (points.length - 1) * 100 : 50}%`">
                        <div class="h-full border-l border-gray-400 dark:border-gray-500"></div>
                        <div class="absolute top-0 z-10 whitespace-nowrap rounded-lg bg-gray-900 px-2 py-1 text-xs text-white shadow" :class="index > points.length / 2 ? 'right-2' : 'left-2'">
                            <div class="text-gray-300" x-text="points[index].label"></div>
                            <div><span>{{ $valueLabel }}:</span> <strong x-text="points[index].value"></strong></div>
                            @if ($hasCompare)
                                <div x-show="points[index].compare"><span>{{ $compareLabel }}:</span> <span x-text="points[index].compare"></span></div>
                            @endif
                        </div>
                    </div>
                </template>
            </div>
            <div class="flex w-16 shrink-0 flex-col justify-between text-end text-[10px] tabular-nums text-gray-500 dark:text-gray-400" style="height: {{ $height }}px" aria-hidden="true">
                @foreach ($ticks->reverse() as $tick)
                    <span>{{ number_format($tick, abs($high - $low) < 20 ? 2 : 0, ',', '.') }}</span>
                @endforeach
            </div>
        </div>
        <div class="mt-1 flex justify-between pe-[4.5rem] text-[10px] text-gray-500 dark:text-gray-400" aria-hidden="true">
            <span>{{ $points[0]['label'] }}</span>
            <span>{{ $points[intdiv($count, 2)]['label'] }}</span>
            <span>{{ $points[$count - 1]['label'] }}</span>
        </div>
    @endif
</div>
