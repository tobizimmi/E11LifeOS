<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\RealEstate;
use App\Models\RealEstateLoan;
use App\Models\RealEstateValuation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Value development of a property: purchase price and later valuations, gain, value per m²
 * and equity (value minus remaining loans).
 */
new class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $realEstateId;

    /**
     * @var array{valued_on: string, value: string, source: string, notes: string}
     */
    public array $valuation = [];

    public function mount(int $realEstateId): void
    {
        $this->resolveHousehold();
        $this->realEstateId = $this->realEstate($realEstateId)->id;
        $this->resetValuation();
    }

    private function realEstate(?int $id = null): RealEstate
    {
        return RealEstate::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($id ?? $this->realEstateId) ?? abort(404);
    }

    private function resetValuation(): void
    {
        $this->valuation = ['valued_on' => today()->toDateString(), 'value' => '', 'source' => 'estimate', 'notes' => ''];
    }

    public function addValuation(): void
    {
        $this->valuation['value'] = str_replace(['.', ' ', '€', ','], ['', '', '', '.'], trim((string) $this->valuation['value']));
        $data = $this->validate([
            'valuation.valued_on' => ['required', 'date', 'before_or_equal:'.today()->addYear()->toDateString()],
            'valuation.value' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'valuation.source' => ['required', Rule::in(RealEstateValuation::SOURCES)],
            'valuation.notes' => ['nullable', 'string', 'max:255'],
        ], attributes: ['valuation.value' => __('Value'), 'valuation.valued_on' => __('Date')])['valuation'];

        $this->realEstate()->valuations()->create($data + ['notes' => null]);
        $this->resetValuation();
    }

    public function deleteValuation(int $id): void
    {
        $this->realEstate()->valuations()->whereKey($id)->delete();
    }

    public function with(): array
    {
        $realEstate = $this->realEstate()->load('valuations', 'loans');
        $points = collect();

        if ($realEstate->purchase_price && $realEstate->purchase_date) {
            $points->push(['date' => $realEstate->purchase_date, 'value' => (float) $realEstate->purchase_price, 'label' => __('Purchase')]);
        }

        foreach ($realEstate->valuations as $valuation) {
            $points->push(['date' => $valuation->valued_on, 'value' => $valuation->value, 'label' => RealEstateValuation::sourceLabels()[$valuation->source] ?? $valuation->source]);
        }

        $points = $points->sortBy(fn (array $point) => $point['date']->toDateString())->values();
        $current = $points->last()['value'] ?? null;
        $debt = (float) $realEstate->loans->sum(function (RealEstateLoan $loan) {
            $start = $loan->start_on ?? $loan->realEstate?->purchase_date;

            return $start && $start->isPast() ? $loan->after((int) $start->diffInMonths(now()))['balance'] : (float) $loan->amount;
        });
        $years = $realEstate->purchase_date && $current ? max(0.0, $realEstate->purchase_date->diffInDays(now()) / 365.25) : 0;

        return [
            'realEstate' => $realEstate,
            'chart' => $points->map(fn (array $point) => ['label' => $point['date']->format('m/Y'), 'value' => $point['value']])->all(),
            'current' => $current,
            'gain' => $current !== null && $realEstate->purchase_price ? $current - $realEstate->purchase_price : null,
            'yearlyGrowth' => $current !== null && $realEstate->purchase_price && $years >= 1 ? (($current / $realEstate->purchase_price) ** (1 / $years) - 1) * 100 : null,
            'perSquareMeter' => $current !== null && $realEstate->living_area ? $current / $realEstate->living_area : null,
            'debt' => $debt,
            'equity' => $current !== null ? $current - $debt : null,
            'sources' => RealEstateValuation::sourceLabels(),
        ];
    }
}; ?>

@php
    $euro = fn ($value, $decimals = 0) => number_format((float) $value, $decimals, ',', '.').' €';
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
@endphp

<div class="space-y-5">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Current value') }}</p>
            <p class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $current !== null ? $euro($current) : '–' }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Change since purchase') }}</p>
            <p @class(['text-xl font-semibold', 'text-green-700 dark:text-green-400' => ($gain ?? 0) >= 0, 'text-red-700 dark:text-red-400' => ($gain ?? 0) < 0])>
                {{ $gain !== null ? ($gain >= 0 ? '+' : '').$euro($gain) : '–' }}
            </p>
            @if ($yearlyGrowth !== null)
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __(':rate % per year', ['rate' => number_format($yearlyGrowth, 1, ',', '.')]) }}</p>
            @endif
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Value per m²') }}</p>
            <p class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $perSquareMeter !== null ? $euro($perSquareMeter) : '–' }}</p>
        </div>
        <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Equity in the property') }}</p>
            <p class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $equity !== null ? $euro($equity) : '–' }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Remaining loans: :amount', ['amount' => $euro($debt)]) }}</p>
        </div>
    </div>

    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="value-heading">
        <h3 id="value-heading" class="font-semibold text-gray-900 dark:text-gray-100">📈 {{ __('Value development') }}</h3>
        @if (count($chart) >= 2)
            <x-line-chart :points="$chart" :value-label="__('Value')" class="mt-3" wire:key="value-chart-{{ count($chart) }}" />
        @else
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Add a valuation to see the development since the purchase.') }}</p>
        @endif

        <form wire:submit="addValuation" class="mt-4 grid gap-3 sm:grid-cols-5 sm:items-end">
            <div>
                <x-input-label for="valuation-date" :value="__('Date')" />
                <input id="valuation-date" type="date" wire:model="valuation.valued_on" class="{{ $input }}">
                <x-input-error :messages="$errors->get('valuation.valued_on')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="valuation-value" :value="__('Value (€)')" />
                <input id="valuation-value" inputmode="decimal" wire:model="valuation.value" class="{{ $input }}">
                <x-input-error :messages="$errors->get('valuation.value')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="valuation-source" :value="__('Source')" />
                <select id="valuation-source" wire:model="valuation.source" class="{{ $input }}">
                    @foreach ($sources as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <x-input-label for="valuation-notes" :value="__('Note')" />
                <input id="valuation-notes" wire:model="valuation.notes" class="{{ $input }}">
            </div>
            <x-primary-button class="justify-center">{{ __('Add') }}</x-primary-button>
        </form>

        @if ($realEstate->valuations->isNotEmpty())
            <ul class="mt-4 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @foreach ($realEstate->valuations->sortByDesc('valued_on') as $valuation)
                    <li wire:key="valuation-{{ $valuation->id }}" class="flex items-center justify-between gap-3 py-2">
                        <span class="text-gray-700 dark:text-gray-300">{{ $valuation->valued_on->format('d.m.Y') }} · {{ $sources[$valuation->source] ?? $valuation->source }}@if ($valuation->notes) · {{ $valuation->notes }}@endif</span>
                        <span class="flex items-center gap-3">
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $euro($valuation->value) }}</span>
                            <button type="button" wire:click="deleteValuation({{ $valuation->id }})" wire:confirm="{{ __('Delete this valuation?') }}" class="text-red-600 dark:text-red-400" aria-label="{{ __('Delete') }}">✕</button>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
