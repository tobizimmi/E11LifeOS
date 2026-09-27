<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\PortfolioRecommendation;
use App\Models\SecurityPrice;
use App\Models\WatchlistItem;
use App\Support\Portfolio\RecommendationEngine;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    #[Url(except: '')]
    public string $rating = '';

    /**
     * @var array{threshold: string, drop_days: string, hold_days: string}
     */
    public array $backtest = ['threshold' => '10', 'drop_days' => '60', 'hold_days' => '30'];

    /** @var array<string, mixed>|null */
    public ?array $backtestResult = null;

    public function refresh(): void
    {
        $count = RecommendationEngine::refreshFor($this->currentUser());
        $this->dispatch('toast', message: trans_choice(':count recommendation|:count recommendations', $count));
    }

    public function watch(string $symbol): void
    {
        $recommendation = PortfolioRecommendation::where('user_id', $this->currentUser()->id)->where('symbol', $symbol)->first() ?? abort(404);
        WatchlistItem::firstOrCreate(['user_id' => $this->currentUser()->id, 'symbol' => $recommendation->symbol], ['name' => $recommendation->name]);
    }

    public function runBacktest(): void
    {
        $data = $this->validate([
            'backtest.threshold' => ['required', 'numeric', 'min:1', 'max:80'],
            'backtest.drop_days' => ['required', 'integer', 'min:10', 'max:250'],
            'backtest.hold_days' => ['required', 'integer', 'min:5', 'max:250'],
        ])['backtest'];

        $symbols = RecommendationEngine::universe($this->currentUser())->pluck('symbol');
        $prices = SecurityPrice::whereIn('symbol', $symbols)->where('date', '>=', now()->subYears(2)->toDateString())->orderBy('date')->get(['symbol', 'date', 'close'])->groupBy('symbol');

        $this->backtestResult = RecommendationEngine::backtest($prices, (float) $data['threshold'], (int) $data['drop_days'], (int) $data['hold_days']);
    }

    public function with(): array
    {
        $settings = $this->portfolioSettings();
        $query = PortfolioRecommendation::where('user_id', $this->currentUser()->id);

        return [
            'recommendations' => (clone $query)->when($this->rating !== '', fn ($query) => $query->where('rating', $this->rating))->orderByDesc('score')->limit(60)->get(),
            'counts' => (clone $query)->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->pluck('total', 'rating'),
            'computedAt' => (clone $query)->max('computed_at'),
            'config' => (new RecommendationEngine($settings->exists ? $settings : null))->config(),
            'watched' => $this->watchlist()->pluck('symbol')->flip(),
            'held' => \App\Models\PortfolioPosition::whereIn('portfolio_id', $this->portfolios()->select('id'))->pluck('symbol')->flip(),
        ];
    }
}; ?>

@php
    $ratings = [
        'strong_buy' => [__('Strong buy'), 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-100'],
        'moderate_buy' => [__('Buy'), 'bg-sky-100 text-sky-900 dark:bg-sky-900/50 dark:text-sky-100'],
        'watchlist_buy' => [__('Watch'), 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100'],
    ];
    $strategies = ['conservative' => __('Conservative'), 'moderate' => __('Moderate'), 'aggressive' => __('Aggressive'), 'thieme' => __('Thieme (200-day line)'), 'custom' => __('Custom')];
    $signed = fn ($value) => $value === null ? '—' : ($value > 0 ? '+' : ($value < 0 ? '−' : '±')).number_format(abs((float) $value), 2, ',', '.').' %';
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">📈 {{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => 'portfolio.recommendations'])

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Buy recommendations') }}</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ __('Strategy: :strategy – price down at least :drop % within :days days, RSI below :rsi.', ['strategy' => $strategies[$config['strategy']] ?? $config['strategy'], 'drop' => number_format($config['drop_threshold'], 0, ',', '.'), 'days' => $config['drop_period_days'], 'rsi' => number_format($config['rsi_threshold'], 0, ',', '.')]) }}
                            <a href="{{ route('portfolio.settings') }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-300">{{ __('Change') }}</a>
                        </p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $computedAt ? __('Calculated :time from the daily market scan.', ['time' => \Illuminate\Support\Carbon::parse($computedAt)->diffForHumans()]) : __('Calculated daily from the market scan, your holdings and your watchlist.') }} {{ __('No investment advice.') }}</p>
                    </div>
                    <button type="button" wire:click="refresh" wire:loading.attr="disabled" class="rounded-lg px-3 py-2 text-sm text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-700">↻ {{ __('Recalculate') }}</button>
                </div>
                <div class="mt-4 flex flex-wrap gap-1.5">
                    <button type="button" wire:click="$set('rating', '')" @class(['rounded-full px-3 py-1 text-sm', 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' => $rating === '', 'ring-1 ring-gray-200 text-gray-700 hover:bg-gray-50 dark:ring-gray-700 dark:text-gray-300' => $rating !== ''])>{{ __('All') }} ({{ $counts->sum() }})</button>
                    @foreach ($ratings as $key => [$label, $classes])
                        <button type="button" wire:click="$set('rating', '{{ $key }}')" @class(['rounded-full px-3 py-1 text-sm', 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' => $rating === $key, 'ring-1 ring-gray-200 text-gray-700 hover:bg-gray-50 dark:ring-gray-700 dark:text-gray-300' => $rating !== $key])>{{ $label }} ({{ $counts[$key] ?? 0 }})</button>
                    @endforeach
                </div>
            </section>

            @if ($recommendations->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center text-sm text-gray-600 shadow-sm dark:bg-gray-800 dark:text-gray-400">
                    <p class="text-4xl" aria-hidden="true">🔎</p>
                    <p class="mt-2">{{ __('No securities match your strategy right now. Markets are calm – or the daily scan has not run yet.') }}</p>
                </div>
            @else
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($recommendations as $recommendation)
                        <article class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800" wire:key="recommendation-{{ $recommendation->id }}">
                            <div class="flex items-start gap-3">
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('portfolio.security', ['symbol' => $recommendation->symbol]) }}" wire:navigate class="block truncate font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $recommendation->name }}</a>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $recommendation->symbol }}{{ $recommendation->data['sector'] ?? null ? ' · '.$recommendation->data['sector'] : '' }}
                                        @if ($held->has($recommendation->symbol)) · <span class="text-indigo-600 dark:text-indigo-300">{{ __('in portfolio') }}</span> @endif
                                    </p>
                                </div>
                                <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $ratings[$recommendation->rating][1] }}">{{ $ratings[$recommendation->rating][0] }}</span>
                            </div>
                            <div class="mt-3 flex items-center gap-3">
                                <div class="h-2 flex-1 rounded-full bg-gray-100 dark:bg-gray-700" title="{{ __('Score') }}"><div class="h-2 rounded-full bg-indigo-500" style="width: {{ $recommendation->score }}%"></div></div>
                                <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($recommendation->score, 0) }}</span>
                            </div>
                            <dl class="mt-3 grid grid-cols-4 gap-2 text-xs">
                                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Price') }}</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($recommendation->price, 2, ',', '.') }} {{ $recommendation->currency }}</dd></div>
                                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Drop') }}</dt><dd class="tabular-nums text-orange-700 dark:text-orange-400">−{{ number_format($recommendation->drop_percent, 1, ',', '.') }} %</dd></div>
                                <div><dt class="text-gray-500 dark:text-gray-400">RSI</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((float) $recommendation->rsi, 0) }}</dd></div>
                                <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Quality') }}</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((float) $recommendation->stability, 0) }}</dd></div>
                            </dl>
                            <ul class="mt-3 space-y-0.5 text-xs text-gray-600 dark:text-gray-300">
                                @foreach (array_slice($recommendation->reasons ?? [], 0, 4) as $reason)
                                    <li>• {{ $reason }}</li>
                                @endforeach
                            </ul>
                            <div class="mt-3 flex gap-2">
                                @if ($watched->has($recommendation->symbol))
                                    <span class="rounded-lg px-2 py-1 text-xs text-gray-500">★ {{ __('On the watchlist') }}</span>
                                @else
                                    <button type="button" wire:click="watch('{{ $recommendation->symbol }}')" class="rounded-lg px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-gray-700">☆ {{ __('Add to watchlist') }}</button>
                                    <button type="button" wire:click="createTask('{{ $recommendation->symbol }}', @js(__('Buy :name?', ['name' => $recommendation->name])))" class="rounded-lg px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700">✅ {{ __('Task') }}</button>
                                @endif
                                <a href="{{ route('portfolio.transactions', ['new' => 1, 'symbol' => $recommendation->symbol]) }}" wire:navigate class="rounded-lg px-2 py-1 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">＋ {{ __('Transaction') }}</a>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            {{-- Backtest --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Backtest of the drop strategy') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('How would it have gone? Each time a price fell by the threshold from its high, it is bought and sold after the holding period (last 2 years, scanned and own securities).') }}</p>
                <form wire:submit="runBacktest" class="mt-4 flex flex-wrap items-end gap-3">
                    <div>
                        <x-input-label for="backtest-threshold" :value="__('Drop (%)')" />
                        <x-text-input id="backtest-threshold" type="number" wire:model="backtest.threshold" class="mt-1 block w-24" />
                    </div>
                    <div>
                        <x-input-label for="backtest-days" :value="__('from the high of (days)')" />
                        <x-text-input id="backtest-days" type="number" wire:model="backtest.drop_days" class="mt-1 block w-24" />
                    </div>
                    <div>
                        <x-input-label for="backtest-hold" :value="__('Holding period (days)')" />
                        <x-text-input id="backtest-hold" type="number" wire:model="backtest.hold_days" class="mt-1 block w-24" />
                    </div>
                    <x-primary-button wire:loading.attr="disabled">{{ __('Run backtest') }}</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('backtest.*')" class="mt-1" />
                @if ($backtestResult)
                    @if ($backtestResult['signals'] === 0)
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('No signals – there is not enough price history yet or the threshold was never reached.') }}</p>
                    @else
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-6">
                            @foreach ([
                                [__('Signals'), number_format($backtestResult['signals'], 0, ',', '.').' · '.trans_choice(':count security|:count securities', $backtestResult['symbols'])],
                                [__('Hit rate'), number_format($backtestResult['win_rate'], 1, ',', '.').' %'],
                                [__('Ø return'), $signed($backtestResult['average'])],
                                [__('Median'), $signed($backtestResult['median'])],
                                [__('Best'), $signed($backtestResult['best'])],
                                [__('Worst'), $signed($backtestResult['worst'])],
                            ] as [$label, $value])
                                <div class="rounded-xl bg-gray-50 px-3 py-2 dark:bg-gray-900/40"><dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                @endif
            </section>
        </div>
    </div>
</div>
