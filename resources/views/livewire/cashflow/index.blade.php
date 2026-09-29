<?php

use App\Support\Addons;
use App\Support\Finance\CashflowStatement;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Income statement and balance sheet after "Rich Dad Poor Dad": where the money comes from,
 * where it goes, which assets pay you and how far away financial freedom is.
 */
new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user->canUseAddon(Addons::FINANCE) || $user->canUseAddon(Addons::PORTFOLIO) || $user->canUseAddon(Addons::REAL_ESTATE), 403);
    }

    public function with(): array
    {
        return ['statement' => CashflowStatement::for(Auth::user()), 'history' => CashflowStatement::history(Auth::user())];
    }
}; ?>

@php
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
    $round = fn (float $value) => number_format($value, 0, ',', '.').' €';
    $freedomPercent = (int) round(min(1, $statement['freedom']) * 100);
    $patterns = [
        'poor' => ['icon' => '🔄', 'title' => __('Pattern “poor”: income goes straight into expenses'), 'text' => __('Salary comes in and is spent – nothing is left to buy assets. The first step is a surplus you invest every month.')],
        'middle' => ['icon' => '🏠', 'title' => __('Pattern “middle class”: income flows into liabilities'), 'text' => __('A large part of the money services loans for things that do not pay you. Assets that bring in money are still small compared with the debts.')],
        'building' => ['icon' => '🌱', 'title' => __('On the way: your assets already pay you'), 'text' => __('You own assets that generate income. Keep buying assets with every surplus until their income covers your expenses.')],
        'rich' => ['icon' => '🏆', 'title' => __('Pattern “rich”: your assets cover your expenses'), 'text' => __('The income from your assets pays for your living costs – work has become optional. Congratulations!')],
    ];
    $pattern = $patterns[$statement['pattern']];
    $kindLabels = ['earned' => __('earned'), 'portfolio' => __('portfolio'), 'passive' => __('passive'), 'other' => __('other')];
    $steps = [];

    if ($statement['months'] < 3 || $statement['expenses']['total'] <= 0) {
        $steps[] = ['icon' => '🏦', 'text' => __('Import at least three months of bank transactions so income and expenses are reliable.'), 'url' => route('finance.index')];
    }
    if ($statement['cashflow'] > 0) {
        $steps[] = ['icon' => '💰', 'text' => __('Pay yourself first: move your surplus of :amount per month into assets right at the start of the month.', ['amount' => $round($statement['cashflow'])]), 'url' => route('knowledge.index', ['category' => 'wealth'])];
    } elseif ($statement['income']['total'] > 0) {
        $steps[] = ['icon' => '✂️', 'text' => __('You spend more than you earn. Look at the biggest expense categories and cancel what you do not need.'), 'url' => route('contracts.index')];
    }
    if ($statement['income']['portfolio'] <= 0) {
        $steps[] = ['icon' => '📈', 'text' => __('No dividends yet: a broadly diversified ETF savings plan is the simplest start for portfolio income.'), 'url' => route('knowledge.index', ['category' => 'stocks'])];
    }
    if ($statement['doodads']['total'] > 0) {
        $steps[] = ['icon' => '🏡', 'text' => __('Kiyosaki counts the home you live in as a liability: it costs you money every month. Worth having – but it does not make you free.'), 'url' => null];
    }
    if ($statement['liabilities']['total'] > $statement['assets']['total'] && $statement['liabilities']['total'] > 0) {
        $steps[] = ['icon' => '📉', 'text' => __('Your debts exceed your assets. Repay expensive consumer loans first, then build assets.'), 'url' => route('knowledge.index', ['category' => 'financing'])];
    }
    if ($statement['gap'] > 0) {
        $steps[] = ['icon' => '🎯', 'text' => __('At a 4 % yield, about :capital in assets would close the remaining gap of :gap per month.', ['capital' => $round($statement['gap'] * 12 / 0.04), 'gap' => $round($statement['gap'])]), 'url' => null];
    }
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🧭 {{ __('Cashflow analysis') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Income statement and balance sheet after “Rich Dad Poor Dad” by Robert Kiyosaki: assets put money in your pocket, liabilities take money out. Monthly averages from :from to :to.', ['from' => $statement['from']->format('m/Y'), 'to' => $statement['to']->format('m/Y')]) }}</p>

            @unless ($statement['has_data'])
                <section class="rounded-2xl bg-white p-6 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-gray-700 dark:text-gray-200">{{ __('There is not enough data for an analysis yet.') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Import bank transactions in Finances, add your portfolio or properties – the analysis builds itself from them.') }}</p>
                </section>
            @endunless

            {{-- Headline: financial freedom --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Financial freedom') }}</p>
                        <p class="mt-1 text-4xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $freedomPercent }} %</p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __(':income asset income covers :expenses expenses per month', ['income' => $round($statement['asset_income']), 'expenses' => $round($statement['expenses']['total'])]) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Monthly cashflow') }}</p>
                        <p @class(['mt-1 text-2xl font-semibold tabular-nums', 'text-emerald-700 dark:text-emerald-400' => $statement['cashflow'] >= 0, 'text-rose-700 dark:text-rose-400' => $statement['cashflow'] < 0])>{{ $statement['cashflow'] >= 0 ? '+' : '' }}{{ $euro($statement['cashflow']) }}</p>
                    </div>
                </div>
                <div class="mt-4 h-3 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $freedomPercent }}" aria-label="{{ __('Financial freedom') }}">
                    <div class="h-full rounded-full bg-emerald-600 dark:bg-emerald-500" style="width: {{ $freedomPercent }}%"></div>
                </div>
                @if ($statement['gap'] > 0)
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Still missing: :gap asset income per month.', ['gap' => $round($statement['gap'])]) }}</p>
                @endif
            </section>

            @if (count($history) >= 2)
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">📈 {{ __('Development') }}</h3>
                    <div class="mt-3 grid gap-5 md:grid-cols-2">
                        <div>
                            <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Financial freedom per month') }}</p>
                            <x-line-chart :points="collect($history)->map(fn ($row) => ['label' => $row['label'], 'value' => $row['freedom']])->all()" :currency="false" suffix=" %" :value-label="__('Financial freedom')" height="170" />
                        </div>
                        <div>
                            <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Cashflow per month') }}</p>
                            <x-line-chart :points="collect($history)->map(fn ($row) => ['label' => $row['label'], 'value' => $row['cashflow']])->all()" :value-label="__('Cashflow')" height="170" />
                        </div>
                    </div>
                </section>
            @endif

            {{-- Pattern --}}
            <section class="rounded-2xl bg-indigo-50 p-5 ring-1 ring-indigo-100 dark:bg-indigo-900/20 dark:ring-indigo-800">
                <h3 class="font-semibold text-indigo-950 dark:text-indigo-100">{{ $pattern['icon'] }} {{ $pattern['title'] }}</h3>
                <p class="mt-1 text-sm text-indigo-900 dark:text-indigo-200">{{ $pattern['text'] }}</p>
                <div class="mt-4 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4" aria-hidden="true">
                    @foreach (['poor' => __('Salary → expenses'), 'middle' => __('Salary → liabilities → expenses'), 'building' => __('Salary → assets → income'), 'rich' => __('Assets → income → expenses')] as $key => $flow)
                        <div @class(['rounded-lg px-2 py-2 text-center', 'bg-indigo-600 font-semibold text-white' => $statement['pattern'] === $key, 'bg-white/70 text-indigo-900 dark:bg-gray-800/60 dark:text-indigo-200' => $statement['pattern'] !== $key])>{{ $patterns[$key]['icon'] }} {{ $flow }}</div>
                    @endforeach
                </div>
            </section>

            {{-- Income statement --}}
            <section class="rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                <h3 class="border-b border-gray-100 px-5 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">📄 {{ __('Income statement') }} <span class="text-sm font-normal text-gray-500 dark:text-gray-400">· {{ __('per month') }}</span></h3>
                <div class="grid gap-0 md:grid-cols-2 md:divide-x md:divide-gray-100 dark:md:divide-gray-700">
                    <div class="p-5">
                        <div class="flex items-baseline justify-between">
                            <h4 class="text-sm font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">{{ __('Income (statement)') }}</h4>
                            <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($statement['income']['total']) }}</span>
                        </div>
                        <ul class="mt-3 space-y-2 text-sm">
                            @forelse ($statement['income']['rows'] as $row)
                                <li class="flex items-center justify-between gap-3">
                                    <span class="text-gray-700 dark:text-gray-200">{{ $row['label'] }} <span @class(['ml-1 rounded-full px-1.5 py-0.5 text-[11px]', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => in_array($row['kind'], ['portfolio', 'passive'], true), 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => ! in_array($row['kind'], ['portfolio', 'passive'], true)])>{{ $kindLabels[$row['kind']] }}</span></span>
                                    <span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($row['value']) }}</span>
                                </li>
                            @empty
                                <li class="text-gray-500 dark:text-gray-400">{{ __('No income found yet.') }}</li>
                            @endforelse
                        </ul>
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Portfolio and passive income come from assets – that is the income that makes you free.') }}</p>
                    </div>
                    <div class="border-t border-gray-100 p-5 md:border-t-0 dark:border-gray-700">
                        <div class="flex items-baseline justify-between">
                            <h4 class="text-sm font-semibold uppercase tracking-wide text-rose-700 dark:text-rose-400">{{ __('Expenses') }}</h4>
                            <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($statement['expenses']['total']) }}</span>
                        </div>
                        <ul class="mt-3 space-y-2 text-sm">
                            @forelse (array_slice($statement['expenses']['rows'], 0, 8) as $row)
                                <li class="flex items-center justify-between gap-3">
                                    <span class="text-gray-700 dark:text-gray-200">{{ $row['icon'] }} {{ $row['label'] }}</span>
                                    <span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($row['value']) }}</span>
                                </li>
                            @empty
                                <li class="text-gray-500 dark:text-gray-400">{{ __('No expenses found yet.') }}</li>
                            @endforelse
                            @if (count($statement['expenses']['rows']) > 8)
                                <li class="flex items-center justify-between gap-3 text-gray-500 dark:text-gray-400">
                                    <span>{{ __('Other categories') }}</span>
                                    <span class="tabular-nums">{{ $euro(collect(array_slice($statement['expenses']['rows'], 8))->sum('value')) }}</span>
                                </li>
                            @endif
                        </ul>
                    </div>
                </div>
            </section>

            {{-- Balance sheet --}}
            <section class="rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                <h3 class="border-b border-gray-100 px-5 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">⚖️ {{ __('Balance sheet') }}</h3>
                <div class="grid gap-0 md:grid-cols-2 md:divide-x md:divide-gray-100 dark:md:divide-gray-700">
                    @foreach (['assets' => [__('Assets (balance sheet)'), __('Put money in your pocket'), 'text-emerald-700 dark:text-emerald-400'], 'liabilities' => [__('Liabilities (balance sheet)'), __('Take money out of your pocket'), 'text-rose-700 dark:text-rose-400']] as $side => [$title, $subtitle, $color])
                        <div @class(['p-5', 'border-t border-gray-100 md:border-t-0 dark:border-gray-700' => $side === 'liabilities'])>
                            <div class="flex items-baseline justify-between">
                                <h4 class="text-sm font-semibold uppercase tracking-wide {{ $color }}">{{ $title }}</h4>
                                <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($statement[$side]['total']) }}</span>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
                            <ul class="mt-3 space-y-2 text-sm">
                                @forelse ($statement[$side]['rows'] as $row)
                                    <li class="flex items-center justify-between gap-3">
                                        @if ($row['url'])
                                            <a href="{{ $row['url'] }}" wire:navigate class="text-gray-700 hover:underline dark:text-gray-200">{{ $row['label'] }}</a>
                                        @else
                                            <span class="text-gray-700 dark:text-gray-200">{{ $row['label'] }}</span>
                                        @endif
                                        <span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($row['value']) }}</span>
                                    </li>
                                @empty
                                    <li class="text-gray-500 dark:text-gray-400">{{ __('Nothing entered yet.') }}</li>
                                @endforelse
                            </ul>
                        </div>
                    @endforeach
                </div>
                @if ($statement['doodads']['rows'] !== [])
                    <div class="border-t border-gray-100 p-5 dark:border-gray-700">
                        <div class="flex items-baseline justify-between">
                            <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('Owner-occupied (liability per Kiyosaki)') }}</h4>
                            <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($statement['doodads']['total']) }}</span>
                        </div>
                        <ul class="mt-3 space-y-2 text-sm">
                            @foreach ($statement['doodads']['rows'] as $row)
                                <li class="flex items-center justify-between gap-3">
                                    <a href="{{ $row['url'] }}" wire:navigate class="text-gray-700 hover:underline dark:text-gray-200">{{ $row['label'] }}</a>
                                    <span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($row['value']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Kept apart from the assets: it has a value, but it costs money every month instead of bringing some in.') }}</p>
                    </div>
                @endif
            </section>

            @if ($steps !== [])
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">👣 {{ __('Next steps') }}</h3>
                    <ul class="mt-3 space-y-3 text-sm">
                        @foreach ($steps as $step)
                            <li class="flex gap-3">
                                <span aria-hidden="true">{{ $step['icon'] }}</span>
                                <span class="text-gray-700 dark:text-gray-200">{{ $step['text'] }}@if ($step['url']) <a href="{{ $step['url'] }}" wire:navigate class="whitespace-nowrap font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('More') }} →</a>@endif</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Rent counts as net cold rent of the current tenants, dividends and business profit as monthly average; rent payments on the bank account are not counted twice. Transfers between own accounts and saving/investing are not expenses.') }}</p>
        </div>
    </div>
</div>
