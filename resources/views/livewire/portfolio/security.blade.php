<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\InvestmentThesis;
use App\Models\PortfolioPosition;
use App\Models\PortfolioTransaction;
use App\Models\WatchlistItem;
use App\Support\Portfolio\KeyFigureCheck;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\PortfolioLink;
use App\Support\Portfolio\Valuation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    #[Locked]
    public string $symbol = '';

    #[Url(except: '1y')]
    public string $range = '1y';

    public bool $editingThesis = false;

    /**
     * @var array{stock_type: string, thesis: string, moat: string, risks: string, sell_if: string, buy_below: string, review_on: string}
     */
    public array $thesis = ['stock_type' => '', 'thesis' => '', 'moat' => '', 'risks' => '', 'sell_if' => '', 'buy_below' => '', 'review_on' => ''];

    public function mount(string $symbol): void
    {
        $this->symbol = strtoupper(mb_substr($symbol, 0, 32));
        app(MarketData::class)->quote($this->symbol) ?? abort(404);
    }

    public function toggleWatchlist(): void
    {
        $item = $this->watchlist()->where('symbol', $this->symbol)->first();

        if ($item) {
            $item->delete();
        } else {
            WatchlistItem::create(['user_id' => $this->currentUser()->id, 'symbol' => $this->symbol, 'name' => (string) \App\Models\Security::where('symbol', $this->symbol)->value('name')]);
        }
    }

    private function currentThesis(): ?InvestmentThesis
    {
        return InvestmentThesis::where('user_id', $this->currentUser()->id)->where('symbol', $this->symbol)->first();
    }

    public function editThesis(): void
    {
        $thesis = $this->currentThesis();
        $this->thesis = [
            'stock_type' => (string) $thesis?->stock_type,
            'thesis' => (string) $thesis?->thesis,
            'moat' => (string) $thesis?->moat,
            'risks' => (string) $thesis?->risks,
            'sell_if' => (string) $thesis?->sell_if,
            'buy_below' => $thesis?->buy_below !== null ? rtrim(rtrim(number_format($thesis->buy_below, 4, ',', ''), '0'), ',') : '',
            'review_on' => (string) ($thesis?->review_on?->toDateString() ?? today()->addYear()->toDateString()),
        ];
        $this->editingThesis = true;
    }

    /**
     * Saves "why I own this" – Peter Lynch's two-minute story – with buy and sell rules.
     */
    public function saveThesis(): void
    {
        $this->thesis['buy_below'] = str_replace(',', '.', trim($this->thesis['buy_below']));
        $data = $this->validate([
            'thesis.stock_type' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(InvestmentThesis::stockTypes()))],
            'thesis.thesis' => ['nullable', 'string', 'max:3000'],
            'thesis.moat' => ['nullable', 'string', 'max:2000'],
            'thesis.risks' => ['nullable', 'string', 'max:2000'],
            'thesis.sell_if' => ['nullable', 'string', 'max:2000'],
            'thesis.buy_below' => ['nullable', 'numeric', 'min:0'],
            'thesis.review_on' => ['nullable', 'date'],
        ], attributes: ['thesis.buy_below' => __('Buy below'), 'thesis.review_on' => __('Review on')])['thesis'];

        InvestmentThesis::updateOrCreate(['user_id' => $this->currentUser()->id, 'symbol' => $this->symbol], [
            'stock_type' => $data['stock_type'] ?: null,
            'thesis' => trim((string) $data['thesis']) ?: null,
            'moat' => trim((string) $data['moat']) ?: null,
            'risks' => trim((string) $data['risks']) ?: null,
            'sell_if' => trim((string) $data['sell_if']) ?: null,
            'buy_below' => $data['buy_below'] !== '' && $data['buy_below'] !== null ? (float) $data['buy_below'] : null,
            'review_on' => $data['review_on'] ?: null,
        ]);
        $this->editingThesis = false;
    }

    public function deleteThesis(): void
    {
        $this->currentThesis()?->delete();
        $this->editingThesis = false;
    }

    public function loadFundamentals(MarketData $market): void
    {
        $security = \App\Models\Security::where('symbol', $this->symbol)->first() ?? abort(404);

        if (! $market->fundamentals($security, refresh: true)) {
            $this->dispatch('toast', message: __('No key figures available for this security.'));
        }
    }

    public function with(MarketData $market, Valuation $valuation): array
    {
        $security = $market->quote($this->symbol);
        $from = match ($this->range) {
            '1m' => now()->subMonth(),
            '3m' => now()->subMonths(3),
            '5y' => now()->subYears(5),
            'max' => now()->subYears(30),
            default => now()->subYear(),
        };
        $prices = $market->history($this->symbol, $from);
        $portfolioIds = $this->portfolios()->pluck('id');
        $positions = PortfolioPosition::whereIn('portfolio_id', $portfolioIds)->where('symbol', $this->symbol)->with('portfolio')->get();

        return [
            'security' => $security,
            'price' => $security?->last_price ? $market->toEuro($security->last_price, $security->currency) : null,
            'chart' => $prices->map(fn ($price) => ['label' => $price->date->format('d.m.y'), 'value' => (float) $price->close])->values()->all(),
            'rows' => $valuation->rows($positions),
            'transactions' => PortfolioTransaction::whereIn('portfolio_id', $portfolioIds)->where('symbol', $this->symbol)->with('portfolio')->orderByDesc('executed_on')->limit(50)->get(),
            'watched' => $this->watchlist()->where('symbol', $this->symbol)->exists(),
            'news' => \App\Models\NewsArticle::where('symbol', $this->symbol)->orderByDesc('published_at')->limit(8)->get(),
            'recommendation' => \App\Models\PortfolioRecommendation::where('user_id', $this->currentUser()->id)->where('symbol', $this->symbol)->first(),
            'mentions' => PortfolioLink::mentions($this->currentUser(), (int) $this->householdId, $this->symbol, $security?->name),
            'checks' => KeyFigureCheck::assess($security->fundamentals ?? []),
            'savedThesis' => $this->currentThesis(),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $signed = fn ($value, $suffix = ' €') => ($value > 0 ? '+' : ($value < 0 ? '−' : '±')).number_format(abs((float) $value), 2, ',', '.').$suffix;
    $tone = fn ($value) => $value > 0 ? 'text-emerald-700 dark:text-emerald-400' : ($value < 0 ? 'text-orange-700 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400');
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 6, ',', '.'), '0'), ',');
    $change = $security?->changePercent();
    $typeLabels = [
        'buy' => __('Purchase'), 'sell' => __('Sale'), 'dividend' => __('Dividend'), 'split' => __('Split'),
        'fee' => __('Fee'), 'transfer_in' => __('Transfer in'), 'transfer_out' => __('Transfer out'),
    ];
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => ''])

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $security->name }}</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ $security->symbol }}
                            @if ($security->isin) · {{ __('ISIN') }} {{ $security->isin }} @endif
                            @if ($security->wkn) · {{ __('WKN') }} {{ $security->wkn }} @endif
                            @if ($security->exchange) · {{ $security->exchange }} @endif
                        </p>
                    </div>
                    <div class="text-end">
                        <p class="text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((float) $security->last_price, 2, ',', '.') }} {{ $security->currency }}</p>
                        <p class="text-sm tabular-nums {{ $tone($change ?? 0) }}">{{ $change !== null ? $signed($change, ' %') : '' }}@if ($price !== null && $security->currency !== 'EUR') <span class="text-gray-500 dark:text-gray-400">· ≈ {{ $euro($price) }}</span>@endif</p>
                        <p class="text-xs text-gray-400">{{ $security->price_updated_at?->diffForHumans() }}</p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('portfolio.transactions', ['new' => 1, 'symbol' => $security->symbol]) }}" wire:navigate class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">＋ {{ __('Transaction') }}</a>
                    <button type="button" wire:click="toggleWatchlist" class="rounded-lg px-3 py-1.5 text-sm ring-1 ring-gray-200 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-gray-700">{{ $watched ? '★ '.__('On the watchlist') : '☆ '.__('Add to watchlist') }}</button>
                    <button type="button" wire:click="createTask(@js($security->symbol))" class="rounded-lg px-3 py-1.5 text-sm ring-1 ring-gray-200 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-gray-700">✅ {{ __('Create task') }}</button>
                </div>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Price history') }} ({{ $security->currency }})</h3>
                    <div class="inline-flex rounded-lg bg-gray-100 p-0.5 text-xs dark:bg-gray-900">
                        @foreach (['1m' => __('1M'), '3m' => __('3M'), '1y' => __('1Y'), '5y' => __('5Y'), 'max' => __('Max')] as $key => $label)
                            <button type="button" wire:click="$set('range', '{{ $key }}')" @class(['rounded-md px-2.5 py-1', 'bg-white font-medium text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100' => $range === $key, 'text-gray-600 dark:text-gray-400' => $range !== $key])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <x-line-chart :points="$chart" :value-label="__('Closing price')" :currency="false" wire:key="security-chart-{{ $range }}" />
                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    @foreach ([
                        __('Previous close') => $security->previous_close ? number_format($security->previous_close, 2, ',', '.') : '—',
                        __('52-week high') => $security->week_52_high ? number_format($security->week_52_high, 2, ',', '.') : '—',
                        __('52-week low') => $security->week_52_low ? number_format($security->week_52_low, 2, ',', '.') : '—',
                        __('Type') => __(ucfirst($security->asset_type)),
                    ] as $label => $value)
                        <div class="rounded-xl bg-gray-50 px-3 py-2 dark:bg-gray-900/40">
                            <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            @if ($recommendation)
                <p class="rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100">🔎 {{ __('Buy candidate of your strategy (score :score): :reasons', ['score' => number_format($recommendation->score, 0), 'reasons' => implode(', ', array_slice($recommendation->reasons ?? [], 0, 3))]) }}</p>
            @endif

            @php
                $figures = $security->fundamentals ?? [];
                $percent = fn ($value) => $value !== null ? number_format($value * 100, 1, ',', '.').' %' : '—';
                $number = fn ($value, $decimals = 1) => $value !== null ? number_format($value, $decimals, ',', '.') : '—';
                $analyst = ['strong_buy' => __('Strong buy'), 'buy' => __('Buy'), 'hold' => __('Hold'), 'underperform' => __('Underperform'), 'sell' => __('Sell')];
            @endphp
            @unless (str_starts_with($security->symbol, '^') || str_contains($security->symbol, '='))
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Key figures') }}</h3>
                        <button type="button" wire:click="loadFundamentals" wire:loading.attr="disabled" class="text-xs text-indigo-600 hover:underline dark:text-indigo-300">↻ {{ $figures ? __('Update') : __('Load key figures') }}</button>
                    </div>
                    @if ($figures)
                        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            @foreach ([
                                [__('Market cap'), $security->market_cap ? number_format($security->market_cap / 1e9, 1, ',', '.').' '.__('bn') : '—'],
                                [__('P/E ratio'), $number($figures['pe_ratio'] ?? null)],
                                [__('Forward P/E'), $number($figures['forward_pe'] ?? null)],
                                [__('Dividend yield'), $percent($figures['dividend_yield'] ?? null)],
                                [__('Debt/equity'), $number($figures['debt_to_equity'] ?? null, 2)],
                                [__('Revenue growth'), $percent($figures['revenue_growth'] ?? null)],
                                [__('Operating margin'), $percent($figures['operating_margins'] ?? null)],
                                [__('Analysts'), ($analyst[$figures['recommendation'] ?? ''] ?? '—').(isset($figures['analyst_target']) ? ' · '.__('target :price', ['price' => $number($figures['analyst_target'], 2)]) : '')],
                            ] as [$label, $value])
                                <div class="rounded-xl bg-gray-50 px-3 py-2 dark:bg-gray-900/40"><dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                        @if ($figures['description'] ?? null)
                            <details class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                                <summary class="cursor-pointer text-gray-900 dark:text-gray-100">{{ $security->sector }}{{ ($figures['industry'] ?? null) ? ' · '.$figures['industry'] : '' }}{{ $security->country ? ' · '.$security->country : '' }}</summary>
                                <p class="mt-2">{{ $figures['description'] }}</p>
                            </details>
                        @endif
                        @if ($checks !== [])
                            @php($score = \App\Support\Portfolio\KeyFigureCheck::score($checks))
                            <details class="mt-3 rounded-xl bg-gray-50 p-3 text-sm dark:bg-gray-900/40">
                                <summary class="cursor-pointer font-medium text-gray-900 dark:text-gray-100">🔎 {{ __('What the key figures say') }} <span class="font-normal text-gray-500 dark:text-gray-400">({{ __(':green of :rated look good', $score) }})</span></summary>
                                <ul class="mt-2 space-y-1.5">
                                    @foreach ($checks as $check)
                                        <li wire:key="check-{{ $check['key'] }}" class="flex gap-2">
                                            <span aria-hidden="true">{{ ['green' => '🟢', 'yellow' => '🟡', 'red' => '🔴', 'neutral' => '⚪'][$check['level']] }}</span>
                                            <span><strong>{{ $check['label'] }} {{ $check['value'] }}:</strong> {{ $check['note'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Rules of thumb only – compare within the industry. Explanations in the knowledge area under “Understanding key figures”.') }} <a href="{{ route('knowledge.index', ['category' => 'metrics']) }}" wire:navigate class="underline">{{ __('To the explanations') }} →</a></p>
                            </details>
                        @endif
                    @else
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Key figures are loaded with the daily market scan.') }}</p>
                    @endif
                </section>
            @endunless

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="thesis-heading">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 id="thesis-heading" class="font-semibold text-gray-900 dark:text-gray-100">📝 {{ __('My investment thesis') }}</h3>
                    @unless ($editingThesis)
                        <button type="button" wire:click="editThesis" class="text-sm text-indigo-600 underline dark:text-indigo-400">{{ $savedThesis ? __('Edit') : '+ '.__('Write down') }}</button>
                    @endunless
                </div>
                @if ($editingThesis)
                    @php($field = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300')
                    <form wire:submit="saveThesis" class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Why do I own it? (in two minutes, as Peter Lynch says)') }}<textarea wire:model="thesis.thesis" rows="3" class="{{ $field }}"></textarea></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Competitive advantage (moat)') }}<textarea wire:model="thesis.moat" rows="2" class="{{ $field }}" placeholder="{{ __('e.g. brand, network effects, switching costs, cost advantage') }}"></textarea></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Risks') }}<textarea wire:model="thesis.risks" rows="2" class="{{ $field }}"></textarea></label>
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('I sell if …') }}<textarea wire:model="thesis.sell_if" rows="2" class="{{ $field }}" placeholder="{{ __('e.g. growth below 5 % for two years, the CEO leaves, the dividend is cut') }}"></textarea></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Stock type') }}
                            <select wire:model="thesis.stock_type" class="{{ $field }}">
                                <option value="">–</option>
                                @foreach (\App\Models\InvestmentThesis::stockTypes() as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Buy below (price in :currency)', ['currency' => $security->currency ?? 'EUR']) }}<input type="text" inputmode="decimal" wire:model="thesis.buy_below" class="{{ $field }}"><x-input-error :messages="$errors->get('thesis.buy_below')" /></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Review on') }}<input type="date" wire:model="thesis.review_on" class="{{ $field }}"></label>
                        <div class="flex flex-wrap gap-2 sm:col-span-2">
                            <x-primary-button>{{ __('Save') }}</x-primary-button>
                            <x-secondary-button type="button" wire:click="$set('editingThesis', false)">{{ __('Cancel') }}</x-secondary-button>
                            @if ($savedThesis)
                                <button type="button" wire:click="deleteThesis" wire:confirm="{{ __('Delete this thesis?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                            @endif
                        </div>
                    </form>
                @elseif ($savedThesis)
                    <dl class="mt-3 space-y-2 text-sm">
                        @if ($savedThesis->isDueForReview())
                            <p class="rounded-lg bg-amber-50 px-3 py-2 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">⏰ {{ __('Time to check your thesis again: does it still hold?') }}</p>
                        @endif
                        @if ($savedThesis->buy_below && $security->last_price && $security->last_price <= $savedThesis->buy_below)
                            <p class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">🎯 {{ __('The price is below your buy limit of :price.', ['price' => number_format($savedThesis->buy_below, 2, ',', '.')]) }}</p>
                        @endif
                        @foreach ([[__('Why I own it'), $savedThesis->thesis], [__('Competitive advantage (moat)'), $savedThesis->moat], [__('Risks'), $savedThesis->risks], [__('I sell if …'), $savedThesis->sell_if]] as [$label, $text])
                            @if ($text)
                                <div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $label }}</dt><dd class="whitespace-pre-line text-gray-800 dark:text-gray-200">{{ $text }}</dd></div>
                            @endif
                        @endforeach
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            @if ($savedThesis->stock_type) {{ \App\Models\InvestmentThesis::stockTypes()[$savedThesis->stock_type] ?? '' }} · @endif
                            @if ($savedThesis->buy_below) {{ __('Buy below :price', ['price' => number_format($savedThesis->buy_below, 2, ',', '.')]) }} · @endif
                            @if ($savedThesis->review_on) {{ __('Review on :date', ['date' => $savedThesis->review_on->format('d.m.Y')]) }} @endif
                        </p>
                    </dl>
                @else
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Write down why you own this security, what could go wrong and when you would sell – it protects against rash decisions.') }}</p>
                @endif
            </section>

            @if ($rows !== [])
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Your position') }}</h3>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach ($rows as $row)
                            <div class="rounded-xl bg-gray-50 p-3 text-sm dark:bg-gray-900/40" wire:key="holding-{{ $row['position']->id }}">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $row['position']->portfolio->name }}</p>
                                <p class="text-gray-600 dark:text-gray-300">{{ $quantity($row['position']->quantity) }} × Ø {{ $euro($row['position']->avg_buy_price) }}</p>
                                <p class="mt-1 flex justify-between"><span>{{ __('Value') }}</span><span class="tabular-nums">{{ $euro($row['value']) }}</span></p>
                                <p class="flex justify-between {{ $tone($row['gain']) }}"><span>{{ __('Gain / loss') }}</span><span class="tabular-nums">{{ $signed($row['gain']) }}{{ $row['gain_percent'] !== null ? ' ('.$signed($row['gain_percent'], ' %').')' : '' }}</span></p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($transactions->isNotEmpty())
                <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                    <h3 class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">{{ __('Transactions') }}</h3>
                    <ul class="divide-y divide-gray-100 border-t border-gray-100 dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($transactions as $transaction)
                            <li class="flex items-center gap-3 px-4 py-2 text-sm" wire:key="security-tx-{{ $transaction->id }}">
                                <span class="w-20 text-xs tabular-nums text-gray-500">{{ $transaction->executed_on->format('d.m.Y') }}</span>
                                <span class="w-24">{{ $typeLabels[$transaction->type] ?? $transaction->type }}</span>
                                <span class="flex-1 text-gray-600 dark:text-gray-300">{{ $transaction->portfolio->name }}</span>
                                <span class="tabular-nums">{{ $transaction->quantity > 0 ? $quantity($transaction->quantity).' × ' : '' }}{{ number_format($transaction->price, 2, ',', '.') }} {{ $transaction->currency }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($mentions !== [])
                <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                    <h3 class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">{{ __('Mentioned in') }}</h3>
                    <ul class="divide-y divide-gray-100 border-t border-gray-100 text-sm dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($mentions as $node)
                            <li wire:key="security-mention-{{ $node['id'] }}"><a href="{{ $node['url'] }}" wire:navigate class="flex items-center gap-2 px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-700/40"><span aria-hidden="true">{{ $node['icon'] }}</span><span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ $node['title'] }}</span><span class="text-xs text-gray-500 dark:text-gray-400">{{ $node['kind'] }}@if ($node['private']) · 🔒@endif</span></a></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($news->isNotEmpty())
                <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                    <h3 class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">{{ __('News') }}</h3>
                    <ul class="divide-y divide-gray-100 border-t border-gray-100 text-sm dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($news as $article)
                            <li wire:key="security-news-{{ $article->id }}"><a href="{{ $article->url }}" target="_blank" rel="noopener noreferrer" class="block px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-700/40"><span class="text-gray-900 dark:text-gray-100">{{ $article->title }}</span> <span class="text-xs text-gray-500 dark:text-gray-400">· {{ $article->source }} · {{ $article->published_at?->diffForHumans() }}</span></a></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
