{{-- Overview insights: top recommendations, fresh headlines, strategy to-dos and the latest market take. --}}
@php
    $signalLabel = function (array $signal) {
        return match ($signal['type'] ?? '') {
            'buy_tranche' => __('Buy tranche (:third) around :price', ['third' => $signal['third'] ?? '', 'price' => isset($signal['price']) ? number_format((float) $signal['price'], 2, ',', '.') : '—']),
            'sell' => ($signal['label'] ?? __('Sell signal')).(isset($signal['gain_percent']) ? ' · +'.number_format((float) $signal['gain_percent'], 1, ',', '.').' %' : ''),
            'cluster' => __('Concentration risk: :weight %', ['weight' => number_format((float) ($signal['weight'] ?? 0), 1, ',', '.')]),
            default => __('To-do'),
        };
    };
    $signalTone = fn (array $signal) => match ($signal['type'] ?? '') {
        'sell' => 'text-orange-700 dark:text-orange-300',
        'buy_tranche' => 'text-emerald-700 dark:text-emerald-300',
        default => 'text-amber-700 dark:text-amber-300',
    };
@endphp

<div class="grid gap-4 lg:grid-cols-2">
    {{-- Top recommendations --}}
    <section class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">🔎 {{ __('Top recommendations') }}</h3>
            <a href="{{ route('portfolio.recommendations') }}" wire:navigate class="text-xs text-indigo-600 hover:underline dark:text-indigo-300">{{ __('All') }}</a>
        </div>
        @forelse ($insights['recommendations'] as $rec)
            <a href="{{ route('portfolio.security', ['symbol' => $rec->symbol]) }}" wire:navigate wire:key="rec-{{ $rec->id }}" class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 hover:bg-white dark:hover:bg-gray-800">
                <span class="min-w-0">
                    <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $rec->name ?: $rec->symbol }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $rec->symbol }}@if ($rec->drop_percent) · −{{ number_format((float) $rec->drop_percent, 1, ',', '.') }} % {{ __('from high') }}@endif</span>
                </span>
                <span class="shrink-0 rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200">{{ __('Score') }} {{ number_format((float) $rec->score, 0) }}</span>
            </a>
        @empty
            <p class="px-2 py-1.5 text-sm text-gray-500 dark:text-gray-400">{{ __('No buy candidates right now – the analysis runs daily.') }}</p>
        @endforelse
    </section>

    {{-- Fresh headlines --}}
    <section class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">📰 {{ __('Latest headlines') }}</h3>
            <a href="{{ route('portfolio.news', ['filter' => 'mine']) }}" wire:navigate class="text-xs text-indigo-600 hover:underline dark:text-indigo-300">{{ __('All') }}</a>
        </div>
        @forelse ($insights['headlines'] as $article)
            <a href="{{ $article->url }}" target="_blank" rel="noopener noreferrer" wire:key="news-{{ $article->id }}" class="block rounded-lg px-2 py-1.5 hover:bg-white dark:hover:bg-gray-800">
                <span class="block text-sm text-gray-800 dark:text-gray-200">{{ \Illuminate\Support\Str::limit($article->title, 90) }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $article->symbol }}@if ($article->published_at) · {{ $article->published_at->diffForHumans() }}@endif</span>
            </a>
        @empty
            <p class="px-2 py-1.5 text-sm text-gray-500 dark:text-gray-400">{{ __('No recent headlines on your securities.') }}</p>
        @endforelse
    </section>

    {{-- Strategy to-dos --}}
    <section class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">🎯 {{ __('Strategy to-dos') }}</h3>
            <a href="{{ route('portfolio.strategy') }}" wire:navigate class="text-xs text-indigo-600 hover:underline dark:text-indigo-300">{{ __('Details') }}</a>
        </div>
        @forelse ($insights['signals'] as $signal)
            <a href="{{ route('portfolio.security', ['symbol' => $signal['symbol']]) }}" wire:navigate wire:key="sig-{{ $loop->index }}" class="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 hover:bg-white dark:hover:bg-gray-800">
                <span class="min-w-0 truncate text-sm text-gray-800 dark:text-gray-200">{{ $signal['name'] ?: $signal['symbol'] }}</span>
                <span class="shrink-0 text-xs font-medium {{ $signalTone($signal) }}">{{ $signalLabel($signal) }}</span>
            </a>
        @empty
            <p class="px-2 py-1.5 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing to do – your holdings are within the strategy.') }}</p>
        @endforelse
    </section>

    {{-- Market take (from the analysed Instagram / Heiko Thieme posts) --}}
    <section class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">🎥 {{ __('Current market take') }}</h3>
            <a href="{{ route('portfolio.instagram') }}" wire:navigate class="text-xs text-indigo-600 hover:underline dark:text-indigo-300">{{ __('More') }}</a>
        </div>
        @if ($insights['marketTake'])
            @php($take = $insights['marketTake'])
            <div class="rounded-lg px-2 py-1.5">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ '@'.$take->profile }}@if ($take->published_at) · {{ $take->published_at->diffForHumans() }}@endif @if ($take->stock_name) · {{ $take->stock_name }}@endif</p>
                <p class="mt-1 text-sm text-gray-800 dark:text-gray-200">{{ \Illuminate\Support\Str::limit($take->summary, 240) }}</p>
                @if (is_array($take->key_points) && $take->key_points !== [])
                    <ul class="mt-1.5 space-y-0.5 text-xs text-gray-600 dark:text-gray-300">
                        @foreach (array_slice($take->key_points, 0, 3) as $point)
                            <li class="flex gap-1.5"><span aria-hidden="true">•</span><span>{{ \Illuminate\Support\Str::limit((string) $point, 120) }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @else
            <p class="px-2 py-1.5 text-sm text-gray-500 dark:text-gray-400">{{ __('No analysed video yet.') }}</p>
        @endif
    </section>
</div>
<p class="mt-3 text-center text-xs text-gray-400 dark:text-gray-500">{{ __('No investment advice.') }}</p>
