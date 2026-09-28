{{-- Tax year across Finances, properties, business and portfolio (variables from finance/index). --}}
@php
    $taxData = $taxYearData;
    $user = auth()->user();
@endphp

<div class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
        <select wire:model.live="taxYear" aria-label="{{ __('Tax year') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
            @foreach ($taxYears as $year)
                <option value="{{ $year }}">{{ __('Tax year :year', ['year' => $year]) }}</option>
            @endforeach
        </select>
        <form x-data="{ rate: @js((string) ($user->tax_rate ?? 30)), children: @js((string) ($user->tax_children ?? 0)) }" x-on:submit.prevent="$wire.saveTaxSettings(rate, children)" class="flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
            <label class="flex items-center gap-1">{{ __('Personal tax rate') }} <input type="number" min="0" max="45" step="1" x-model="rate" class="w-16 rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"> %</label>
            <label class="flex items-center gap-1">{{ __('Children') }} <input type="number" min="0" max="20" x-model="children" class="w-14 rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"></label>
            <button type="submit" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Apply') }}</button>
        </form>
        <a href="{{ route('finance.tax-export', $taxYear) }}" class="ms-auto rounded-lg px-3 py-1.5 text-sm ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700">⬇ {{ __('CSV for the tax return') }}</a>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Deductible expenses') }}</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $euro($taxData['deductible']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('≈ :amount less tax', ['amount' => $euro($taxData['deduction_effect'])]) }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Direct tax reductions') }}</p>
            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $euro($taxData['credits']['total']) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('§ 35a household & craftsman, § 35c') }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Estimated effect overall') }}</p>
            <p @class(['mt-1 text-2xl font-semibold', 'text-green-700 dark:text-green-400' => $taxData['effect'] >= 0, 'text-red-700 dark:text-red-400' => $taxData['effect'] < 0])>≈ {{ $euro(abs($taxData['effect'])) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $taxData['effect'] >= 0 ? __('less income tax') : __('more income tax') }} · {{ __('rate :rate %', ['rate' => number_format($taxData['rate'], 0)]) }}</p>
        </div>
    </div>

    @if ($taxSuggestions !== [])
        <section class="rounded-2xl bg-amber-50 p-5 shadow-sm dark:bg-amber-900/20" aria-labelledby="tax-suggestions-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 id="tax-suggestions-heading" class="font-semibold text-amber-950 dark:text-amber-100">💡 {{ trans_choice(':count booking may be tax-relevant|:count bookings may be tax-relevant', count($taxSuggestions)) }}</h3>
                <button type="button" wire:click="acceptTaxSuggestions" class="rounded-lg bg-amber-600 px-3 py-1.5 text-sm text-white hover:bg-amber-500">{{ __('Accept all') }}</button>
            </div>
            <ul class="mt-3 divide-y divide-amber-200/60 dark:divide-amber-800/40">
                @foreach (array_slice($taxSuggestions, 0, 30) as $row)
                    <li wire:key="tax-suggestion-{{ $row['transaction']->id }}" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                        <span class="w-16 text-xs text-amber-900/70 dark:text-amber-200/70">{{ $row['transaction']->booked_on->format('d.m.') }}</span>
                        <span class="min-w-0 flex-1 truncate text-amber-950 dark:text-amber-50">{{ $row['transaction']->counterparty ?: $row['transaction']->purpose }}</span>
                        <span class="tabular-nums text-amber-950 dark:text-amber-50">{{ $euro($row['transaction']->amount) }}</span>
                        <button type="button" wire:click="setTaxCategory({{ $row['transaction']->id }}, '{{ $row['category'] }}')" class="rounded-full bg-white px-2.5 py-0.5 text-xs text-amber-900 ring-1 ring-amber-300 hover:bg-amber-100 dark:bg-amber-950 dark:text-amber-100 dark:ring-amber-700">+ {{ $taxCategories[$row['category']]['icon'] }} {{ $taxCategories[$row['category']]['label'] }}</button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="deductions-heading">
            <h3 id="deductions-heading" class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Deductions from your bookings') }}</h3>
            <table class="mt-3 w-full text-sm">
                <thead class="text-xs text-gray-500 dark:text-gray-400"><tr><th class="text-start font-medium">{{ __('Category') }}</th><th class="text-end font-medium">{{ __('Paid') }}</th><th class="text-end font-medium">{{ __('Counts') }}</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($taxData['deductions'] as $key => $row)
                        <tr>
                            <td class="py-1.5" title="{{ $taxCategories[$key]['hint'] }}">{{ $taxCategories[$key]['icon'] }} {{ $taxCategories[$key]['label'] }} <span class="text-xs text-gray-400">· {{ $taxCategories[$key]['form'] }}</span></td>
                            <td class="whitespace-nowrap py-1.5 ps-2 text-end tabular-nums text-gray-600 dark:text-gray-300">{{ $euro($row['spent']) }}</td>
                            <td class="whitespace-nowrap py-1.5 ps-2 text-end tabular-nums font-medium text-gray-900 dark:text-gray-100">{{ $euro($row['deductible']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Work expenses count above the flat rate of 1,230 €; childcare 80 % up to 4,800 € per child; medical costs above the reasonable burden; insurance within the maximum amounts.') }}</p>
        </section>

        <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="credits-heading">
            <h3 id="credits-heading" class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Direct tax reductions') }}</h3>
            <dl class="mt-3 space-y-1.5 text-sm">
                <div class="flex justify-between gap-2"><dt class="text-gray-600 dark:text-gray-300">🧹 {{ __('Household services: 20 % of :amount', ['amount' => $euro($taxData['credits']['household_services']['spent'])]) }}</dt><dd class="whitespace-nowrap font-medium">{{ $euro($taxData['credits']['household_services']['credit']) }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-600 dark:text-gray-300">🔧 {{ __('Craftsmen: 20 % of :amount labour', ['amount' => $euro($taxData['credits']['craftsman']['spent'])]) }}@if ($taxData['credits']['craftsman']['from_properties'] > 0) <span class="text-xs text-gray-400">({{ __(':amount from properties', ['amount' => $euro($taxData['credits']['craftsman']['from_properties'])]) }})</span>@endif</dt><dd class="whitespace-nowrap font-medium">{{ $euro($taxData['credits']['craftsman']['credit']) }}</dd></div>
                <div class="flex justify-between gap-2"><dt class="text-gray-600 dark:text-gray-300">🌱 {{ __('Energy renovation (§ 35c)') }}</dt><dd class="whitespace-nowrap font-medium">{{ $euro($taxData['credits']['energy']['credit']) }}</dd></div>
            </dl>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Enter the labour costs of a booking below – only they count for § 35a. Caps: 4,000 € and 1,200 € per household.') }}</p>
        </section>
    </div>

    @if ($taxData['properties'] !== [] || $taxData['business'] || $taxData['portfolio'])
        <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="modules-heading">
            <h3 id="modules-heading" class="font-semibold text-gray-900 dark:text-gray-100">{{ __('From your other modules') }}</h3>
            <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @foreach ($taxData['properties'] as $row)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <a href="{{ route('real-estate.show', ['realEstate' => $row['model']->id, 'tab' => 'tax', 'year' => $taxYear]) }}" wire:navigate class="text-gray-800 hover:underline dark:text-gray-200">🏠 {{ $row['model']->name }}</a>
                        @php
                            $parts = array_filter([
                                $row['tax']['rental'] ? __('Anlage V: :result', ['result' => $euro($row['tax']['rental']['result'])]) : null,
                                ($row['tax']['owner']['labor'] ?? 0) > 0 ? __(':amount craftsman labour', ['amount' => $euro($row['tax']['owner']['labor'])]) : null,
                                ($row['tax']['owner']['energy'] ?? 0) > 0 ? __('§ 35c: :amount', ['amount' => $euro($row['tax']['owner']['energy'])]) : null,
                            ]);
                        @endphp
                        <span class="text-gray-600 dark:text-gray-300">{{ $parts ? implode(' · ', $parts) : __('nothing tax-relevant this year') }}</span>
                    </li>
                @endforeach
                @if ($taxData['business'])
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <a href="{{ route('business.report') }}" wire:navigate class="text-gray-800 hover:underline dark:text-gray-200">💼 {{ __('Small business (EÜR)') }}</a>
                        <span class="text-gray-600 dark:text-gray-300">{{ __('Profit :profit', ['profit' => $euro($taxData['business']['profit'])]) }}</span>
                    </li>
                @endif
                @if ($taxData['portfolio'])
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <a href="{{ route('portfolio.taxes', ['year' => $taxYear]) }}" wire:navigate class="text-gray-800 hover:underline dark:text-gray-200">📈 {{ __('Capital gains (Anlage KAP)') }}</a>
                        <span class="text-gray-600 dark:text-gray-300">{{ __('Net :net · allowance used :used of :allowance · tax :tax (withheld separately)', ['net' => $euro($taxData['portfolio']['net']), 'used' => $euro($taxData['portfolio']['allowance_used']), 'allowance' => $euro($taxData['portfolio']['allowance']), 'tax' => $euro($taxData['portfolio']['tax'])]) }}</span>
                    </li>
                @endif
            </ul>
        </section>
    @endif

    <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800" aria-labelledby="tax-bookings-heading">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-700">
            <h3 id="tax-bookings-heading" class="font-semibold text-gray-900 dark:text-gray-100">{{ trans_choice(':count tax-relevant booking|:count tax-relevant bookings', $taxData['bookings']->count()) }}</h3>
            <input type="search" wire:model.live.debounce.300ms="taxSearch" placeholder="{{ __('Find another booking …') }}" aria-label="{{ __('Find another booking') }}" class="w-64 max-w-full rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
        </div>

        @if ($taxSearchResults->isNotEmpty())
            <ul class="divide-y divide-gray-100 bg-indigo-50/40 dark:divide-gray-700 dark:bg-indigo-900/10">
                @foreach ($taxSearchResults as $transaction)
                    <li wire:key="tax-search-{{ $transaction->id }}" class="flex flex-wrap items-center gap-3 px-5 py-2 text-sm">
                        <span class="w-16 text-xs text-gray-500">{{ $transaction->booked_on->format('d.m.') }}</span>
                        <span class="min-w-0 flex-1 truncate">{{ $transaction->counterparty ?: $transaction->purpose }}</span>
                        <span class="tabular-nums">{{ $euro($transaction->amount) }}</span>
                        <select wire:change="setTaxCategory({{ $transaction->id }}, $event.target.value)" aria-label="{{ __('Tax category') }}" class="rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            <option value="">{{ __('Assign …') }}</option>
                            @foreach ($taxCategories as $key => $category)
                                <option value="{{ $key }}">{{ $category['icon'] }} {{ $category['label'] }}</option>
                            @endforeach
                        </select>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($taxData['bookings']->isEmpty())
            <p class="p-5 text-sm text-gray-500 dark:text-gray-400">{{ __('No bookings assigned yet. Accept the suggestions above or search for a booking.') }}</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($taxData['bookings'] as $transaction)
                    <li wire:key="tax-booking-{{ $transaction->id }}" class="flex flex-wrap items-center gap-3 px-5 py-2 text-sm">
                        <span class="w-16 text-xs text-gray-500 dark:text-gray-400">{{ $transaction->booked_on->format('d.m.') }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-gray-900 dark:text-gray-100">{{ $transaction->counterparty ?: '—' }}</span>
                            <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $transaction->purpose }}</span>
                        </span>
                        <select wire:change="setTaxCategory({{ $transaction->id }}, $event.target.value)" aria-label="{{ __('Tax category') }}" class="rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            <option value="">{{ __('– not relevant –') }}</option>
                            @foreach ($taxCategories as $key => $category)
                                <option value="{{ $key }}" @selected($transaction->tax_category === $key)>{{ $category['icon'] }} {{ $category['label'] }}</option>
                            @endforeach
                        </select>
                        <label class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400" title="{{ __('Only this part counts, e.g. labour costs (empty = whole amount)') }}">
                            {{ in_array($transaction->tax_category, ['household_services', 'craftsman'], true) ? __('Labour') : __('Counts') }}
                            <input type="text" inputmode="decimal" value="{{ $transaction->tax_amount !== null ? number_format($transaction->tax_amount, 2, ',', '') : '' }}" placeholder="{{ number_format(abs($transaction->amount), 2, ',', '') }}" wire:change="setTaxAmount({{ $transaction->id }}, $event.target.value)" class="w-24 rounded-md border-gray-300 py-0.5 text-end text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        </label>
                        <span class="w-24 text-end tabular-nums font-medium text-gray-900 dark:text-gray-100">{{ $euro($transaction->amount) }}</span>
                        <x-input-error :messages="$errors->get('taxAmount.'.$transaction->id)" class="w-full" />
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <p class="text-xs text-gray-500 dark:text-gray-400">ℹ️ {{ __('Simplified estimate from your bookings and modules – not tax advice. Keep receipts; the tax office or your tax adviser decides.') }}</p>
</div>
