{{-- Finance report tab (variables from finance/index: $report, $euro, $frequencies, $aiAvailable, $advice, $openCount). --}}
@php
    $maxMonth = max(1, collect($report['monthly'])->flatMap(fn ($month) => [$month['income'], $month['expenses']])->max());
    $tickStep = $maxMonth > 0 ? pow(10, floor(log10($maxMonth))) : 1;
    $tickStep = $maxMonth / $tickStep > 5 ? $tickStep * 2 : ($maxMonth / $tickStep < 2 ? $tickStep / 2 : $tickStep);
    $axisMax = max($tickStep, ceil($maxMonth / $tickStep) * $tickStep);
    $ticks = range(0, $axisMax, $tickStep);
    $maxCategory = max(1, collect($report['categories'])->max('amount') ?? 1);
@endphp

<style>
    .fin-root { --fin-income: #2a78d6; --fin-expense: #eb6834; --fin-savings: #1baf7a; --fin-grid: #e7e5e4; }
    @media (prefers-color-scheme: dark) { :root:where(:not([data-theme="light"])) .fin-root { --fin-income: #3987e5; --fin-expense: #d95926; --fin-savings: #199e70; --fin-grid: #374151; } }
    :root[data-theme="dark"] .fin-root { --fin-income: #3987e5; --fin-expense: #d95926; --fin-savings: #199e70; --fin-grid: #374151; }
</style>

<div class="fin-root space-y-5">
    @if ($report['count'] === 0)
        <p class="rounded-2xl bg-white p-6 text-sm text-gray-600 shadow-xs dark:bg-gray-800 dark:text-gray-300">{{ __('No bookings in this period.') }}</p>
    @else
        {{-- Headline figures --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                [__('Income'), $euro($report['income'], 0), __('Ø :amount per month', ['amount' => $euro($report['avgIncome'], 0)]), 'var(--fin-income)'],
                [__('Expenses'), $euro($report['expenses'], 0), __('Ø :amount per month', ['amount' => $euro($report['avgExpenses'], 0)]), 'var(--fin-expense)'],
                [__('Left over'), $euro($report['balance'], 0), $report['savingsTransfers'] > 0 ? __(':amount of it moved to savings', ['amount' => $euro($report['savingsTransfers'], 0)]) : __('Income minus expenses'), null],
                [__('Savings rate'), $report['savingsRate'] !== null ? number_format($report['savingsRate'], 1, ',', '.').' %' : '—', __('Guideline: at least 10–20 %'), null],
            ] as [$label, $value, $hint, $color])
                <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <p class="flex items-center gap-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">
                        @if ($color)<span class="h-2.5 w-2.5 rounded-full" style="background: {{ $color }}" aria-hidden="true"></span>@endif
                        {{ $label }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        {{-- Active category drill-down --}}
        @if ($categoryFilter !== '' && isset($categories[$categoryFilter]))
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-100 px-3 py-1 font-medium text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200">
                    {{ $categories[$categoryFilter]['icon'] }} {{ __('Only :category', ['category' => $categories[$categoryFilter]['label']]) }}
                    <button type="button" wire:click="$set('categoryFilter', '')" class="text-indigo-500 hover:text-indigo-700 dark:text-indigo-300" aria-label="{{ __('Remove filter') }}">✕</button>
                </span>
            </div>
        @endif

        {{-- Comparison with the previous, equally long period --}}
        @if (! empty($deltas) && $comparison)
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl bg-white px-4 py-2.5 text-xs shadow-xs dark:bg-gray-800">
                <span class="text-gray-400">{{ __('Compared to :from – :to', ['from' => $comparison['from']->isoFormat('DD.MM.YY'), 'to' => $comparison['to']->isoFormat('DD.MM.YY')]) }}:</span>
                @foreach ($deltas as $d)
                    <span class="font-medium {{ $d['delta'] == 0 ? 'text-gray-400' : (($d['delta'] > 0) === $d['higherBetter'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') }}">
                        {{ $d['label'] }} {{ $d['delta'] > 0 ? '▲' : ($d['delta'] < 0 ? '▼' : '=') }} {{ $euro(abs($d['delta']), 0) }}
                    </span>
                @endforeach
            </div>
        @endif

        @if ($openCount > 0)
            <p class="flex flex-wrap items-center gap-2 rounded-xl bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">
                {{ trans_choice(':count booking has no clear category yet.|:count bookings have no clear category yet.', $openCount) }}
                <button type="button" wire:click="$set('tab', 'transactions'); $set('categoryFilter', 'other')" class="underline">{{ __('Sort them') }}</button>
                @if ($aiAvailable)
                    <span aria-hidden="true">·</span>
                    <button type="button" wire:click="categorizeWithAi" wire:loading.attr="disabled" wire:target="categorizeWithAi" class="font-medium underline">
                        <span wire:loading.remove wire:target="categorizeWithAi">✨ {{ __('Let the AI categorize them') }}</span>
                        <span wire:loading wire:target="categorizeWithAi">{{ __('Categorizing …') }}</span>
                    </button>
                @endif
            </p>
        @endif

        {{-- Review: internal transfers & duplicates (detection; the owner confirms each) --}}
        @if (count($reviewTransfers) || count($reviewDuplicates))
            <div class="space-y-3 rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4 dark:border-indigo-900/50 dark:bg-indigo-900/20">
                <p class="text-sm font-medium text-indigo-900 dark:text-indigo-100">🔎 {{ __('Please check: these bookings may be inflating your totals.') }}</p>

                @foreach ($reviewTransfers as $pair)
                    <div class="flex flex-wrap items-center gap-2 rounded-xl bg-white px-3 py-2 text-sm shadow-xs dark:bg-gray-800" wire:key="tr-{{ $pair['out']->id }}-{{ $pair['in']->id }}">
                        <span class="text-base" aria-hidden="true">🔁</span>
                        <span class="flex-1">
                            <span class="font-medium">{{ __('Internal transfer?') }}</span>
                            {{ $euro(abs($pair['out']->amount), 2) }} ·
                            {{ $pair['out']->account?->name ?? __('Account') }} → {{ $pair['in']->account?->name ?? __('Account') }} ·
                            {{ $pair['out']->booked_on->isoFormat('DD.MM.YY') }}
                        </span>
                        <button type="button" wire:click="confirmTransfer({{ $pair['out']->id }}, {{ $pair['in']->id }})" class="rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700">{{ __('Mark as transfer') }}</button>
                        <button type="button" wire:click="ignoreTransfer({{ $pair['out']->id }}, {{ $pair['in']->id }})" class="rounded-lg px-2.5 py-1 text-xs text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700">{{ __('Keep') }}</button>
                    </div>
                @endforeach

                @foreach ($reviewDuplicates as $pair)
                    <div class="flex flex-wrap items-center gap-2 rounded-xl bg-white px-3 py-2 text-sm shadow-xs dark:bg-gray-800" wire:key="dup-{{ $pair['keep']->id }}-{{ $pair['duplicate']->id }}">
                        <span class="text-base" aria-hidden="true">👯</span>
                        <span class="flex-1">
                            <span class="font-medium">{{ __('Possible duplicate?') }}</span>
                            {{ $euro($pair['duplicate']->amount, 2) }} ·
                            {{ $pair['duplicate']->counterparty ?: ($pair['duplicate']->purpose ? \Illuminate\Support\Str::limit($pair['duplicate']->purpose, 40) : __('Booking')) }} ·
                            {{ $pair['keep']->booked_on->isoFormat('DD.MM.YY') }} + {{ $pair['duplicate']->booked_on->isoFormat('DD.MM.YY') }}
                        </span>
                        <button type="button" wire:click="confirmDuplicate({{ $pair['keep']->id }}, {{ $pair['duplicate']->id }})" class="rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700">{{ __('Remove duplicate') }}</button>
                        <button type="button" wire:click="ignoreDuplicate({{ $pair['keep']->id }}, {{ $pair['duplicate']->id }})" class="rounded-lg px-2.5 py-1 text-xs text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700">{{ __('Keep') }}</button>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="grid gap-5 lg:grid-cols-5">
            {{-- Month by month --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800 lg:col-span-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Income and expenses per month') }}</h3>
                    <div class="flex items-center gap-4 text-xs text-gray-600 dark:text-gray-300">
                        <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-xs" style="background: var(--fin-income)"></span>{{ __('Income') }}</span>
                        <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-xs" style="background: var(--fin-expense)"></span>{{ __('Expenses') }}</span>
                    </div>
                </div>
                <div class="mt-4 flex gap-2" x-data="{ hover: null }">
                    <div class="flex h-48 flex-col justify-between pb-5 text-end text-[10px] tabular-nums text-gray-400" aria-hidden="true">
                        @foreach (array_reverse($ticks) as $tick)
                            <span class="-translate-y-1/2 leading-none">{{ number_format($tick, 0, ',', '.') }}</span>
                        @endforeach
                    </div>
                    <div class="relative flex-1">
                        <div class="absolute inset-x-0 top-0 bottom-5 flex flex-col justify-between" aria-hidden="true">
                            @foreach ($ticks as $tick)
                                <div class="border-t" style="border-color: var(--fin-grid)"></div>
                            @endforeach
                        </div>
                        <div class="relative flex h-48 items-stretch justify-around gap-1">
                            @foreach ($report['monthly'] as $index => $month)
                                <div class="relative flex flex-1 flex-col items-center" x-on:mouseenter="hover = {{ $index }}" x-on:mouseleave="hover = null" x-on:focusin="hover = {{ $index }}" x-on:focusout="hover = null" tabindex="0" role="img" aria-label="{{ $month['label'] }}: {{ __('Income') }} {{ $euro($month['income']) }}, {{ __('Expenses') }} {{ $euro($month['expenses']) }}">
                                    <div class="flex flex-1 items-end gap-[2px] pb-0">
                                        <div class="w-3 rounded-t-sm sm:w-5" style="max-width: 24px; height: {{ round($month['income'] / $axisMax * 100, 2) }}%; background: var(--fin-income)"></div>
                                        <div class="w-3 rounded-t-sm sm:w-5" style="max-width: 24px; height: {{ round($month['expenses'] / $axisMax * 100, 2) }}%; background: var(--fin-expense)"></div>
                                    </div>
                                    <span class="h-5 pt-1 text-[10px] text-gray-500 dark:text-gray-400">{{ $month['label'] }}</span>
                                    <div x-show="hover === {{ $index }}" x-cloak class="pointer-events-none absolute bottom-full z-10 mb-1 w-40 rounded-lg bg-gray-900 px-3 py-2 text-xs text-white shadow-lg dark:bg-gray-100 dark:text-gray-900">
                                        <p class="font-semibold">{{ $month['label'] }}</p>
                                        <p class="flex justify-between gap-2"><span>{{ __('Income') }}</span><span class="tabular-nums">{{ $euro($month['income']) }}</span></p>
                                        <p class="flex justify-between gap-2"><span>{{ __('Expenses') }}</span><span class="tabular-nums">{{ $euro($month['expenses']) }}</span></p>
                                        <p class="flex justify-between gap-2 border-t border-white/20 pt-1 dark:border-gray-900/20"><span>{{ __('Balance') }}</span><span class="tabular-nums">{{ $euro($month['income'] - $month['expenses']) }}</span></p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <details class="mt-3 text-sm">
                    <summary class="cursor-pointer text-xs text-gray-500 dark:text-gray-400">{{ __('Show as table') }}</summary>
                    <table class="mt-2 w-full text-xs tabular-nums">
                        <thead class="text-gray-500"><tr><th class="text-start font-medium">{{ __('Month') }}</th><th class="text-end font-medium">{{ __('Income') }}</th><th class="text-end font-medium">{{ __('Expenses') }}</th><th class="text-end font-medium">{{ __('Balance') }}</th></tr></thead>
                        <tbody class="text-gray-700 dark:text-gray-300">
                            @foreach ($report['monthly'] as $month)
                                <tr><td>{{ $month['label'] }}</td><td class="text-end">{{ $euro($month['income']) }}</td><td class="text-end">{{ $euro($month['expenses']) }}</td><td class="text-end">{{ $euro($month['income'] - $month['expenses']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            </section>

            {{-- 50/30/20 --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800 lg:col-span-2">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('50/30/20 check') }}</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Share of your income: needs (guideline 50 %), wishes (30 %), saving (20 %).') }}</p>
                @if ($report['split']['needs'] !== null)
                    <ul class="mt-4 space-y-4">
                        @foreach ([['needs', __('Needs'), 50, 'var(--fin-income)'], ['wants', __('Wishes & lifestyle'), 30, 'var(--fin-expense)'], ['savings', __('Left for saving'), 20, 'var(--fin-savings)']] as [$key, $label, $target, $color])
                            @php $value = (float) $report['split'][$key]; @endphp
                            <li>
                                <div class="flex items-baseline justify-between text-sm">
                                    <span class="flex items-center gap-1.5 text-gray-700 dark:text-gray-200"><span class="h-2.5 w-2.5 rounded-xs" style="background: {{ $color }}"></span>{{ $label }}</span>
                                    <span class="tabular-nums text-gray-900 dark:text-gray-100"><strong>{{ number_format($value, 0, ',', '.') }} %</strong> <span class="text-xs text-gray-500">/ {{ $target }} %</span></span>
                                </div>
                                <div class="relative mt-1.5 h-2.5 rounded-full bg-gray-100 dark:bg-gray-700">
                                    <div class="h-2.5 rounded-full" style="width: {{ min(100, $value) }}%; background: {{ $color }}"></div>
                                    <div class="absolute -top-1 h-4.5 w-0.5 bg-indigo-600 dark:bg-indigo-500" style="left: {{ $target }}%; height: 18px" title="{{ __('Guideline :target %', ['target' => $target]) }}"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 text-sm text-gray-500">{{ __('No income in this period.') }}</p>
                @endif
            </section>
        </div>

        {{-- Savings potential --}}
        <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">💡 {{ __('Savings potential') }}</h3>
                @if ($report['potential'] > 0)
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('up to') }} <strong class="text-lg text-gray-900 dark:text-gray-100">{{ $euro($report['potential'], 0) }}</strong> {{ __('per year') }}</p>
                @endif
            </div>
            @if ($report['suggestions'] === [])
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No obvious savings found – nicely done. The AI analysis can look deeper.') }}</p>
            @else
                <ul class="mt-3 grid gap-3 md:grid-cols-2">
                    @foreach ($report['suggestions'] as $suggestion)
                        <li class="flex gap-3 rounded-xl bg-gray-50 p-4 dark:bg-gray-900/40">
                            <span class="text-2xl" aria-hidden="true">{{ $suggestion['icon'] }}</span>
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $suggestion['title'] }}</p>
                                <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ $suggestion['text'] }}</p>
                                @if ($suggestion['yearly'] > 0)
                                    <p class="mt-1.5 inline-block rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/40 dark:text-green-200">≈ {{ $euro($suggestion['yearly'], 0) }} {{ __('per year') }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- AI analyst --}}
        @if ($aiAvailable)
            <section class="rounded-2xl bg-gradient-to-br from-indigo-50 to-white p-5 shadow-xs ring-1 ring-indigo-100 dark:from-indigo-950/40 dark:to-gray-800 dark:ring-indigo-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">✨ {{ __('Your financial analyst') }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Only totals per category and recurring payments are sent to the Claude API – no account numbers, no single bookings.') }}</p>
                    </div>
                    <x-primary-button type="button" wire:click="askAnalyst" wire:loading.attr="disabled" wire:target="askAnalyst">
                        <span wire:loading.remove wire:target="askAnalyst">{{ $advice ? __('Analyse again') : __('Get an assessment') }}</span>
                        <span wire:loading wire:target="askAnalyst">{{ __('Analysing …') }}</span>
                    </x-primary-button>
                </div>
                @if ($aiError)
                    <p class="mt-3 text-sm text-red-700 dark:text-red-300" role="alert">{{ $aiError }}</p>
                @endif
                @if ($advice)
                    <div class="mt-4 space-y-4">
                        <p class="text-gray-800 dark:text-gray-100">{{ $advice['summary'] }}</p>
                        @if ($advice['strengths'] !== [])
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Going well') }}</p>
                                <ul class="mt-1 space-y-1 text-sm text-gray-700 dark:text-gray-200">
                                    @foreach ($advice['strengths'] as $strength)<li>✓ {{ $strength }}</li>@endforeach
                                </ul>
                            </div>
                        @endif
                        <ol class="space-y-2">
                            @foreach ($advice['recommendations'] as $recommendation)
                                <li class="rounded-xl bg-white p-3 shadow-xs dark:bg-gray-800">
                                    <p class="flex flex-wrap items-baseline justify-between gap-2 font-medium text-gray-900 dark:text-gray-100">
                                        <span>{{ $loop->iteration }}. {{ $recommendation['title'] }}</span>
                                        @if ($recommendation['yearly_saving'] > 0)
                                            <span class="text-xs font-medium text-green-700 dark:text-green-300">≈ {{ $euro($recommendation['yearly_saving'], 0) }} / {{ __('year') }}</span>
                                        @endif
                                    </p>
                                    <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ $recommendation['text'] }}</p>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endif
            </section>
        @endif

        <div class="grid gap-5 lg:grid-cols-2">
            {{-- Categories --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Expenses by category') }}</h3>
                <ul class="mt-4 space-y-2.5">
                    @foreach ($report['categories'] as $category)
                        <li>
                            <button type="button" wire:click="$set('categoryFilter', '{{ $category['key'] }}'); $set('tab', 'transactions')" class="block w-full text-start" title="{{ __(':count bookings', ['count' => $category['count']]) }}">
                                <div class="flex items-baseline justify-between gap-2 text-sm">
                                    <span class="truncate text-gray-700 dark:text-gray-200">{{ $category['icon'] }} {{ $category['label'] }}</span>
                                    <span class="shrink-0 tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($category['amount'], 0) }} <span class="text-xs text-gray-500">· {{ number_format($category['share'], 0, ',', '.') }} %</span></span>
                                </div>
                                <div class="mt-1 h-2 rounded-full bg-gray-100 dark:bg-gray-700">
                                    <div class="h-2 rounded-full" style="width: {{ round($category['amount'] / $maxCategory * 100, 2) }}%; background: var(--fin-expense)"></div>
                                </div>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>

            <div class="space-y-5">
                {{-- Recurring --}}
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">🔁 {{ __('Recurring payments') }}</h3>
                        @if ($report['recurring'] !== [])
                            <span class="text-sm text-gray-600 dark:text-gray-300"><strong class="text-gray-900 dark:text-gray-100">{{ $euro($report['recurringMonthly']) }}</strong> / {{ __('month') }}</span>
                        @endif
                    </div>
                    <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                        @forelse ($report['recurring'] as $row)
                            <li class="flex items-center gap-3 py-2">
                                <span aria-hidden="true">{{ \App\Support\Finance\FinanceCategories::icon($row['category']) }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-gray-800 dark:text-gray-100">{{ $row['name'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $frequencies[$row['frequency']] ?? $row['frequency'] }} · {{ __('last :date', ['date' => $row['lastDate']->translatedFormat('j. M')]) }}@if ($row['increase']) · <span class="font-medium text-red-600 dark:text-red-400">+{{ $euro($row['increase']) }}</span>@endif</p>
                                </div>
                                <span class="shrink-0 text-end tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($row['amount']) }}<br><span class="text-xs text-gray-500">{{ $euro($row['yearly'], 0) }} / {{ __('year') }}</span></span>
                            </li>
                        @empty
                            <li class="py-2 text-gray-500 dark:text-gray-400">{{ __('No recurring payments found yet – they show up after two months of data.') }}</li>
                        @endforelse
                    </ul>
                </section>

                {{-- Top payees --}}
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Where most of the money goes') }}</h3>
                    <ol class="mt-3 space-y-1.5 text-sm">
                        @foreach ($report['payees'] as $payee)
                            <li class="flex items-center justify-between gap-3">
                                <span class="truncate text-gray-700 dark:text-gray-200">{{ $loop->iteration }}. {{ $payee['name'] }} <span class="text-xs text-gray-400">({{ $payee['count'] }}×)</span></span>
                                <span class="shrink-0 tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($payee['amount'], 0) }}</span>
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>
        </div>

        {{-- Expenses per account (only with more than one account) --}}
        @if (count($report['byAccount']) > 1)
            @php $maxAccount = max(1, collect($report['byAccount'])->max('amount') ?? 1); @endphp
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Expenses by account') }}</h3>
                <ul class="mt-4 space-y-2.5">
                    @foreach ($report['byAccount'] as $row)
                        <li>
                            <div class="flex items-baseline justify-between gap-2 text-sm">
                                <span class="truncate text-gray-700 dark:text-gray-200">🏦 {{ $row['label'] }} <span class="text-xs text-gray-400">({{ $row['count'] }}×)</span></span>
                                <span class="shrink-0 tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($row['amount'], 0) }} <span class="text-xs text-gray-500">· {{ number_format($row['share'], 0, ',', '.') }} %</span></span>
                            </div>
                            <div class="mt-1 h-2 rounded-full bg-gray-100 dark:bg-gray-700">
                                <div class="h-2 rounded-full" style="width: {{ round($row['amount'] / $maxAccount * 100, 2) }}%; background: var(--fin-expense)"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __(':count bookings from :from to :to. Own transfers are not counted.', ['count' => $report['count'], 'from' => $report['from']->translatedFormat('j. M Y'), 'to' => $report['to']->translatedFormat('j. M Y')]) }}</p>
            <a href="{{ route('finance.report', ['period' => $period, 'account' => $accountFilter ?: null]) }}" class="rounded-lg px-3 py-2 text-indigo-600 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">📄 {{ __('Download report as PDF') }}</a>
        </div>
    @endif
</div>
