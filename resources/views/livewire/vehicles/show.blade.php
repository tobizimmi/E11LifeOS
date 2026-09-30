<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\Vehicle;
use App\Models\VehicleLog;
use App\Support\Life\VehicleStats;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * One vehicle: costs and consumption, the log (fuel, workshop, tyres, inspection), the logbook of
 * trips and the vehicle's details with inspection and service dates.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $vehicleId;

    /** log, trips or details */
    #[Url(except: 'log')]
    public string $tab = 'log';

    /**
     * @var array{kind: string, date: string, odometer: string, amount: string, quantity: string, distance_km: string, purpose: string, destination: string, notes: string}
     */
    public array $entry = [];

    /**
     * @var array<string, mixed>
     */
    public array $details = [];

    public function mount(Vehicle $vehicle): void
    {
        $this->resolveHousehold();
        $this->vehicleId = $this->findVehicle($vehicle->id)->id;

        if (! in_array($this->tab, ['log', 'trips', 'details'], true)) {
            $this->tab = 'log';
        }

        $this->resetEntry($this->tab === 'trips' ? 'trip' : 'fuel');
        $this->fillDetails();
    }

    private function findVehicle(?int $id = null): Vehicle
    {
        return Vehicle::query()->visibleTo($this->currentUser(), (int) $this->householdId)->find($id ?? $this->vehicleId) ?? abort(404);
    }

    public function updatedTab(string $tab): void
    {
        $this->resetEntry($tab === 'trips' ? 'trip' : 'fuel');
    }

    private function resetEntry(string $kind): void
    {
        $this->entry = ['kind' => $kind, 'date' => today()->toDateString(), 'odometer' => '', 'amount' => '', 'quantity' => '', 'distance_km' => '', 'purpose' => 'business', 'destination' => '', 'notes' => ''];
        $this->resetValidation();
    }

    private function fillDetails(): void
    {
        $vehicle = $this->findVehicle();
        $this->details = [
            'name' => $vehicle->name,
            'make_model' => (string) $vehicle->make_model,
            'plate' => (string) $vehicle->plate,
            'fuel' => $vehicle->fuel,
            'first_registration' => (string) $vehicle->first_registration?->toDateString(),
            'purchase_date' => (string) $vehicle->purchase_date?->toDateString(),
            'purchase_price' => $vehicle->purchase_price !== null ? (string) $vehicle->purchase_price : '',
            'current_value' => $vehicle->current_value !== null ? (string) $vehicle->current_value : '',
            'odometer_start' => $vehicle->odometer_start !== null ? (string) $vehicle->odometer_start : '',
            'inspection_due' => (string) $vehicle->inspection_due?->toDateString(),
            'service_due' => (string) $vehicle->service_due?->toDateString(),
            'service_due_km' => $vehicle->service_due_km !== null ? (string) $vehicle->service_due_km : '',
            'tyre_reminders' => $vehicle->tyre_reminders,
            'tax_yearly' => $vehicle->tax_yearly !== null ? (string) $vehicle->tax_yearly : '',
            'insurance_yearly' => $vehicle->insurance_yearly !== null ? (string) $vehicle->insurance_yearly : '',
            'notes' => (string) $vehicle->notes,
            'private' => $vehicle->visibility === Item::VISIBILITY_PRIVATE,
        ];
    }

    private static function number(string $value): string
    {
        $value = trim(str_replace(['€', ' '], '', $value));

        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            return str_replace('.', '', $value);
        }

        return str_contains($value, ',') ? str_replace(',', '.', str_replace('.', '', $value)) : $value;
    }

    public function addEntry(): void
    {
        foreach (['amount', 'quantity'] as $field) {
            $this->entry[$field] = self::number((string) $this->entry[$field]);
        }

        $isTrip = $this->entry['kind'] === 'trip';
        $data = $this->validate([
            'entry.kind' => ['required', Rule::in(VehicleLog::KINDS)],
            'entry.date' => ['required', 'date'],
            'entry.odometer' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'entry.amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'entry.quantity' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'entry.distance_km' => [$isTrip ? 'required' : 'nullable', 'integer', 'min:1', 'max:20000'],
            'entry.purpose' => [$isTrip ? 'required' : 'nullable', Rule::in(VehicleLog::PURPOSES)],
            'entry.destination' => ['nullable', 'string', 'max:255'],
            'entry.notes' => ['nullable', 'string', 'max:1000'],
        ], attributes: ['entry.distance_km' => __('Distance (km)'), 'entry.amount' => __('Amount (€)')])['entry'];

        $this->findVehicle()->logs()->create([
            'created_by' => Auth::id(),
            'kind' => $data['kind'],
            'date' => $data['date'],
            'odometer' => $data['odometer'] !== '' ? (int) $data['odometer'] : null,
            'amount' => $isTrip || $data['amount'] === '' ? null : $data['amount'],
            'quantity' => $data['kind'] === 'fuel' && $data['quantity'] !== '' ? $data['quantity'] : null,
            'distance_km' => $isTrip ? (int) $data['distance_km'] : null,
            'purpose' => $isTrip ? $data['purpose'] : null,
            'destination' => $data['destination'] ?: null,
            'notes' => $data['notes'] ?: null,
        ]);

        $this->resetEntry($data['kind']);
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function deleteEntry(int $id): void
    {
        $this->findVehicle()->logs()->whereKey($id)->delete();
    }

    public function saveDetails(): void
    {
        foreach (['purchase_price', 'current_value', 'tax_yearly', 'insurance_yearly'] as $field) {
            $this->details[$field] = self::number((string) $this->details[$field]);
        }

        $data = $this->validate([
            'details.name' => ['required', 'string', 'max:120'],
            'details.make_model' => ['nullable', 'string', 'max:120'],
            'details.plate' => ['nullable', 'string', 'max:20'],
            'details.fuel' => ['required', Rule::in(Vehicle::FUELS)],
            'details.first_registration' => ['nullable', 'date'],
            'details.purchase_date' => ['nullable', 'date'],
            'details.purchase_price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'details.current_value' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'details.odometer_start' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'details.inspection_due' => ['nullable', 'date'],
            'details.service_due' => ['nullable', 'date'],
            'details.service_due_km' => ['nullable', 'integer', 'min:0', 'max:5000000'],
            'details.tyre_reminders' => ['boolean'],
            'details.tax_yearly' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'details.insurance_yearly' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'details.notes' => ['nullable', 'string', 'max:3000'],
        ], attributes: ['details.name' => __('Name')])['details'];

        $attributes = collect($data)->map(fn ($value) => $value === '' ? null : $value)->all();
        $attributes['plate'] = $attributes['plate'] ? mb_strtoupper($attributes['plate']) : null;
        $vehicle = $this->findVehicle();

        if ($vehicle->created_by === Auth::id()) {
            $attributes['visibility'] = $this->details['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;
        }

        $vehicle->update($attributes);
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function deleteVehicle(): void
    {
        $this->findVehicle()->delete();
        $this->redirectRoute('vehicles.index', navigate: true);
    }

    public function with(): array
    {
        $vehicle = $this->findVehicle()->load('logs');

        return [
            'vehicle' => $vehicle,
            'stats' => VehicleStats::for($vehicle),
            'kinds' => VehicleLog::kindLabels($vehicle->isElectric()),
            'purposes' => VehicleLog::purposeLabels(),
            'fuels' => Vehicle::fuelLabels(),
            'logs' => $vehicle->logs->where('kind', '!=', 'trip')->values(),
            'trips' => $vehicle->logs->where('kind', 'trip')->values(),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
    $km = fn (?int $value) => $value !== null ? number_format($value, 0, ',', '.').' km' : '–';
    $tabClass = 'flex-1 rounded-lg px-3 py-2 text-sm font-medium transition pointer-coarse:min-h-11';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $vehicle->name }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <a href="{{ route('vehicles.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ __('All vehicles') }}</a>

            <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Mileage') }}</p>
                    <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $km($stats['odometer']) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Costs per month') }}</p>
                    <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($stats['monthly']) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Costs per km') }}</p>
                    <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $stats['per_km'] !== null ? $euro($stats['per_km']) : '–' }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Consumption') }}</p>
                    <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $stats['consumption'] !== null ? number_format($stats['consumption'], 1, ',', '.').' '.$stats['unit'].'/100 km' : '–' }}</p>
                </div>
            </section>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Last 12 months: :km driven, :costs costs including tax and insurance.', ['km' => $km($stats['km_12m']), 'costs' => $euro($stats['costs_12m'])]) }}</p>

            @if ($stats['next'] !== [])
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">📅 {{ __('Next dates') }}</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($stats['next'] as $date)
                            <li class="flex justify-between gap-3">
                                <span class="text-gray-700 dark:text-gray-200">{{ $date['label'] }}</span>
                                <span @class(['tabular-nums', 'font-medium text-red-700 dark:text-red-300' => $date['overdue'], 'text-gray-600 dark:text-gray-300' => ! $date['overdue']])>{{ $date['overdue'] ? __('overdue since :date', ['date' => \Illuminate\Support\Carbon::parse($date['date'])->format('d.m.Y')]) : \Illuminate\Support\Carbon::parse($date['date'])->format('d.m.Y') }}</span>
                            </li>
                        @endforeach
                        @if ($vehicle->service_due_km)
                            <li class="flex justify-between gap-3">
                                <span class="text-gray-700 dark:text-gray-200">{{ __('Service at') }}</span>
                                <span @class(['tabular-nums', 'font-medium text-red-700 dark:text-red-300' => $stats['odometer'] && $stats['odometer'] >= $vehicle->service_due_km, 'text-gray-600 dark:text-gray-300' => ! ($stats['odometer'] && $stats['odometer'] >= $vehicle->service_due_km)])>{{ $km($vehicle->service_due_km) }}</span>
                            </li>
                        @endif
                    </ul>
                </section>
            @endif

            <nav class="flex gap-1 rounded-xl bg-gray-100 p-1 dark:bg-gray-800" aria-label="{{ __('Sections') }}">
                @foreach (['log' => '🧾 '.__('Log'), 'trips' => '🗺️ '.__('Logbook'), 'details' => '⚙️ '.__('Details')] as $key => $label)
                    <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([$tabClass, 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100' => $tab === $key, 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100' => $tab !== $key]) aria-pressed="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </nav>

            @if ($tab === 'details')
                <form wire:submit="saveDetails" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="details.name" class="{{ $input }}"><x-input-error :messages="$errors->get('details.name')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Make & model') }}<input type="text" wire:model="details.make_model" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Licence plate') }}<input type="text" wire:model="details.plate" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Drive') }}
                        <select wire:model="details.fuel" class="{{ $input }}">
                            @foreach ($fuels as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('First registration') }}<input type="date" wire:model="details.first_registration" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Next inspection (TÜV)') }}<input type="date" wire:model="details.inspection_due" class="{{ $input }}"><x-input-error :messages="$errors->get('details.inspection_due')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Next service') }}<input type="date" wire:model="details.service_due" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('… or at km') }}<input type="number" min="0" wire:model="details.service_due_km" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Vehicle tax per year (€)') }}<input type="text" inputmode="decimal" wire:model="details.tax_yearly" class="{{ $input }}"><x-input-error :messages="$errors->get('details.tax_yearly')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Insurance per year (€)') }}<input type="text" inputmode="decimal" wire:model="details.insurance_yearly" class="{{ $input }}"><x-input-error :messages="$errors->get('details.insurance_yearly')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Mileage at start (km)') }}<input type="number" min="0" wire:model="details.odometer_start" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Purchased on') }}<input type="date" wire:model="details.purchase_date" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Purchase price (€)') }}<input type="text" inputmode="decimal" wire:model="details.purchase_price" class="{{ $input }}"><x-input-error :messages="$errors->get('details.purchase_price')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Current value (€)') }}<input type="text" inputmode="decimal" wire:model="details.current_value" class="{{ $input }}"><x-input-error :messages="$errors->get('details.current_value')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('Notes') }}<textarea wire:model="details.notes" rows="3" class="{{ $input }}"></textarea></label>
                    <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-3 dark:text-gray-300"><input type="checkbox" wire:model="details.tyre_reminders" class="rounded border-gray-300 text-indigo-600"> {{ __('Remind me of tyre changes (O to O)') }}</label>
                    @if ($vehicle->created_by === auth()->id())
                        <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-3 dark:text-gray-300"><input type="checkbox" wire:model="details.private" class="rounded border-gray-300 text-indigo-600"> {{ __('Only visible to me') }}</label>
                    @endif
                    <div class="flex items-center gap-2 sm:col-span-6">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <button type="button" wire:click="deleteVehicle" wire:confirm="{{ __('Delete this vehicle with its log?') }}" class="ms-auto text-sm text-red-600 hover:underline">{{ __('Delete vehicle') }}</button>
                    </div>
                </form>
            @else
                <form wire:submit="addEntry" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    @if ($tab === 'log')
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Type') }}
                            <select wire:model.live="entry.kind" class="{{ $input }}">
                                @foreach ($kinds as $key => $kind)
                                    @if ($key !== 'trip')
                                        <option value="{{ $key }}">{{ $kind['icon'] }} {{ $kind['label'] }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Date') }}<input type="date" wire:model="entry.date" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.date')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Mileage (km)') }}<input type="number" min="0" wire:model="entry.odometer" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.odometer')" /></label>
                    @if ($tab === 'trips')
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Distance (km)') }}<input type="number" min="1" wire:model="entry.distance_km" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.distance_km')" /></label>
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Trip purpose') }}
                            <select wire:model="entry.purpose" class="{{ $input }}">
                                @foreach ($purposes as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm text-gray-700 sm:col-span-4 dark:text-gray-300">{{ __('Destination / occasion') }}<input type="text" wire:model="entry.destination" class="{{ $input }}"></label>
                    @else
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Amount (€)') }}<input type="text" inputmode="decimal" wire:model="entry.amount" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.amount')" /></label>
                        @if ($entry['kind'] === 'fuel')
                            <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ $vehicle->isElectric() ? __('Charged (kWh)') : __('Litres') }}<input type="text" inputmode="decimal" wire:model="entry.quantity" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.quantity')" /></label>
                        @endif
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Note') }}<input type="text" wire:model="entry.notes" class="{{ $input }}"></label>
                    @endif
                    <div class="sm:col-span-6"><x-primary-button>{{ __('Add') }}</x-primary-button></div>
                </form>

                @if ($tab === 'trips')
                    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('This year') }}</h3>
                        <ul class="mt-2 grid gap-2 text-sm sm:grid-cols-3">
                            @foreach ($purposes as $key => $label)
                                <li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-700/50"><span class="text-gray-500 dark:text-gray-400">{{ $label }}</span><br><span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $km($stats['trips'][$key] ?? 0) }}</span></li>
                            @endforeach
                        </ul>
                        @if ($stats['business_allowance'] > 0)
                            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">💼 {{ __('Business trips at :rate per km: :amount deductible as travel costs.', ['rate' => $euro(\App\Support\Life\VehicleStats::BUSINESS_RATE), 'amount' => $euro($stats['business_allowance'])]) }}</p>
                        @endif
                    </section>
                @endif

                <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($tab === 'trips' ? $trips : $logs as $log)
                            <li wire:key="log-{{ $log->id }}" class="flex items-center gap-3 px-5 py-3 text-sm">
                                <span aria-hidden="true">{{ $kinds[$log->kind]['icon'] ?? '•' }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-gray-900 dark:text-gray-100">{{ $log->kind === 'trip' ? ($purposes[$log->purpose] ?? '').($log->destination ? ' · '.$log->destination : '') : ($kinds[$log->kind]['label'] ?? $log->kind).($log->notes ? ' · '.$log->notes : '') }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $log->date->format('d.m.Y') }}@if ($log->odometer) · {{ $km($log->odometer) }}@endif @if ($log->quantity) · {{ number_format($log->quantity, 2, ',', '.') }} {{ $stats['unit'] }}@endif</p>
                                </div>
                                <span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $log->kind === 'trip' ? $km($log->distance_km) : ($log->amount !== null ? $euro($log->amount) : '') }}</span>
                                <button type="button" wire:click="deleteEntry({{ $log->id }})" wire:confirm="{{ __('Delete this entry?') }}" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                            </li>
                        @empty
                            <li class="px-5 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ $tab === 'trips' ? __('No trips yet.') : __('No entries yet – add the first refuelling with the mileage.') }}</li>
                        @endforelse
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
