<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\Vehicle;
use App\Support\Life\VehicleStats;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The household's vehicles with their next dates and monthly costs.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public bool $showForm = false;

    /**
     * @var array{name: string, make_model: string, plate: string, fuel: string, odometer_start: string, private: bool}
     */
    public array $vehicle = ['name' => '', 'make_model' => '', 'plate' => '', 'fuel' => 'petrol', 'odometer_start' => '', 'private' => false];

    public function mount(): void
    {
        $this->resolveHousehold();
    }

    public function create(): void
    {
        $data = $this->validate([
            'vehicle.name' => ['required', 'string', 'max:120'],
            'vehicle.make_model' => ['nullable', 'string', 'max:120'],
            'vehicle.plate' => ['nullable', 'string', 'max:20'],
            'vehicle.fuel' => ['required', 'in:'.implode(',', Vehicle::FUELS)],
            'vehicle.odometer_start' => ['nullable', 'integer', 'min:0', 'max:5000000'],
        ], attributes: ['vehicle.name' => __('Name')])['vehicle'];

        $created = Vehicle::create([
            'household_id' => $this->householdId,
            'created_by' => Auth::id(),
            'visibility' => $this->vehicle['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'name' => $data['name'],
            'make_model' => $data['make_model'] ?: null,
            'plate' => $data['plate'] ? mb_strtoupper($data['plate']) : null,
            'fuel' => $data['fuel'],
            'odometer_start' => $data['odometer_start'] !== '' ? (int) $data['odometer_start'] : null,
        ]);

        $this->redirectRoute('vehicles.show', ['vehicle' => $created->id, 'tab' => 'details'], navigate: true);
    }

    public function with(): array
    {
        $vehicles = Vehicle::query()->visibleTo(Auth::user(), (int) $this->householdId)->with('logs')->orderBy('name')->get();

        return [
            'vehicles' => $vehicles->map(fn (Vehicle $vehicle) => ['vehicle' => $vehicle, 'stats' => VehicleStats::for($vehicle)]),
            'fuels' => Vehicle::fuelLabels(),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
@endphp

<div x-on:page-primary.window="$wire.set('showForm', true)">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Vehicles') }}</h2>
            <x-primary-button type="button" x-on:click="$dispatch('page-primary')" class="shrink-0">+ {{ __('Add vehicle') }}</x-primary-button>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">

            @if ($showForm)
                <form wire:submit="create" class="grid gap-3 rounded-2xl bg-white p-5 shadow-xs sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="vehicle.name" class="{{ $input }}" placeholder="{{ __('e.g. Family car') }}" autofocus><x-input-error :messages="$errors->get('vehicle.name')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Make & model') }}<input type="text" wire:model="vehicle.make_model" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Licence plate') }}<input type="text" wire:model="vehicle.plate" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Drive') }}
                        <select wire:model="vehicle.fuel" class="{{ $input }}">
                            @foreach ($fuels as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Mileage today (km)') }}<input type="number" min="0" wire:model="vehicle.odometer_start" class="{{ $input }}"><x-input-error :messages="$errors->get('vehicle.odometer_start')" /></label>
                    <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-6 dark:text-gray-300"><input type="checkbox" wire:model="vehicle.private" class="rounded-sm border-gray-300 text-indigo-600"> {{ __('Only visible to me') }}</label>
                    <div class="flex gap-2 sm:col-span-6">
                        <x-primary-button>{{ __('Create') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-secondary-button>
                    </div>
                </form>
            @endif

            @forelse ($vehicles as $row)
                @php
                    $car = $row['vehicle'];
                    $stats = $row['stats'];
                @endphp
                <a href="{{ route('vehicles.show', $car) }}" wire:navigate wire:key="vehicle-{{ $car->id }}" class="block rounded-2xl bg-white p-5 shadow-xs transition hover:ring-1 hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $car->isElectric() ? '🔌' : '🚗' }} {{ $car->name }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ collect([$car->make_model, $car->plate, $stats['odometer'] ? number_format($stats['odometer'], 0, ',', '.').' km' : null])->filter()->implode(' · ') }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Costs per month') }}</p>
                            <p class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($stats['monthly']) }}</p>
                        </div>
                    </div>
                    @if ($stats['next'] !== [])
                        <ul class="mt-3 flex flex-wrap gap-2 text-xs">
                            @foreach ($stats['next'] as $date)
                                <li @class(['rounded-full px-2 py-0.5', 'bg-red-50 text-red-800 dark:bg-red-900/40 dark:text-red-200' => $date['overdue'], 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => ! $date['overdue']])>{{ $date['label'] }}: {{ \Illuminate\Support\Carbon::parse($date['date'])->format('d.m.Y') }}</li>
                            @endforeach
                        </ul>
                    @endif
                </a>
            @empty
                <section class="rounded-2xl bg-white p-6 text-center shadow-xs dark:bg-gray-800">
                    <p class="text-gray-700 dark:text-gray-200">{{ __('No vehicles yet.') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Add your car: inspection and service are reminded, fuel and workshop costs add up to the costs per kilometre, and a logbook collects business trips.') }}</p>
                </section>
            @endforelse
        </div>
    </div>
</div>
