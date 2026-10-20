<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\PortfolioTransaction;
use App\Models\Security;
use App\Support\Portfolio\TaxReport;
use App\Support\Portfolio\Vorabpauschale;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    #[Url(except: 0)]
    public int $year = 0;

    #[Url(as: 'depot', except: 0)]
    public int $portfolioId = 0;

    public string $vpStart = '';

    public string $vpEnd = '';

    public string $vpDistributions = '';

    public string $vpFundType = 'equity';

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->year = $this->year >= 2000 && $this->year <= 2100 ? $this->year : now()->year;
    }

    public function with(): array
    {
        $ids = $this->portfolioId ? $this->portfolios()->whereKey($this->portfolioId)->pluck('id') : $this->portfolios()->pluck('id');
        $transactions = PortfolioTransaction::whereIn('portfolio_id', $ids)->where('executed_on', '<=', $this->year.'-12-31')->get();
        $settings = $this->portfolioSettings();
        $assetTypes = Security::whereIn('symbol', $transactions->pluck('symbol')->unique())->pluck('asset_type', 'symbol')->all();

        return [
            'portfolioList' => $this->portfolios()->orderBy('id')->get(),
            'report' => TaxReport::build($transactions, $this->year, (float) ($settings->tax_allowance ?? 1000), (float) ($settings->church_tax_rate ?? 0), $assetTypes),
            'years' => PortfolioTransaction::whereIn('portfolio_id', $this->portfolios()->select('id'))->pluck('executed_on')->map->year
                ->push(now()->year, $this->year)->unique()->sortDesc()->values(),
            'vorab' => Vorabpauschale::estimate(
                (float) str_replace(',', '.', $this->vpStart),
                (float) str_replace(',', '.', $this->vpEnd),
                (float) str_replace(',', '.', $this->vpDistributions),
                $this->year,
                $this->vpFundType,
                (float) ($settings->church_tax_rate ?? 0),
            ),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $tone = fn ($value) => $value > 0 ? 'text-emerald-700 dark:text-emerald-400' : ($value < 0 ? 'text-orange-700 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400');
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 6, ',', '.'), '0'), ',');
    $allowanceShare = $report['allowance'] > 0 ? min(100, $report['allowance_used'] / $report['allowance'] * 100) : 0;
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
            @include('portfolio.partials.nav', ['active' => 'portfolio.taxes'])

            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Capital gains :year', ['year' => $year]) }}</h3>
                <select wire:model.live="year" aria-label="{{ __('Year') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    @foreach ($years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
                <select wire:model.live="portfolioId" aria-label="{{ __('Portfolio') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    <option value="0">{{ __('All portfolios') }}</option>
                    @foreach ($portfolioList as $portfolio)
                        <option value="{{ $portfolio->id }}">{{ $portfolio->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Capital income') }}</p>
                    <p class="mt-1 whitespace-nowrap text-xl font-semibold tabular-nums sm:text-2xl {{ $tone($report['net']) }}">{{ $euro($report['net']) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('gains, losses and dividends') }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Tax-free allowance used') }}</p>
                    <p class="mt-1 whitespace-nowrap text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100 sm:text-2xl">{{ $euro($report['allowance_used']) }}</p>
                    <div class="mt-1 h-1.5 rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-1.5 rounded-full bg-indigo-500" style="width: {{ $allowanceShare }}%"></div></div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('of :amount', ['amount' => $euro($report['allowance'])]) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Taxable') }}</p>
                    <p class="mt-1 whitespace-nowrap text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100 sm:text-2xl">{{ $euro($report['taxable']) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('above the allowance') }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Estimated tax') }}</p>
                    <p class="mt-1 whitespace-nowrap text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100 sm:text-2xl">{{ $euro($report['tax']) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $report['church_tax_rate'] > 0 ? __('incl. Soli and church tax') : __('25 % + Soli') }}</p>
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800 lg:col-span-1">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Offsetting pots') }}</h3>
                    <dl class="mt-3 space-y-1.5 text-sm">
                        @foreach ([
                            [__('Share gains'), $report['share_gains']],
                            [__('Share losses'), $report['share_losses']],
                            [__('Other gains (ETFs, funds …)'), $report['other_gains']],
                            [__('Other losses'), $report['other_losses']],
                            [__('Dividends and distributions'), $report['dividends']],
                        ] as [$label, $value])
                            <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-300">{{ $label }}</dt><dd class="whitespace-nowrap tabular-nums {{ $tone($value) }}">{{ $euro($value) }}</dd></div>
                        @endforeach
                        <div class="flex justify-between gap-3 border-t border-gray-200 pt-1.5 font-semibold dark:border-gray-700"><dt>{{ __('Capital income') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($report['net']) }}</dd></div>
                        @if ($report['taxes_paid'] > 0)
                            <div class="flex justify-between gap-3 text-xs text-gray-500 dark:text-gray-400"><dt>{{ __('Taxes already withheld (recorded)') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($report['taxes_paid']) }}</dd></div>
                        @endif
                    </dl>
                    @if ($report['share_gains'] + $report['share_losses'] < 0)
                        <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">{{ __('Share losses of :amount remain in the share loss pot and are carried forward.', ['amount' => $euro(abs($report['share_gains'] + $report['share_losses']))]) }}</p>
                    @endif
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('FIFO method as used by German banks. Partial exemptions on sales (Teilfreistellung) are not included; the advance lump sum (Vorabpauschale) has its own estimator below. No tax advice – the bank\'s annual tax certificate is binding.') }}</p>
                </section>

                <section class="overflow-hidden rounded-2xl bg-white shadow-xs dark:bg-gray-800 lg:col-span-2">
                    <h3 class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">{{ __('Taxable events') }}</h3>
                    @if ($report['events'] === [])
                        <p class="border-t border-gray-100 px-4 py-6 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ __('No sales or dividends in :year.', ['year' => $year]) }}</p>
                    @else
                        <div class="overflow-x-auto border-t border-gray-100 dark:border-gray-700">
                            <table class="w-full min-w-[36rem] text-sm">
                                <thead class="bg-gray-50 text-left text-xs text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
                                    <tr>
                                        <th class="px-4 py-2 font-medium">{{ __('Date') }}</th>
                                        <th class="px-3 py-2 font-medium">{{ __('Security') }}</th>
                                        <th class="px-3 py-2 text-end font-medium">{{ __('Proceeds') }}</th>
                                        <th class="px-3 py-2 text-end font-medium">{{ __('Purchase value') }}</th>
                                        <th class="px-3 py-2 text-end font-medium">{{ __('Gain / loss') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @foreach ($report['events'] as $event)
                                        <tr>
                                            <td class="px-4 py-2 tabular-nums text-gray-500">{{ $event['date']->format('d.m.Y') }}</td>
                                            <td class="max-w-[14rem] px-3 py-2">
                                                <span class="block truncate text-gray-900 dark:text-gray-100">{{ $event['name'] }}</span>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $event['kind'] === 'dividend' ? __('Dividend') : __('Sale').' · '.$quantity($event['quantity']).' '.__('Shares') }}</span>
                                            </td>
                                            <td class="px-3 py-2 text-end tabular-nums">{{ $euro($event['proceeds']) }}</td>
                                            <td class="px-3 py-2 text-end tabular-nums text-gray-500">{{ $event['kind'] === 'dividend' ? '—' : $euro($event['cost']) }}</td>
                                            <td class="px-3 py-2 text-end font-medium tabular-nums {{ $tone($event['gain']) }}">{{ $euro($event['gain']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>

            {{-- Vorabpauschale estimator for accumulating funds / ETFs --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Advance lump sum (Vorabpauschale) :year', ['year' => $year]) }}</h3>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('For accumulating funds and ETFs. Enter the fund value at the start and end of the year and any distributions received.') }}</p>

                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @php($field = 'block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200')
                    <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Value on 1 Jan') }}
                        <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="vpStart" class="{{ $field }} mt-1" placeholder="0,00">
                    </label>
                    <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Value on 31 Dec') }}
                        <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="vpEnd" class="{{ $field }} mt-1" placeholder="0,00">
                    </label>
                    <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Distributions in the year') }}
                        <input type="number" step="0.01" min="0" wire:model.live.debounce.400ms="vpDistributions" class="{{ $field }} mt-1" placeholder="0,00">
                    </label>
                    <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Fund type') }}
                        <select wire:model.live="vpFundType" class="{{ $field }} mt-1">
                            <option value="equity">{{ __('Equity fund (30 % exempt)') }}</option>
                            <option value="mixed">{{ __('Mixed fund (15 % exempt)') }}</option>
                            <option value="real_estate">{{ __('Real estate fund (60 % exempt)') }}</option>
                            <option value="none">{{ __('No partial exemption') }}</option>
                        </select>
                    </label>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Base return') }} ({{ number_format($vorab['base_rate'] * 100, 2, ',', '.') }} % × 70 %)</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($vorab['base_return']) }}</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Vorabpauschale') }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($vorab['gross']) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('taxable after exemption: :amount', ['amount' => $euro($vorab['taxable'])]) }}</p>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Estimated tax') }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($vorab['tax']) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('counts towards your :amount allowance', ['amount' => $euro($report['allowance'])]) }}</p>
                    </div>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Estimate only. The Vorabpauschale is zero if the fund did not gain value or distributed enough. Your bank withholds it in January of the following year.') }}</p>
            </section>
        </div>
    </div>
</div>
