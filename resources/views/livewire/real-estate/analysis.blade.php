<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\RealEstate;
use App\Models\RealEstateAnalysis;
use App\Support\RealEstate\ProfitabilityAnalysis;
use App\Support\RealEstate\RealEstateLink;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Profitability check of a rental property: purchase costs, financing, rent against the local
 * rent index, running costs, depreciation and tax – with key figures, hints and a projection.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /** Saved calculation shown (id), empty for a new one. */
    #[Url(as: 'id', except: '')]
    public string $analysisId = '';

    public string $title = '';

    /**
     * @var array<string, string>
     */
    public array $in = [];

    /**
     * Detailed renovation items (label + cost); their sum fills the renovation field.
     *
     * @var list<array{label: string, amount: string}>
     */
    public array $renoItems = [];

    public function mount(): void
    {
        $this->resolveHousehold();
        $saved = $this->analysisId !== '' ? $this->findAnalysis((int) $this->analysisId) : null;
        $realEstateId = request()->query('real_estate');
        $realEstate = is_string($realEstateId) && ctype_digit($realEstateId) ? $this->properties()->firstWhere('id', (int) $realEstateId) : null;

        if ($saved) {
            $this->fill(['title' => $saved->title]);
            $this->setRenoItems($saved->inputs['renovation_items'] ?? []);
            $this->setInputs(array_merge(ProfitabilityAnalysis::defaults(), $saved->inputs));
        } elseif ($realEstate) {
            $this->title = $realEstate->name;
            $this->setInputs(ProfitabilityAnalysis::fromRealEstate($realEstate->load('loans')));
        } else {
            $this->analysisId = '';
            $this->setInputs(ProfitabilityAnalysis::defaults());
        }
    }

    /**
     * @param  array<string, float|int|string>  $values
     */
    private function setInputs(array $values): void
    {
        $this->in = collect($values)->map(fn ($value) => is_array($value) ? '' : (is_float($value) ? rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',') : (string) $value))->all();
        unset($this->in['renovation_items']);
    }

    /**
     * @param  mixed  $items
     */
    private function setRenoItems($items): void
    {
        $this->renoItems = collect(is_array($items) ? $items : [])
            ->map(fn ($row) => ['label' => (string) ($row['label'] ?? ''), 'amount' => (string) ($row['amount'] ?? '')])
            ->values()->all();
    }

    public function addRenoItem(): void
    {
        $this->renoItems[] = ['label' => '', 'amount' => ''];
    }

    public function removeRenoItem(int $index): void
    {
        unset($this->renoItems[$index]);
        $this->renoItems = array_values($this->renoItems);
        $this->syncRenovation();
    }

    /**
     * When renovation items are used, their sum fills the renovation field.
     */
    public function updatedRenoItems(): void
    {
        $this->syncRenovation();
    }

    private function syncRenovation(): void
    {
        if ($this->renoItems === []) {
            return;
        }

        $sum = array_sum(array_map(fn (array $row) => $this->toNumber($row['amount']), $this->renoItems));
        $this->in['renovation'] = rtrim(rtrim(number_format($sum, 2, ',', ''), '0'), ',');
    }

    /**
     * A German-written number ("1.200,50") as a float.
     */
    private function toNumber(string $raw): float
    {
        $raw = trim($raw);
        $number = str_contains($raw, ',') || preg_match('/^\d{1,3}(\.\d{3})+$/', $raw) ? str_replace(['.', ','], ['', '.'], $raw) : $raw;

        return is_numeric($number) ? max(0.0, (float) $number) : 0.0;
    }

    /**
     * @return \Illuminate\Support\Collection<int, RealEstate>
     */
    private function properties()
    {
        return RealEstateLink::properties(Auth::user(), (int) $this->householdId);
    }

    private function findAnalysis(int $id): ?RealEstateAnalysis
    {
        return RealEstateAnalysis::where('household_id', $this->householdId)->find($id);
    }

    /**
     * Numbers as entered (German decimal comma allowed), kept within sensible limits.
     *
     * @return array<string, float|int|string>
     */
    private function normalized(): array
    {
        $values = ProfitabilityAnalysis::defaults();

        foreach ($values as $key => $default) {
            $raw = trim((string) ($this->in[$key] ?? ''));

            if ($key === 'state') {
                $values[$key] = array_key_exists($raw, RealEstate::TRANSFER_TAX) ? $raw : $default;

                continue;
            }

            // "300.000" and "3,8" as written in German; "3.8" is taken as a decimal point.
            $number = str_contains($raw, ',') || preg_match('/^\d{1,3}(\.\d{3})+$/', $raw)
                ? str_replace(['.', ','], ['', '.'], $raw)
                : $raw;
            $values[$key] = is_numeric($number) ? max(0.0, min(100000000.0, (float) $number)) : 0.0;
        }

        $values['years'] = max(1, min(40, (int) $values['years']));

        $items = collect($this->renoItems)
            ->map(fn (array $row) => ['label' => mb_substr(trim($row['label']), 0, 120), 'amount' => $this->toNumber($row['amount'])])
            ->filter(fn (array $row) => $row['label'] !== '' || $row['amount'] > 0)
            ->values()->all();

        if ($items !== []) {
            $values['renovation'] = array_sum(array_column($items, 'amount'));
            $values['renovation_items'] = $items;
        }

        return $values;
    }

    /**
     * A new federal state brings its transfer tax rate.
     */
    public function updatedInState(string $state): void
    {
        if (array_key_exists($state, RealEstate::TRANSFER_TAX)) {
            $this->in['transfer_tax_rate'] = rtrim(rtrim(number_format(RealEstate::TRANSFER_TAX[$state], 2, ',', ''), '0'), ',');
        }
    }

    public function save(): void
    {
        $this->validate(['title' => ['required', 'string', 'max:150']], attributes: ['title' => __('Name')]);
        $attributes = ['title' => $this->title, 'inputs' => $this->normalized()];
        $saved = $this->analysisId !== '' ? $this->findAnalysis((int) $this->analysisId) : null;

        if ($saved) {
            $saved->update($attributes);
        } else {
            $saved = RealEstateAnalysis::create($attributes + ['household_id' => $this->householdId, 'created_by' => Auth::id()]);
            $this->analysisId = (string) $saved->id;
        }

        $this->dispatch('toast', message: __('Calculation saved.'));
    }

    public function newAnalysis(): void
    {
        $this->redirectRoute('real-estate.analysis', navigate: true);
    }

    public function delete(): void
    {
        $this->findAnalysis((int) $this->analysisId)?->delete();
        $this->redirectRoute('real-estate.analysis', navigate: true);
    }

    public function with(): array
    {
        return [
            'result' => ProfitabilityAnalysis::calculate($this->normalized()),
            'saved' => RealEstateAnalysis::where('household_id', $this->householdId)->latest('updated_at')->get(['id', 'title', 'updated_at']),
            'properties' => $this->properties(),
            'states' => \App\Support\Life\PublicHolidays::states(),
            'checklist' => \App\Support\RealEstate\BuyingChecklist::groups(),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $euro = fn (?float $value, int $decimals = 0) => $value === null ? '–' : number_format($value, $decimals, ',', '.').' €';
    $pct = fn (?float $value) => $value === null ? '–' : number_format($value, 1, ',', '.').' %';
    $levels = [
        'green' => 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-900/40 dark:text-emerald-200 dark:ring-emerald-800',
        'yellow' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-900/40 dark:text-amber-200 dark:ring-amber-800',
        'red' => 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-900/40 dark:text-red-200 dark:ring-red-800',
        'unknown' => 'bg-gray-50 text-gray-700 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700',
    ];
    $field = function (string $key, string $label, string $suffix = '', string $hint = '') {
        return ['key' => $key, 'label' => $label, 'suffix' => $suffix, 'hint' => $hint];
    };
    $groups = [
        '🏷 '.__('Purchase') => [
            $field('purchase_price', __('Purchase price'), '€'), $field('living_area', __('Living area'), 'm²'),
            $field('transfer_tax_rate', __('Transfer tax'), '%'), $field('notary_rate', __('Notary & land registry'), '%'),
            $field('broker_rate', __('Broker'), '%'), $field('renovation', __('Renovation / modernisation'), '€'),
            $field('building_share', __('Building share of the price'), '%', __('The rest is land (not depreciable).')), $field('construction_year', __('Year of construction')),
        ],
        '🏦 '.__('Financing') => [
            $field('equity', __('Equity'), '€'), $field('interest_rate', __('Interest rate'), '%'),
            $field('repayment_rate', __('Initial repayment'), '%'), $field('fixed_years', __('Fixed interest period'), __('years')),
            $field('subsidy', __('Subsidy / grant'), '€', __('One-time grant (e.g. KfW/BAFA) – lowers the money needed.')),
            $field('kfw_loan', __('Subsidised loan'), '€', __('Part of the loan at a lower rate (e.g. KfW tranche).')), $field('kfw_rate', __('Rate of the subsidised loan'), '%'),
        ],
        '🔑 '.__('Rent & costs') => [
            $field('rent', __('Net cold rent per month'), '€'), $field('local_rent_per_sqm', __('Local rent index (Mietspiegel)'), '€/m²', __('Comparable rent from the city\'s rent index.')),
            $field('vacancy_rate', __('Vacancy / rent loss'), '%'), $field('non_allocable_costs', __('Non-allocable service charges per month'), '€'),
            $field('maintenance_per_sqm', __('Maintenance per m² and year'), '€'), $field('management', __('Management per month'), '€'),
        ],
        '🏛 '.__('Tax & outlook') => [
            $field('tax_rate', __('Personal marginal tax rate'), '%'), $field('rent_increase', __('Rent increase per year'), '%'),
            $field('value_increase', __('Value increase per year'), '%'), $field('years', __('Holding period / projection'), __('years'), __('Sale is assumed at the end of this period.')),
            $field('sale_costs_rate', __('Selling costs'), '%', __('Agent, notary and preparation on sale.')), $field('alternative_rate', __('Alternative return'), '%', __('e.g. a broad ETF, for comparison.')),
        ],
    ];
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('real-estate.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ __('Real estate') }}</a>
            <h2 class="w-full font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Profitability check') }}</h2>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-6xl space-y-5 px-4 sm:px-6 lg:px-8">
            <section class="flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                <label class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">{{ __('Name of the calculation') }}<input type="text" wire:model="title" class="{{ $input }}" placeholder="{{ __('e.g. 2-room flat Schwabing') }}"><x-input-error :messages="$errors->get('title')" /></label>
                <x-primary-button type="button" wire:click="save">{{ __('Save') }}</x-primary-button>
                @if ($analysisId !== '')
                    <x-secondary-button type="button" wire:click="newAnalysis">+ {{ __('New') }}</x-secondary-button>
                    <button type="button" wire:click="delete" wire:confirm="{{ __('Delete this calculation?') }}" class="text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                @endif
                @if ($saved->isNotEmpty() || $properties->isNotEmpty())
                    <div class="flex w-full flex-wrap gap-1.5 text-xs">
                        @foreach ($saved as $analysis)
                            <a href="{{ route('real-estate.analysis', ['id' => $analysis->id]) }}" wire:navigate wire:key="saved-{{ $analysis->id }}" @class(['rounded-full px-2.5 py-0.5', 'bg-indigo-600 text-white' => (string) $analysis->id === $analysisId, 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200' => (string) $analysis->id !== $analysisId])>🧮 {{ $analysis->title }}</a>
                        @endforeach
                        @foreach ($properties as $property)
                            <a href="{{ route('real-estate.analysis', ['real_estate' => $property->id]) }}" wire:navigate wire:key="property-{{ $property->id }}" class="rounded-full bg-gray-100 px-2.5 py-0.5 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200">🏠 {{ __('From :name', ['name' => $property->name]) }}</a>
                        @endforeach
                    </div>
                @endif
            </section>

            <div class="grid gap-5 lg:grid-cols-5">
                <div class="space-y-4 lg:col-span-2">
                    @foreach ($groups as $groupLabel => $fields)
                        <section class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $groupLabel }}</h3>
                            @if ($loop->first)
                                <label class="mt-2 block text-sm text-gray-700 dark:text-gray-300">{{ __('Federal state') }}
                                    <select wire:model.live="in.state" class="{{ $input }}">
                                        @foreach ($states as $code => $name)
                                            <option value="{{ $code }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endif
                            <div class="mt-2 grid grid-cols-2 gap-3">
                                @foreach ($fields as $item)
                                    <label class="text-sm text-gray-700 dark:text-gray-300">{{ $item['label'] }}
                                        <span class="relative block">
                                            <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="in.{{ $item['key'] }}" class="{{ $input }} {{ $item['suffix'] ? 'pe-12' : '' }}">
                                            @if ($item['suffix'])
                                                <span class="pointer-events-none absolute inset-y-0 end-3 mt-1 flex items-center text-xs text-gray-400">{{ $item['suffix'] }}</span>
                                            @endif
                                        </span>
                                        @if ($item['hint'])
                                            <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $item['hint'] }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                            @if ($loop->first)
                                <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-700">
                                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">🔨 {{ __('Renovation in detail') }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Add single items (roof, heating, bathroom …) – the total fills the renovation above.') }}</p>
                                    @foreach ($renoItems as $i => $row)
                                        <div class="mt-2 flex items-center gap-2" wire:key="reno-{{ $i }}">
                                            <input type="text" wire:model.live.debounce.500ms="renoItems.{{ $i }}.label" placeholder="{{ __('e.g. new heating') }}" class="min-w-0 flex-1 rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" aria-label="{{ __('Item') }}">
                                            <span class="relative w-28 shrink-0">
                                                <input type="text" inputmode="decimal" wire:model.live.debounce.500ms="renoItems.{{ $i }}.amount" class="w-full rounded-md border-gray-300 pe-7 text-right text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" aria-label="{{ __('Cost') }}">
                                                <span class="pointer-events-none absolute inset-y-0 end-2 flex items-center text-xs text-gray-400">€</span>
                                            </span>
                                            <button type="button" wire:click="removeRenoItem({{ $i }})" class="shrink-0 rounded-md px-2 py-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400" aria-label="{{ __('Remove') }}">×</button>
                                        </div>
                                    @endforeach
                                    <button type="button" wire:click="addRenoItem" class="mt-2 text-sm text-indigo-600 hover:underline dark:text-indigo-400">+ {{ __('Add item') }}</button>
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>

                <div class="space-y-4 lg:col-span-3">
                    <div class="rounded-2xl px-4 py-3 text-sm ring-1 {{ $levels[$result['verdict']['level']] }}" role="status">{{ $result['verdict']['text'] }}</div>

                    <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @foreach ([
                            [__('Return per year (IRR)'), $result['irr'] !== null ? $pct($result['irr']) : '–', ($result['irr'] ?? -1) >= 4.5],
                            [__('Operating cashflow'), $euro($result['operating_cashflow'] / 12).' '.__('/ month'), $result['operating_cashflow'] >= 0],
                            [__('Cashflow after tax'), $euro($result['cashflow_after_tax'] / 12).' '.__('/ month'), $result['cashflow_after_tax'] >= 0],
                            [__('Thereof repayment'), $euro($result['principal_first'] / 12).' '.__('/ month'), null],
                            [__('Gross yield'), $pct($result['gross_yield']), ($result['gross_yield'] ?? 0) >= 4.5],
                            [__('Net yield'), $pct($result['net_yield']), ($result['net_yield'] ?? 0) >= 3.5],
                            [__('Price factor'), $result['price_factor'] !== null ? number_format($result['price_factor'], 1, ',', '.').'×' : '–', ($result['price_factor'] ?? 99) <= 22],
                            [__('Return on equity'), $pct($result['equity_return']), ($result['equity_return'] ?? 0) >= 5],
                            [__('Loan rate'), $euro($result['monthly_rate']).' '.__('/ month'), null],
                            [__('Break-even rent'), $euro($result['break_even_rent']).' '.__('/ month'), null],
                        ] as [$label, $value, $good])
                            <div class="rounded-2xl bg-white p-3 shadow-xs dark:bg-gray-800">
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                                <p @class(['mt-1 font-semibold tabular-nums', 'text-emerald-700 dark:text-emerald-300' => $good === true, 'text-red-700 dark:text-red-300' => $good === false, 'text-gray-900 dark:text-gray-100' => $good === null])>{{ $value }}</p>
                            </div>
                        @endforeach
                    </section>

                    <section class="grid gap-4 sm:grid-cols-2">
                        <div class="rounded-2xl bg-white p-4 text-sm shadow-xs dark:bg-gray-800">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Total investment') }}</h3>
                            <dl class="mt-2 space-y-1">
                                @foreach ([[__('Purchase price'), $result['price']], [__('Transfer tax'), $result['transfer_tax']], [__('Notary & land registry'), $result['notary']], [__('Broker'), $result['broker']], [__('Renovation / modernisation'), $result['renovation']]] as [$label, $value])
                                    <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ $label }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($value) }}</dd></div>
                                @endforeach
                                <div class="flex justify-between gap-3 border-t border-gray-100 pt-1 font-semibold dark:border-gray-700"><dt>{{ __('Total') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['total']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Equity') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['equity']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Loan') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['loan']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Remaining debt after fixed interest') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['remaining_debt']) }}</dd></div>
                            </dl>
                        </div>
                        <div class="rounded-2xl bg-white p-4 text-sm shadow-xs dark:bg-gray-800">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('First year') }}</h3>
                            <dl class="mt-2 space-y-1">
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Rent after vacancy') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['effective_rent']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Running costs') }}</dt><dd class="whitespace-nowrap tabular-nums">−{{ $euro($result['costs']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Loan rate') }}</dt><dd class="whitespace-nowrap tabular-nums">−{{ $euro($result['monthly_rate'] * 12) }}</dd></div>
                                <div class="flex justify-between gap-3 border-t border-gray-100 pt-1 dark:border-gray-700"><dt>{{ __('Cashflow before tax') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['cashflow_pre_tax']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Depreciation (:rate % AfA)', ['rate' => number_format($result['afa_rate'], 1, ',', '.')]) }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['depreciation']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ $result['tax'] < 0 ? __('Tax saving') : __('Tax') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $result['tax'] < 0 ? '+'.$euro(-$result['tax']) : '−'.$euro($result['tax']) }}</dd></div>
                                <div class="flex justify-between gap-3 border-t border-gray-100 pt-1 font-semibold dark:border-gray-700"><dt>{{ __('Cashflow after tax') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['cashflow_after_tax']) }}</dd></div>
                                @if ($result['rent_per_sqm'] !== null)
                                    <div class="flex justify-between gap-3 pt-2"><dt class="text-gray-600 dark:text-gray-400">{{ __('Rent per m² (index)') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ number_format($result['rent_per_sqm'], 2, ',', '.') }} €@if ($result['rent_vs_index'] !== null) ({{ $result['rent_vs_index'] > 0 ? '+' : '' }}{{ $pct($result['rent_vs_index']) }})@endif</dd></div>
                                    <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Price per m²') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['price_per_sqm']) }}</dd></div>
                                @endif
                            </dl>
                        </div>
                    </section>

                    <section class="grid gap-4 sm:grid-cols-2">
                        <div class="rounded-2xl bg-white p-4 text-sm shadow-xs dark:bg-gray-800">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">🏁 {{ __('Sale after :years years', ['years' => (int) ($in['years'] ?? 0)]) }}</h3>
                            <dl class="mt-2 space-y-1">
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Estimated sale price') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['sale_price']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Selling costs') }}</dt><dd class="whitespace-nowrap tabular-nums">−{{ $euro($result['sale_costs']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Remaining debt') }}</dt><dd class="whitespace-nowrap tabular-nums">−{{ $euro($result['exit_debt']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ $result['speculation_period'] ? __('Speculation tax (within 10 years)') : __('Speculation tax (tax-free after 10 years)') }}</dt><dd class="whitespace-nowrap tabular-nums">−{{ $euro($result['speculation_tax']) }}</dd></div>
                                <div class="flex justify-between gap-3 border-t border-gray-100 pt-1 font-semibold dark:border-gray-700"><dt>{{ __('Net sale proceeds') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['net_sale_proceeds']) }}</dd></div>
                            </dl>
                        </div>
                        <div class="rounded-2xl bg-white p-4 text-sm shadow-xs dark:bg-gray-800">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">🎯 {{ __('Total return over :years years', ['years' => (int) ($in['years'] ?? 0)]) }}</h3>
                            <dl class="mt-2 space-y-1">
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Equity put in') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['equity']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Sum of cashflows after tax') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['total_cashflow']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Net sale proceeds') }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['net_sale_proceeds']) }}</dd></div>
                                <div class="flex justify-between gap-3 border-t border-gray-100 pt-1 font-semibold dark:border-gray-700"><dt>{{ __('Total profit after tax') }}</dt><dd @class(['whitespace-nowrap tabular-nums', 'text-emerald-700 dark:text-emerald-300' => $result['total_profit'] >= 0, 'text-red-700 dark:text-red-300' => $result['total_profit'] < 0])>{{ $euro($result['total_profit']) }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Return per year (IRR)') }}</dt><dd class="whitespace-nowrap font-semibold tabular-nums">{{ $result['irr'] !== null ? $pct($result['irr']) : '–' }}</dd></div>
                                <div class="flex justify-between gap-3"><dt class="text-gray-600 dark:text-gray-400">{{ __('Alternative (:rate %)', ['rate' => number_format((float) ($in['alternative_rate'] ?? 0), 1, ',', '.')]) }}</dt><dd class="whitespace-nowrap tabular-nums">{{ $euro($result['alternative_profit']) }}</dd></div>
                            </dl>
                        </div>
                    </section>

                    @if ($result['hints'] !== [])
                        <section class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">💡 {{ __('Points to check') }}</h3>
                            <ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-300">
                                @foreach ($result['hints'] as $hint)
                                    <li>{{ $hint }}</li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @php
                        $chartPoints = collect($result['projection']);
                    @endphp
                    @if ($chartPoints->count() > 1)
                        <section class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100">🏗 {{ __('Wealth build-up') }}</h3>
                                <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Property value and your equity in it; the gap is the remaining debt.') }}</p>
                                <x-line-chart
                                    :points="$chartPoints->map(fn ($row) => ['label' => __('Year :year', ['year' => $row['year']]), 'value' => $row['value'], 'compare' => $row['net_equity']])->all()"
                                    :value-label="__('Property value')"
                                    :compare-label="__('Equity in property')"
                                    :height="200" />
                            </div>
                            <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100">💶 {{ __('Rent & cashflow') }}</h3>
                                <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Yearly rent against the cashflow after tax per year.') }}</p>
                                <x-line-chart
                                    :points="$chartPoints->map(fn ($row) => ['label' => __('Year :year', ['year' => $row['year']]), 'value' => $row['rent'], 'compare' => $row['cashflow_after_tax']])->all()"
                                    :value-label="__('Rent per year')"
                                    :compare-label="__('Cashflow after tax')"
                                    :height="200" />
                            </div>
                        </section>
                    @endif

                    @php
                        $ownUse = $result['own_use'];
                    @endphp
                    @if ($ownUse['saved_rent_first'] > 0)
                        <section class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">⚖️ {{ __('Let out vs. live in it') }}</h3>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Over :years years – letting (rent, depreciation, tax) against living in it yourself (saved cold rent tax-free, sale free of speculation tax). Simplified comparison.', ['years' => (int) ($in['years'] ?? 0)]) }}</p>
                            <div class="mt-3 grid grid-cols-2 gap-3">
                                <div @class(['rounded-xl p-3 ring-1', 'bg-emerald-50 ring-emerald-200 dark:bg-emerald-900/40 dark:ring-emerald-800' => $ownUse['better'] === 'let', 'bg-gray-50 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700' => $ownUse['better'] !== 'let'])>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">🔑 {{ __('Let out') }}</p>
                                    <p class="mt-1 font-semibold tabular-nums {{ $ownUse['let_total'] >= 0 ? 'text-gray-900 dark:text-gray-100' : 'text-red-700 dark:text-red-300' }}">{{ $euro($ownUse['let_total']) }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Total profit after tax') }}</p>
                                </div>
                                <div @class(['rounded-xl p-3 ring-1', 'bg-emerald-50 ring-emerald-200 dark:bg-emerald-900/40 dark:ring-emerald-800' => $ownUse['better'] === 'own', 'bg-gray-50 ring-gray-200 dark:bg-gray-900/40 dark:ring-gray-700' => $ownUse['better'] !== 'own'])>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">🏡 {{ __('Live in it') }}</p>
                                    <p class="mt-1 font-semibold tabular-nums {{ $ownUse['own_total'] >= 0 ? 'text-gray-900 dark:text-gray-100' : 'text-red-700 dark:text-red-300' }}">{{ $euro($ownUse['own_total']) }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('incl. saved rent (:amount/year)', ['amount' => $euro($ownUse['saved_rent_first'])]) }}</p>
                                </div>
                            </div>
                            <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $ownUse['better'] === 'let' ? __('Letting out comes out :amount ahead here.', ['amount' => $euro($ownUse['difference'])]) : __('Living in it yourself comes out :amount ahead here.', ['amount' => $euro($ownUse['difference'])]) }}</p>
                        </section>
                    @endif

                    <section class="overflow-x-auto rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">📈 {{ __('Projection') }}</h3>
                        <table class="mt-2 w-full whitespace-nowrap text-right text-xs tabular-nums [&_td]:px-1 [&_th]:px-1">
                            <thead class="text-gray-500 dark:text-gray-400">
                                <tr><th class="py-1 text-left">{{ __('Year') }}</th><th>{{ __('Rent') }}</th><th>{{ __('Interest') }}</th><th>{{ __('Repayment') }}</th><th>{{ __('Remaining debt') }}</th><th>{{ __('Cashflow after tax') }}</th><th>{{ __('Equity in property') }}</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($result['projection'] as $row)
                                    <tr wire:key="year-{{ $row['year'] }}">
                                        <td class="py-1 text-left">{{ $row['year'] }}</td>
                                        <td>{{ $euro($row['rent']) }}</td>
                                        <td>{{ $euro($row['interest']) }}</td>
                                        <td>{{ $euro($row['principal']) }}</td>
                                        <td>{{ $euro($row['debt']) }}</td>
                                        <td @class(['text-emerald-700 dark:text-emerald-300' => $row['cashflow_after_tax'] >= 0, 'text-red-700 dark:text-red-300' => $row['cashflow_after_tax'] < 0])>{{ $euro($row['cashflow_after_tax']) }}</td>
                                        <td>{{ $euro($row['net_equity']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Simplified calculation without guarantee – interest after the fixed period, tax law and market developments can differ. Have a purchase checked by your tax adviser.') }}</p>
                    </section>

                    <section class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800" x-data="{ open: false }">
                        <button type="button" x-on:click="open = !open" class="flex w-full items-center justify-between gap-2 text-left">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">✅ {{ __('Before you buy: what to check') }}</h3>
                            <svg class="h-5 w-5 shrink-0 text-gray-400 transition-transform" x-bind:class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                        </button>
                        <div x-show="open" x-cloak x-transition class="mt-3 space-y-4">
                            @foreach ($checklist as $group)
                                <div wire:key="check-{{ $loop->index }}">
                                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $group['icon'] }} {{ $group['title'] }}</p>
                                    <ul class="mt-1 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                                        @foreach ($group['items'] as $item)
                                            <li class="flex gap-2"><span class="mt-0.5 text-gray-400" aria-hidden="true">☐</span><span>{{ $item }}</span></li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Guidance, not legal advice – a surveyor, tax adviser and the notary stay the authority.') }}</p>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</div>
