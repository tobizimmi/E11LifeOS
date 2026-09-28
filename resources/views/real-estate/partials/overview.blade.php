{{-- Real estate overview (variables from real-estate/show: $model, $summary, $tax, $deadlines, $euro). --}}
@php
    $segments = collect([
        ['label' => __('Purchase price'), 'value' => $summary['purchase']['price'], 'color' => 'var(--re-1)'],
        ['label' => __('Closing costs'), 'value' => $summary['purchase']['closing'] + $summary['extra_purchase'], 'color' => 'var(--re-2)'],
        ['label' => __('Renovation'), 'value' => $summary['renovation']['expected'], 'color' => 'var(--re-3)'],
        ['label' => __('Other invoices'), 'value' => $summary['unassigned'], 'color' => 'var(--re-4)'],
    ])->filter(fn ($segment) => $segment['value'] > 0)->values();
    $segmentTotal = max(1, $segments->sum('value'));
    $funding = $summary['funding'];
    $tradeRows = $summary['items']->filter(fn ($row) => $row['planned'] > 0 || $row['invoiced'] > 0);
    $emptyTrades = $summary['items']->count() - $tradeRows->count();
@endphp

<style>
    .re-viz { --re-1: #2a78d6; --re-2: #eb6834; --re-3: #1baf7a; --re-4: #eda100; }
    @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) .re-viz { --re-1: #3987e5; --re-2: #d95926; --re-3: #199e70; --re-4: #c98500; } }
    :root[data-theme="dark"] .re-viz, .dark .re-viz { --re-1: #3987e5; --re-2: #d95926; --re-3: #199e70; --re-4: #c98500; }
</style>

<div class="re-viz space-y-5">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Total costs (expected)') }}</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $euro($summary['expected_total']) }}</p>
            @if ($summary['price_per_sqm'])
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __(':price €/m² purchase price', ['price' => number_format($summary['price_per_sqm'], 0, ',', '.')]) }}</p>
            @endif
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Invoiced / paid') }}</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $euro($summary['paid_total']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('of :amount invoiced', ['amount' => $euro($summary['invoiced_total'])]) }} · {{ trans_choice(':count open invoice|:count open invoices', $summary['open_invoices']->count()) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $funding['gap'] > 0 ? __('Funding gap') : __('Funding buffer') }}</p>
            <p @class(['mt-1 text-2xl font-semibold', 'text-red-700 dark:text-red-400' => $funding['gap'] > 0, 'text-green-700 dark:text-green-400' => $funding['gap'] <= 0])>{{ $funding['gap'] > 0 ? '⚠ ' : '✓ ' }}{{ $euro(abs($funding['gap'])) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Equity :equity · loans :loans · grants :grants', ['equity' => $euro($funding['equity']), 'loans' => $euro($funding['loans']), 'grants' => $euro($funding['grants'])]) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Monthly loan instalment') }}</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $euro($summary['monthly']['loan']) }}</p>
            @if ($model->rentalShare() > 0)
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Cash flow :amount/month', ['amount' => ($summary['monthly']['cashflow'] >= 0 ? '+' : '').$euro($summary['monthly']['cashflow'])]) }}@if ($summary['yield']) · {{ __('gross yield :percent %', ['percent' => number_format($summary['yield'], 1, ',', '.')]) }}@endif</p>
            @elseif ($tax)
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Tax effect :year: :amount', ['year' => $tax['year'], 'amount' => $euro($tax['effect'])]) }}</p>
            @endif
        </div>
    </div>

    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="cost-heading">
        <h3 id="cost-heading" class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Where the money goes') }}</h3>
        @if ($segments->isEmpty())
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Enter the purchase price and plan the renovation to see the cost structure.') }} <button type="button" wire:click="$set('tab', 'purchase')" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Enter purchase price') }}</button></p>
        @else
            <div class="mt-3 flex h-6 w-full gap-0.5 overflow-hidden rounded" role="img" aria-label="{{ $segments->map(fn ($segment) => $segment['label'].' '.$euro($segment['value']))->implode(', ') }}">
                @foreach ($segments as $segment)
                    <div class="h-full first:rounded-s last:rounded-e" style="width: {{ $segment['value'] / $segmentTotal * 100 }}%; background: {{ $segment['color'] }}" title="{{ $segment['label'] }}: {{ $euro($segment['value']) }} ({{ number_format($segment['value'] / $segmentTotal * 100, 0) }} %)"></div>
                @endforeach
            </div>
            <ul class="mt-3 flex flex-wrap gap-x-6 gap-y-1.5 text-sm">
                @foreach ($segments as $segment)
                    <li class="flex items-center gap-2 whitespace-nowrap">
                        <span class="h-3 w-3 shrink-0 rounded-sm" style="background: {{ $segment['color'] }}" aria-hidden="true"></span>
                        <span class="text-gray-600 dark:text-gray-300">{{ $segment['label'] }}</span>
                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $euro($segment['value']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="grid gap-5 lg:grid-cols-3">
        <section class="rounded-2xl bg-white p-5 shadow-sm lg:col-span-2 dark:bg-gray-800" aria-labelledby="trades-heading">
            <div class="flex items-center justify-between gap-2">
                <h3 id="trades-heading" class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Budget by trade') }}</h3>
                <button type="button" wire:click="$set('tab', 'budget')" class="text-sm text-indigo-600 underline dark:text-indigo-400">{{ __('Edit') }}</button>
            </div>
            @if ($tradeRows->isEmpty())
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $emptyTrades > 0 ? __('Enter planned amounts for your trades to compare them with the invoices.') : __('No trades planned yet.') }}</p>
            @else
                <ul class="mt-3 space-y-3">
                    @foreach ($tradeRows as $row)
                        @php
                            $base = max($row['planned'], $row['invoiced'], 1);
                        @endphp
                        <li wire:key="overview-item-{{ $row['item']->id }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $row['item']->title }} @if ($row['item']->energy_measure)<span title="{{ __('Energy measure') }}">🌱</span>@endif</span>
                                <span class="text-gray-600 dark:text-gray-300">
                                    {{ $euro($row['invoiced']) }} / {{ $row['planned'] > 0 ? $euro($row['planned']) : __('no plan') }}
                                    @if ($row['over'])
                                        <span class="ms-1 rounded bg-red-50 px-1.5 text-xs text-red-700 dark:bg-red-900/40 dark:text-red-300">⚠ {{ __('over budget by :amount', ['amount' => $euro($row['invoiced'] - $row['planned'])]) }}</span>
                                    @endif
                                </span>
                            </div>
                            <div class="mt-1 flex items-center gap-2">
                                <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" title="{{ __('Invoiced') }}: {{ $euro($row['invoiced']) }}">
                                    <div class="h-full rounded-full" style="width: {{ min(100, $row['invoiced'] / $base * 100) }}%; background: var(--re-1)"></div>
                                </div>
                                <span class="w-24 shrink-0 text-end text-xs text-gray-500 dark:text-gray-400">{{ \App\Models\RealEstateBudgetItem::statusLabels()[$row['item']->status] }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
                @if ($emptyTrades > 0)
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('+ :count trade without an amount|+ :count trades without an amount', $emptyTrades) }}</p>
                @endif
            @endif
        </section>

        <div class="space-y-5">
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="deadlines-heading">
                <h3 id="deadlines-heading" class="font-semibold text-gray-900 dark:text-gray-100">📅 {{ __('Next dates') }}</h3>
                <ul class="mt-2 space-y-1.5 text-sm">
                    @forelse ($deadlines as $deadline)
                        <li class="flex gap-2">
                            <span @class(['w-16 shrink-0', 'font-medium text-red-700 dark:text-red-400' => $deadline['overdue'], 'text-gray-500 dark:text-gray-400' => ! $deadline['overdue']])>{{ $deadline['overdue'] ? '⚠ ' : '' }}{{ $deadline['date']->translatedFormat('j. M') }}</span>
                            <span class="text-gray-800 dark:text-gray-200">{{ $deadline['label'] }}</span>
                        </li>
                    @empty
                        <li class="text-gray-500 dark:text-gray-400">{{ __('No dates coming up.') }}</li>
                    @endforelse
                </ul>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="subsidy-heading">
                <h3 id="subsidy-heading" class="font-semibold text-gray-900 dark:text-gray-100">🎁 {{ __('Subsidies') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __(':secured secured, :pending applied for or planned', ['secured' => $euro($funding['grants']), 'pending' => $euro($funding['pending_grants'])]) }}</p>
                <button type="button" wire:click="$set('tab', 'subsidies')" class="mt-2 text-sm text-indigo-600 underline dark:text-indigo-400">{{ __('Find subsidies') }}</button>
            </section>

            @if ($tax)
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="tax-heading">
                    <h3 id="tax-heading" class="font-semibold text-gray-900 dark:text-gray-100">🏛 {{ __('Tax :year (estimate)', ['year' => $tax['year']]) }}</h3>
                    <p @class(['mt-1 text-xl font-semibold', 'text-green-700 dark:text-green-400' => $tax['effect'] >= 0, 'text-red-700 dark:text-red-400' => $tax['effect'] < 0])>{{ $tax['effect'] >= 0 ? __('saves about :amount', ['amount' => $euro($tax['effect'])]) : __('costs about :amount', ['amount' => $euro(-$tax['effect'])]) }}</p>
                    <button type="button" wire:click="$set('tab', 'tax')" class="mt-1 text-sm text-indigo-600 underline dark:text-indigo-400">{{ __('Details') }}</button>
                </section>
            @endif
        </div>
    </div>
</div>
