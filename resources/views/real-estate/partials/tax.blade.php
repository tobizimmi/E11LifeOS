{{-- Estimated tax effect by year (variables from real-estate/show: $tax, $taxYears). --}}
<div class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
        <select wire:model.live="taxYear" aria-label="{{ __('Tax year') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
            @foreach ($taxYears as $year)
                <option value="{{ $year }}">{{ $year }}</option>
            @endforeach
        </select>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ $model->rentalShare() === 100 ? __('Let – rental income (Anlage V)') : ($model->rentalShare() === 0 ? __('Lived in – tax reductions (§ 35a, § 35c)') : __(':share % let, the rest lived in', ['share' => $model->rentalShare()])) }}
            · {{ __('personal tax rate :rate %', ['rate' => number_format($model->tax_rate, 0)]) }}
        </p>
    </div>

    @foreach ($tax['warnings'] as $warning)
        <p class="rounded-xl bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100" role="status">⚠ {{ $warning }}</p>
    @endforeach

    <div class="grid gap-5 lg:grid-cols-2">
        @if ($tax['rental'])
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="rental-tax-heading">
                <h3 id="rental-tax-heading" class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Letting (Anlage V)') }}</h3>
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">{{ __('Rent income') }}</dt><dd>{{ $euro($tax['rental']['rent'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">− {{ __('Depreciation (:rate % of :base)', ['rate' => number_format(\App\Support\RealEstate\RealEstateTax::depreciationRate($model->construction_year), 1, ',', ''), 'base' => $euro($tax['rental']['depreciation_base'])]) }}</dt><dd>{{ $euro($tax['rental']['depreciation'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">− {{ __('Loan interest') }}</dt><dd>{{ $euro($tax['rental']['interest'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">− {{ __('Maintenance paid') }}</dt><dd>{{ $euro($tax['rental']['maintenance'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">− {{ __('Running costs') }}</dt><dd>{{ $euro($tax['rental']['costs'], 2) }}</dd></div>
                    <div class="flex justify-between border-t border-gray-100 pt-1 font-semibold dark:border-gray-700"><dt>{{ $tax['rental']['result'] < 0 ? __('Loss from letting') : __('Income from letting') }}</dt><dd>{{ $euro($tax['rental']['result'], 2) }}</dd></div>
                </dl>
                <p @class(['mt-3 text-lg font-semibold', 'text-green-700 dark:text-green-400' => $tax['rental']['effect'] >= 0, 'text-red-700 dark:text-red-400' => $tax['rental']['effect'] < 0])>
                    {{ $tax['rental']['effect'] >= 0 ? __('Tax saving about :amount', ['amount' => $euro($tax['rental']['effect'])]) : __('Additional tax about :amount', ['amount' => $euro(-$tax['rental']['effect'])]) }}
                </p>
            </section>
        @endif

        @if ($tax['owner'])
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="owner-tax-heading">
                <h3 id="owner-tax-heading" class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Living in it (tax reductions)') }}</h3>
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">{{ __('Labour costs of craftsmen paid') }}</dt><dd>{{ $euro($tax['owner']['labor'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">{{ __('§ 35a: 20 %, max. 1,200 €') }}</dt><dd>{{ $euro($tax['owner']['craftsman'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-600 dark:text-gray-300">{{ __('§ 35c energy renovation (7/7/6 %)') }}</dt><dd>{{ $euro($tax['owner']['energy'], 2) }}</dd></div>
                </dl>
                <p class="mt-3 text-lg font-semibold text-green-700 dark:text-green-400">{{ __('Tax reduction about :amount', ['amount' => $euro($tax['owner']['effect'])]) }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Reduces the income tax directly. Not for new buildings and not for measures that received a subsidy; § 35a and § 35c cannot be combined for the same measure.') }}</p>
            </section>
        @endif
    </div>

    <p class="text-xs text-gray-500 dark:text-gray-400">ℹ️ {{ __('Simplified estimate based on your entries (payment dates, labour costs, budget lines) – not tax advice. Your tax adviser or the tax office decides.') }}</p>
</div>
