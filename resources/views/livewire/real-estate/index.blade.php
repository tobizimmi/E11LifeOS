<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\RealEstate;
use App\Support\RealEstate\RealEstatePlan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public bool $creating = false;

    /**
     * @var array{name: string, usage: string, state: string, purchase_price: string, private: bool}
     */
    public array $form = ['name' => '', 'usage' => RealEstate::OWNER, 'state' => 'NW', 'purchase_price' => '', 'private' => false];

    public function mount(): void
    {
        $this->resolveHousehold();
    }

    public function create(): void
    {
        $validated = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.usage' => ['required', Rule::in(RealEstate::USAGES)],
            'form.state' => ['required', Rule::in(array_keys(RealEstate::TRANSFER_TAX))],
            'form.purchase_price' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'form.private' => ['boolean'],
        ], attributes: ['form.name' => __('Name'), 'form.purchase_price' => __('Purchase price')])['form'];

        $realEstate = RealEstate::create([
            'household_id' => $this->householdId,
            'created_by' => Auth::id(),
            'visibility' => $validated['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'name' => trim($validated['name']),
            'usage' => $validated['usage'],
            'rental_share' => $validated['usage'] === RealEstate::MIXED ? 50 : 0,
            'state' => $validated['state'],
            'purchase_price' => $validated['purchase_price'] !== '' ? (float) $validated['purchase_price'] : null,
        ]);

        $this->redirectRoute('real-estate.show', ['realEstate' => $realEstate->id], navigate: true);
    }

    public function with(): array
    {
        return [
            'realEstates' => RealEstate::query()->visibleTo(Auth::user(), (int) $this->householdId)->orderBy('name')->get()
                ->map(fn (RealEstate $realEstate) => ['model' => $realEstate, 'summary' => RealEstatePlan::summary($realEstate)]),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 0, ',', '.').' €';
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🏠 {{ __('Real estate') }}</h2>
            <x-primary-button type="button" wire:click="$toggle('creating')">+ {{ __('New real estate') }}</x-primary-button>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @if ($creating || $realEstates->isEmpty())
                <form wire:submit="create" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-5 dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 sm:col-span-2 lg:col-span-5 dark:text-gray-100">{{ __('Plan a property') }}</h3>
                    <label class="text-sm text-gray-700 lg:col-span-2 dark:text-gray-300">{{ __('Name') }}
                        <x-text-input wire:model="form.name" class="mt-1 block w-full" :placeholder="__('e.g. House in Lindenstraße')" />
                        <x-input-error :messages="$errors->get('form.name')" />
                    </label>
                    <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Usage') }}
                        <select wire:model="form.usage" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            @foreach (\App\Models\RealEstate::usageLabels() as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('State') }}
                        <select wire:model="form.state" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            @foreach (\App\Models\RealEstate::stateNames() as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Purchase price') }} (€)
                        <x-text-input wire:model="form.purchase_price" type="number" step="1000" min="0" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('form.purchase_price')" />
                    </label>
                    <div class="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-5">
                        <x-primary-button>{{ __('Create') }}</x-primary-button>
                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                            <input type="checkbox" wire:model="form.private" class="rounded border-gray-300 text-indigo-600"> 🔒 {{ __('Only for me') }}
                        </label>
                    </div>
                </form>
            @endif

            @if ($realEstates->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">🏡</p>
                    <p class="mx-auto mt-2 max-w-xl text-sm text-gray-600 dark:text-gray-400">{{ __('Plan the purchase and renovation of a property in one place: purchase costs, financing, budget by trade, invoices, subsidies and the tax effect – for a home you live in or a property you let.') }}</p>
                </div>
            @else
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($realEstates as $row)
                        @php
                            $model = $row['model'];
                            $summary = $row['summary'];
                        @endphp
                        <a href="{{ route('real-estate.show', $model) }}" wire:navigate wire:key="real-estate-{{ $model->id }}" class="block rounded-2xl bg-white p-5 shadow-sm ring-1 ring-transparent hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="truncate text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $model->name }} @if ($model->isPrivate())<span title="{{ __('Only for me') }}">🔒</span>@endif</h3>
                                    <p class="truncate text-sm text-gray-500 dark:text-gray-400">{{ $model->address() ?: \App\Models\RealEstate::stateNames()[$model->state] ?? '' }} · {{ \App\Models\RealEstate::usageLabels()[$model->usage] }}</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ \App\Models\RealEstate::statusLabels()[$model->status] ?? $model->status }}</span>
                            </div>
                            <dl class="mt-4 grid grid-cols-3 gap-3 text-sm">
                                <div>
                                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Total costs') }}</dt>
                                    <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $euro($summary['expected_total']) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Paid') }}</dt>
                                    <dd class="font-semibold text-gray-900 dark:text-gray-100">{{ $euro($summary['paid_total']) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $summary['funding']['gap'] > 0 ? __('Funding gap') : __('Funding buffer') }}</dt>
                                    <dd @class(['font-semibold', 'text-red-600 dark:text-red-400' => $summary['funding']['gap'] > 0, 'text-green-700 dark:text-green-400' => $summary['funding']['gap'] <= 0])>{{ $euro(abs($summary['funding']['gap'])) }}</dd>
                                </div>
                            </dl>
                            @if ($summary['progress'] !== null)
                                <div class="mt-4">
                                    <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400"><span>{{ __('Renovation progress') }}</span><span>{{ $summary['progress'] }} %</span></div>
                                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ $summary['progress'] }}" aria-valuemin="0" aria-valuemax="100">
                                        <div class="h-full rounded-full bg-indigo-500" style="width: {{ $summary['progress'] }}%"></div>
                                    </div>
                                </div>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
