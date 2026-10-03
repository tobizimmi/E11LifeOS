<?php

use App\Livewire\Concerns\InteractsWithBusiness;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Support\Business\BookkeepingCategories;
use App\Support\Business\EuerReport;
use App\Support\Business\VatReturn;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithBusiness;
    use InteractsWithHousehold;

    #[Url(except: 0)]
    public int $year = 0;

    /** Filing interval of the VAT advance return: quarter or month. */
    #[Url(except: 'quarter')]
    public string $vatInterval = 'quarter';

    public function mount(): void
    {
        // In the first months the previous year's statement is the relevant one.
        $this->year = $this->year >= 2000 && $this->year <= 2100 ? $this->year : (now()->month <= 5 ? now()->year - 1 : now()->year);
    }

    public function with(): array
    {
        $profile = $this->businessProfile();
        $years = $this->invoices()->whereNotNull('paid_on')->pluck('paid_on')->map->year
            ->merge($this->bookkeepingEntries()->pluck('booked_on')->map->year)
            ->push(now()->year, now()->year - 1, $this->year)->unique()->sortDesc()->values();

        return [
            'profile' => $profile,
            'report' => EuerReport::build($this->currentUser(), (int) $this->householdId, $this->year, $profile),
            'lineDefinitions' => BookkeepingCategories::euerLines(),
            'years' => $years,
            'vatPeriods' => $profile && ! $profile->small_business
                ? VatReturn::periods($this->currentUser(), (int) $this->householdId, $this->year, $this->vatInterval === 'month' ? 'month' : 'quarter')
                : [],
        ];
    }
}; ?>

@php
    $euro = fn ($value, $decimals = 2) => number_format((float) $value, $decimals, ',', '.').' €';
    $maxMonth = max(1, collect($report['monthly'])->flatMap(fn ($month) => [$month['income'], $month['expenses']])->max());
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $profile?->company_name ?: __('Small business') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your business data.') }}</span>
        </div>
    </x-slot>

    <style>
        .biz-root { --biz-income: #2a78d6; --biz-expense: #eb6834; }
        @media (prefers-color-scheme: dark) { :root:where(:not([data-theme="light"])) .biz-root { --biz-income: #3987e5; --biz-expense: #d95926; } }
        :root[data-theme="dark"] .biz-root { --biz-income: #3987e5; --biz-expense: #d95926; }
    </style>

    <div class="py-6">
        <div class="biz-root max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('business.partials.nav', ['active' => 'business.report'])

            <div class="flex flex-wrap items-center gap-3">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Profit calculation (EÜR) :year', ['year' => $year]) }}</h3>
                <select wire:model.live="year" aria-label="{{ __('Year') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    @foreach ($years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
                <div class="ms-auto flex flex-wrap gap-2">
                    <a href="{{ route('business.report.download', ['year' => $year, 'format' => 'pdf']) }}" class="rounded-lg px-3 py-2 text-sm text-indigo-600 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">📄 {{ __('PDF for the tax return') }}</a>
                    <a href="{{ route('business.report.download', ['year' => $year, 'format' => 'csv']) }}" class="rounded-lg px-3 py-2 text-sm text-indigo-600 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">⬇ {{ __('All bookings (CSV)') }}</a>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                @foreach ([
                    [__('Business income'), $euro($report['incomeTotal']), 'var(--biz-income)'],
                    [__('Business expenses'), $euro($report['expenseTotal']), 'var(--biz-expense)'],
                    [$report['profit'] >= 0 ? __('Profit') : __('Loss'), $euro($report['profit']), null],
                ] as [$label, $value, $color])
                    <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                        <p class="flex items-center gap-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">
                            @if ($color)<span class="h-2.5 w-2.5 rounded-full" style="background: {{ $color }}" aria-hidden="true"></span>@endif
                            {{ $label }}
                        </p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Hints --}}
            <div class="space-y-2">
                @if ($report['limitStatus'] === 'ok')
                    <p class="rounded-xl bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100">✓ {{ __('Revenue :amount – below the small business limit of 25,000 €.', ['amount' => $euro($report['revenue'], 0)]) }}</p>
                @elseif ($report['limitStatus'] === 'next_year')
                    <p class="rounded-xl bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">⚠ {{ __('Revenue :amount – above 25,000 €: in the following year the small business rule no longer applies.', ['amount' => $euro($report['revenue'], 0)]) }}</p>
                @elseif ($report['limitStatus'] === 'exceeded')
                    <p class="rounded-xl bg-red-50 px-4 py-2.5 text-sm text-red-900 dark:bg-red-900/30 dark:text-red-100">⚠ {{ __('Revenue :amount – above 100,000 €: VAT is due from the invoice that crossed the limit. Please talk to your tax advisor.', ['amount' => $euro($report['revenue'], 0)]) }}</p>
                @endif
                @if ($report['missingReceipts'] > 0)
                    <p class="rounded-xl bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">📎 {{ trans_choice(':count expense has no receipt attached – keep receipts for 8 to 10 years.|:count expenses have no receipt attached – keep receipts for 8 to 10 years.', $report['missingReceipts']) }} <a href="{{ route('business.bookings', ['year' => $year, 'kind' => 'missing']) }}" wire:navigate class="font-medium underline">{{ __('Show') }}</a></p>
                @endif
                @if ($report['openInvoiceCount'] > 0)
                    <p class="rounded-xl bg-sky-50 px-4 py-2.5 text-sm text-sky-900 dark:bg-sky-900/30 dark:text-sky-100">⏳ {{ trans_choice(':count invoice (:amount) is still open – it counts in the year it is paid.|:count invoices (:amount) are still open – they count in the year they are paid.', $report['openInvoiceCount'], ['amount' => $euro($report['openInvoiceTotal'])]) }}</p>
                @endif
            </div>

            <div class="grid gap-5 lg:grid-cols-5">
                {{-- Anlage EÜR --}}
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800 lg:col-span-3">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Figures for the Anlage EÜR') }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Enter these amounts in the matching fields of the Anlage EÜR in ELSTER. Line numbers change from year to year – the names are the same.') }}</p>

                    @foreach (['income' => __('Business income'), 'expense' => __('Business expenses')] as $section => $sectionLabel)
                        <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $sectionLabel }}</h4>
                        <table class="mt-1 w-full text-sm">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @php
                                    $sectionLines = collect($lineDefinitions)->filter(fn ($line, $key) => $line['section'] === $section && $report['lines'][$key] != 0);
                                @endphp
                                @forelse ($sectionLines as $key => $line)
                                    <tr>
                                        <td class="py-1.5 pe-3 text-gray-700 dark:text-gray-300">{{ $line['label'] }}</td>
                                        <td class="whitespace-nowrap py-1.5 text-end tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($report['lines'][$key]) }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="py-1.5 text-gray-500 dark:text-gray-400" colspan="2">—</td></tr>
                                @endforelse
                                <tr class="font-semibold">
                                    <td class="py-1.5 pe-3 text-gray-900 dark:text-gray-100">{{ __('Total') }}</td>
                                    <td class="whitespace-nowrap py-1.5 text-end tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($section === 'income' ? $report['incomeTotal'] : $report['expenseTotal']) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    @endforeach

                    <div class="mt-4 flex items-center justify-between rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-900/40">
                        <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $report['profit'] >= 0 ? __('Profit') : __('Loss') }}</span>
                        <span class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($report['profit']) }}</span>
                    </div>
                    @if ($report['nonDeductible'] > 0)
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Non-deductible share of business meals (30 %): :amount', ['amount' => $euro($report['nonDeductible'])]) }}</p>
                    @endif
                </section>

                <div class="space-y-5 lg:col-span-2">
                    {{-- Months --}}
                    <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <div class="flex items-center justify-between gap-2">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Month by month') }}</h3>
                            <div class="flex gap-3 text-xs text-gray-600 dark:text-gray-300">
                                <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-xs" style="background: var(--biz-income)"></span>{{ __('Income') }}</span>
                                <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-xs" style="background: var(--biz-expense)"></span>{{ __('Expenses') }}</span>
                            </div>
                        </div>
                        <div class="mt-4 flex h-40 items-end gap-1 border-b border-gray-200 dark:border-gray-700">
                            @foreach ($report['monthly'] as $month)
                                <div class="group relative flex h-full flex-1 items-end justify-center gap-px" tabindex="0">
                                    <div class="w-full max-w-[10px] rounded-t-sm" style="height: {{ $month['income'] / $maxMonth * 100 }}%; background: var(--biz-income)"></div>
                                    <div class="w-full max-w-[10px] rounded-t-sm" style="height: {{ $month['expenses'] / $maxMonth * 100 }}%; background: var(--biz-expense)"></div>
                                    <div class="pointer-events-none absolute bottom-full z-10 mb-1 hidden whitespace-nowrap rounded-lg bg-gray-900 px-2 py-1 text-xs text-white shadow-sm group-hover:block group-focus:block">
                                        {{ $month['label'] }}: {{ $euro($month['income'], 0) }} / {{ $euro($month['expenses'], 0) }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-1 flex gap-1 text-center text-[10px] text-gray-500 dark:text-gray-400">
                            @foreach ($report['monthly'] as $month)
                                <span class="flex-1">{{ mb_substr($month['label'], 0, 1) }}</span>
                            @endforeach
                        </div>
                    </section>

                    @unless ($report['smallBusiness'])
                        <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('VAT overview') }}</h3>
                            <dl class="mt-2 space-y-1 text-sm">
                                <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">{{ __('VAT received') }}</dt><dd class="tabular-nums">{{ $euro($report['vat']['received']) }}</dd></div>
                                <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">− {{ __('Input VAT paid') }}</dt><dd class="tabular-nums">{{ $euro($report['vat']['paid']) }}</dd></div>
                                <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">− {{ __('Already paid to the tax office') }}</dt><dd class="tabular-nums">{{ $euro($report['vat']['payments']) }}</dd></div>
                                <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">+ {{ __('Refunded by the tax office') }}</dt><dd class="tabular-nums">{{ $euro($report['vat']['refunded']) }}</dd></div>
                                <div class="flex justify-between border-t border-gray-200 pt-1 font-semibold dark:border-gray-700"><dt>{{ $report['vat']['balance'] >= 0 ? __('Still to pay') : __('Refund expected') }}</dt><dd class="tabular-nums">{{ $euro(abs($report['vat']['balance'])) }}</dd></div>
                            </dl>
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Based on payments received and made (cash accounting).') }}</p>
                        </section>
                    @endunless

                    <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">🗂 {{ __('Your tax return checklist') }}</h3>
                        <ol class="mt-2 list-decimal space-y-1.5 ps-5 text-sm text-gray-700 dark:text-gray-300">
                            <li>{{ __('Anlage EÜR: enter the figures on the left (ELSTER, due with the income tax return).') }}</li>
                            <li>{{ __('Anlage G: profit from trade – transfer the profit from the EÜR.') }}</li>
                            <li>{{ __('Trade tax: a return is needed if the profit exceeds 24,500 € (allowance for individuals).') }}</li>
                            @if ($report['smallBusiness'])
                                <li>{{ __('VAT: as a small business you usually do not have to file an annual VAT return (since 2024), unless the tax office asks you to.') }}</li>
                            @else
                                <li>{{ __('Annual VAT return (Umsatzsteuererklärung) with the figures from the VAT overview.') }}</li>
                            @endif
                            <li>{{ __('Keep invoices and receipts for 8 years (receipts) or 10 years (books, invoices).') }}</li>
                        </ol>
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('This overview helps with the tax return but is not tax advice. Special cases (assets over 800 €, private use, car logbook) are best checked with a tax advisor.') }}</p>
                    </section>
                </div>
            </div>

            @if ($vatPeriods)
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('VAT advance return (USt-VA)') }}</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Fields of the ELSTER form, based on payments received (cash accounting). Due on the 10th of the following month.') }}</p>
                        </div>
                        <select wire:model.live="vatInterval" aria-label="{{ __('Interval') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            <option value="quarter">{{ __('Quarterly') }}</option>
                            <option value="month">{{ __('Monthly') }}</option>
                        </select>
                    </div>
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[36rem] text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                    <th class="py-1.5 pe-3 font-medium">{{ __('Period') }}</th>
                                    <th class="py-1.5 pe-3 text-end font-medium" title="{{ __('Net revenue at 19 %') }}">Kz 81</th>
                                    <th class="py-1.5 pe-3 text-end font-medium" title="{{ __('Net revenue at 7 %') }}">Kz 86</th>
                                    <th class="py-1.5 pe-3 text-end font-medium">{{ __('VAT') }}</th>
                                    <th class="py-1.5 pe-3 text-end font-medium" title="{{ __('Input VAT') }}">Kz 66</th>
                                    <th class="py-1.5 text-end font-medium" title="{{ __('Advance payment') }}">Kz 83</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                                @foreach ($vatPeriods as $period)
                                    <tr class="{{ $period['from']->isFuture() ? 'text-gray-400 dark:text-gray-500' : 'text-gray-900 dark:text-gray-100' }}">
                                        <td class="py-1.5 pe-3">{{ $period['label'] }}</td>
                                        <td class="py-1.5 pe-3 text-end">{{ $euro($period['kz81']) }}</td>
                                        <td class="py-1.5 pe-3 text-end">{{ $euro($period['kz86']) }}</td>
                                        <td class="py-1.5 pe-3 text-end">{{ $euro($period['kz81_vat'] + $period['kz86_vat'] + $period['other_vat']) }}</td>
                                        <td class="py-1.5 pe-3 text-end">{{ $euro($period['kz66']) }}</td>
                                        <td class="py-1.5 text-end font-semibold">{{ $euro($period['kz83']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Kz 81: net revenue at 19 %, Kz 86: net revenue at 7 %, Kz 66: input VAT from receipts, Kz 83: amount to pay (negative = refund).') }}</p>
                </section>
            @endif
        </div>
    </div>
</div>
