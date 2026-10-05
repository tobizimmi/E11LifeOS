<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\HouseholdMember;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeDashboard;
use App\Models\Meter;
use App\Models\SmartHomeDay;
use App\Models\SmartHomeEntity;
use App\Models\Vehicle;
use App\Support\SmartHome\EnergyRecorder;
use App\Support\SmartHome\EntityControl;
use App\Support\Drives\WebDavClient;
use App\Support\SmartHome\EntityStates;
use App\Support\SmartHome\EvccClient;
use App\Support\Vpn;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Smart home: values and switches from Home Assistant, the energy flow and charging points of evcc,
 * and (for the household owner) the connections – reached through the VPN or an external address.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    #[Url]
    public ?int $board = null;

    /** A just created dashboard opens in editing mode. */
    public bool $newBoard = false;

    /**
     * @var array<string, string>
     */
    protected $listeners = ['smart-home-dashboards-changed' => '$refresh', 'smart-home-dashboard-deleted' => 'dashboardDeleted'];

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array{type: string, name: string, url: string, external_url: string, token: string}
     */
    public array $form = ['type' => 'homeassistant', 'name' => '', 'url' => '', 'external_url' => '', 'token' => ''];

    public string $entitySearch = '';

    /**
     * Entities offered by Home Assistant while picking (loaded on request).
     *
     * @var list<array{entity_id: string, state: string, name: string, unit: ?string}>
     */
    public array $available = [];

    public ?string $error = null;

    private function isOwner(): bool
    {
        return (bool) HouseholdMember::where('household_id', $this->householdId)->where('user_id', Auth::id())->first()?->isOwner();
    }

    private function findConnection(int $id): SmartHomeConnection
    {
        return SmartHomeConnection::where('household_id', $this->householdId)->find($id) ?? abort(404);
    }

    private function homeAssistant(): ?SmartHomeConnection
    {
        return SmartHomeConnection::where('household_id', $this->householdId)->where('type', SmartHomeConnection::HOME_ASSISTANT)->orderBy('id')->first();
    }

    private function evccConnection(): ?SmartHomeConnection
    {
        return SmartHomeConnection::where('household_id', $this->householdId)->where('type', SmartHomeConnection::EVCC)->orderBy('id')->first();
    }

    public function create(string $type = 'homeassistant'): void
    {
        abort_unless($this->isOwner(), 403);
        $this->reset(['editingId']);
        $this->form = ['type' => $type, 'name' => SmartHomeConnection::types()[$type]['label'] ?? '', 'url' => '', 'external_url' => '', 'token' => ''];
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        abort_unless($this->isOwner(), 403);
        $connection = $this->findConnection($id);
        $this->editingId = $connection->id;
        // The token is never sent back to the browser; empty keeps the saved one.
        $this->form = ['type' => $connection->type, 'name' => $connection->name, 'url' => (string) $connection->url, 'external_url' => (string) $connection->external_url, 'token' => ''];
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless($this->isOwner(), 403);
        $allowed = fn ($attribute, $value, $fail) => $value === '' || $value === null || WebDavClient::isAllowedUrl((string) $value) ? null : $fail(__('This address is not allowed: it is neither in a VPN network nor public. Enter your home network (e.g. 192.168.1.0/24) under Admin › Server settings › VPN › Networks through the VPN, or use a public address.'));

        $data = $this->validate([
            'form.type' => ['required', 'in:'.implode(',', array_keys(SmartHomeConnection::types()))],
            'form.name' => ['required', 'string', 'max:100'],
            'form.url' => ['nullable', 'url:http,https', 'max:500', $allowed, 'required_without:form.external_url'],
            'form.external_url' => ['nullable', 'url:https', 'max:500', $allowed],
            'form.token' => [$this->editingId || $this->form['type'] === SmartHomeConnection::EVCC ? 'nullable' : 'required', 'string', 'max:1000'],
        ], attributes: ['form.url' => __('Address in the home network'), 'form.external_url' => __('External address'), 'form.token' => __('Access token')])['form'];

        $attributes = ['type' => $data['type'], 'name' => $data['name'], 'url' => $data['url'] ?: null, 'external_url' => $data['external_url'] ?: null];

        if (filled($data['token'])) {
            $attributes['token'] = $data['token'];
        }

        $connection = $this->editingId ? tap($this->findConnection($this->editingId))->update($attributes) : SmartHomeConnection::create($attributes + ['household_id' => $this->householdId]);
        $this->reset(['editingId', 'showForm']);
        $this->testConnection($connection->id);
    }

    public function delete(int $id): void
    {
        abort_unless($this->isOwner(), 403);
        $this->findConnection($id)->delete();
        $this->reset(['editingId', 'showForm', 'available']);
    }

    public function testConnection(int $id): void
    {
        $connection = $this->findConnection($id);

        try {
            $count = $connection->isHomeAssistant() ? count($connection->homeAssistant()->states()) : count($connection->evcc()->state()['loadpoints']);
            $this->dispatch('toast', message: $connection->isHomeAssistant()
                ? __('Connected: :count entities.', ['count' => $count])
                : __('Connected: :count charging points.', ['count' => $count]));
            $this->error = null;
        } catch (Throwable $exception) {
            $this->error = $connection->name.': '.$exception->getMessage();
        }
    }

    /**
     * Loads the entities of Home Assistant to pick from.
     */
    public function loadEntities(): void
    {
        abort_unless($this->isOwner(), 403);

        try {
            $this->available = collect($this->homeAssistant()?->homeAssistant()->states() ?? [])
                ->map(fn (array $state) => ['entity_id' => $state['entity_id'], 'state' => $state['state'], 'name' => $state['name'], 'unit' => $state['unit']])
                ->all();
            $this->error = null;
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
        }
    }

    public function addEntity(string $entityId): void
    {
        abort_unless($this->isOwner(), 403);
        $connection = $this->homeAssistant() ?? abort(404);
        $state = collect($this->available)->firstWhere('entity_id', $entityId) ?? abort(404);

        SmartHomeEntity::firstOrCreate(
            ['smart_home_connection_id' => $connection->id, 'entity_id' => $entityId],
            ['household_id' => $this->householdId, 'name' => mb_substr($state['name'], 0, 150), 'unit' => $state['unit'], 'state' => $state['state'], 'state_changed_at' => now(),
                'position' => (int) SmartHomeEntity::where('household_id', $this->householdId)->max('position') + 1],
        );
    }

    public function updateEntity(int $id, string $field, string $value): void
    {
        abort_unless($this->isOwner() && in_array($field, ['name', 'area'], true), 403);
        (SmartHomeEntity::where('household_id', $this->householdId)->find($id) ?? abort(404))->update([$field => mb_substr(trim($value), 0, $field === 'name' ? 150 : 100) ?: null]);
    }

    public function toggleFavorite(int $id): void
    {
        abort_unless($this->isOwner(), 403);
        $entity = SmartHomeEntity::where('household_id', $this->householdId)->find($id) ?? abort(404);
        $entity->update(['favorite' => ! $entity->favorite]);
    }

    /**
     * A sensor that counts a meter (e.g. total kWh) writes the meter reading every night.
     */
    public function setEntityMeter(int $id, string $meterId): void
    {
        abort_unless($this->isOwner(), 403);
        $entity = SmartHomeEntity::where('household_id', $this->householdId)->find($id) ?? abort(404);
        $meter = $meterId === '' ? null : (Meter::where('household_id', $this->householdId)->find((int) $meterId) ?? abort(404));
        $entity->update(['meter_id' => $meter?->id]);
    }

    /**
     * The car charged at evcc's first charging point: the daily charged energy goes to its log.
     */
    public function setVehicle(int $connectionId, string $vehicleId): void
    {
        abort_unless($this->isOwner(), 403);
        $connection = $this->findConnection($connectionId);
        $vehicle = $vehicleId === '' ? null : (Vehicle::where('household_id', $this->householdId)->find((int) $vehicleId) ?? abort(404));
        $connection->update(['vehicle_map' => $vehicle ? [1 => $vehicle->id] : null]);
    }

    public function removeEntity(int $id): void
    {
        abort_unless($this->isOwner(), 403);
        SmartHomeEntity::where('household_id', $this->householdId)->whereKey($id)->delete();
    }

    /**
     * Tap on a tile: switch on/off, open/close or start a scene/script (critical devices confirmed).
     */
    public function pressEntity(int $id, bool $confirmed = false): void
    {
        $entity = SmartHomeEntity::where('household_id', $this->householdId)->with('connection')->find($id) ?? abort(404);

        if ($error = EntityControl::attempt(fn () => EntityControl::press(Auth::user(), $entity, $confirmed))) {
            $this->dispatch('toast', message: $error);
        }
    }

    /**
     * Slider of a tile: brightness, target temperature or cover position.
     */
    public function setEntityValue(int $id, float $value): void
    {
        $entity = SmartHomeEntity::where('household_id', $this->householdId)->with('connection')->find($id) ?? abort(404);

        if ($error = EntityControl::attempt(fn () => EntityControl::setValue(Auth::user(), $entity, $value))) {
            $this->dispatch('toast', message: $error);
        }
    }

    public function openBoard(int $id): void
    {
        SmartHomeDashboard::query()->visibleTo(Auth::user(), $this->householdId)->find($id) ?? abort(404);
        [$this->tab, $this->board, $this->newBoard] = ['board', $id, false];
    }

    public function addDashboard(): void
    {
        $dashboard = SmartHomeDashboard::create([
            'household_id' => $this->householdId,
            'created_by' => Auth::id(),
            'name' => __('New dashboard'),
            'icon' => '🧩',
            'position' => (int) SmartHomeDashboard::where('household_id', $this->householdId)->max('position') + 1,
        ]);

        [$this->tab, $this->board, $this->newBoard] = ['board', $dashboard->id, true];
    }

    public function dashboardDeleted(): void
    {
        [$this->tab, $this->board, $this->newBoard] = ['overview', null, false];
    }

    public function reloadStates(): void
    {
        $connection = $this->homeAssistant();

        if (! $connection) {
            return;
        }

        try {
            $picked = $connection->entities()->pluck('entity_id');

            foreach ($connection->homeAssistant()->states() as $state) {
                if ($picked->contains($state['entity_id'])) {
                    EntityStates::remember($connection, $state['entity_id'], $state['state'], $state['attributes']);
                }
            }

            $this->error = null;
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
        }
    }

    public function chargeMode(int $loadpoint, string $mode): void
    {
        try {
            ($this->evccConnection() ?? abort(404))->evcc()->setMode($loadpoint, $mode);
        } catch (Throwable $exception) {
            $this->dispatch('toast', message: $exception->getMessage());
        }
    }

    public function chargeLimit(int $loadpoint, int $soc): void
    {
        try {
            ($this->evccConnection() ?? abort(404))->evcc()->setLimitSoc($loadpoint, $soc);
        } catch (Throwable $exception) {
            $this->dispatch('toast', message: $exception->getMessage());
        }
    }

    public function with(): array
    {
        $connections = SmartHomeConnection::where('household_id', $this->householdId)->orderBy('id')->get();
        $evcc = null;
        $evccError = null;

        if ($this->tab === 'overview' && ($connection = $connections->firstWhere('type', SmartHomeConnection::EVCC))) {
            try {
                $evcc = $connection->evcc()->state();
            } catch (Throwable $exception) {
                $evccError = $exception->getMessage();
            }
        }

        $entities = SmartHomeEntity::where('household_id', $this->householdId)->orderBy('position')->orderBy('name')->get();
        $energyTab = $this->tab === 'energy';
        $search = mb_strtolower(trim($this->entitySearch));

        return [
            'connections' => $connections,
            'dashboards' => SmartHomeDashboard::query()->visibleTo(Auth::user(), $this->householdId)->orderBy('position')->orderBy('id')->get(['id', 'name', 'icon']),
            'isOwner' => $this->isOwner(),
            'mayControl' => EntityControl::mayControl(Auth::user(), $this->householdId),
            'entities' => $entities,
            'groups' => $entities->groupBy(fn (SmartHomeEntity $entity) => $entity->area ?: __('Other')),
            'evcc' => $evcc,
            'evccError' => $evccError,
            'evccModes' => \App\Support\Automation\AutomationCatalog::evccModes(),
            'vpnReady' => Vpn::isConfigured(),
            'curve' => $energyTab ? EnergyRecorder::dayCurve($this->householdId, today()) : [],
            'today' => $energyTab ? EnergyRecorder::energy($this->householdId, today()) : null,
            'days' => $energyTab ? SmartHomeDay::where('household_id', $this->householdId)->whereDate('date', '>=', today()->subDays(30)->toDateString())->orderBy('date')->get() : collect(),
            'meters' => $this->tab === 'settings' ? Meter::where('household_id', $this->householdId)->orderBy('name')->get() : collect(),
            'vehicles' => $this->tab === 'settings' ? Vehicle::where('household_id', $this->householdId)->orderBy('name')->get() : collect(),
            'offered' => collect($this->available)
                ->reject(fn (array $state) => $entities->contains('entity_id', $state['entity_id']))
                ->filter(fn (array $state) => $search === '' || str_contains(mb_strtolower($state['name'].' '.$state['entity_id']), $search))
                ->take(60)->values(),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $watts = fn (?float $value) => $value === null ? '–' : (abs($value) >= 1000 ? number_format($value / 1000, 1, ',', '.').' kW' : number_format($value, 0, ',', '.').' W');
@endphp

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Smart home') }}</h2>
            <a href="{{ route('automations.index') }}" wire:navigate class="shrink-0 text-sm text-indigo-600 hover:underline dark:text-indigo-400">⚙️ {{ __('Automations') }}</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center gap-2">
                <x-section-nav class="w-full md:w-auto" :label="__('Smart home')" :active="$tab === 'board' ? 'board-'.$board : $tab" :groups="[
                    __('Dashboards') => ['overview' => ['🏠', __('Overview')]] + $dashboards->mapWithKeys(fn ($item) => ['board-'.$item->id => [$item->icon ?: '📋', $item->name, null, null, 'openBoard('.$item->id.')']])->all(),
                    __('Setup') => array_filter(['energy' => $connections->contains('type', 'evcc') ? ['⚡', __('Energy flow')] : null, 'settings' => ['🔧', __('Connections & devices')]]),
                ]" />
                @if ($entities->isNotEmpty())
                    <button type="button" wire:click="addDashboard" class="rounded-full border border-dashed border-indigo-300 px-3 py-1.5 text-sm text-indigo-600 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-400 dark:hover:bg-indigo-950/40" title="{{ __('New dashboard') }}">+ {{ __('Add dashboard') }}</button>
                @endif
            </div>

            @if ($error)
                <p class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200" role="alert">⚠️ {{ $error }}</p>
            @endif

            @if ($tab === 'board' && $board && $dashboards->contains('id', $board))
                <livewire:smart-home.dashboard :dashboard-id="$board" :editing="$newBoard" wire:key="board-{{ $board }}" />
            @elseif ($tab === 'overview' || $tab === 'board')
                @if ($connections->isEmpty())
                    <div class="rounded-2xl bg-white p-6 text-center shadow-xs dark:bg-gray-800">
                        <p class="text-3xl">🔌</p>
                        <p class="mt-2 font-medium text-gray-900 dark:text-gray-100">{{ __('Connect Home Assistant and evcc') }}</p>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Then values, switches and the charging point appear here, and automations can use them.') }}</p>
                        @if ($isOwner)
                            <x-primary-button type="button" wire:click="$set('tab', 'settings')" class="mt-4">{{ __('Set up connection') }}</x-primary-button>
                        @endif
                    </div>
                @endif

                @if ($evcc || $evccError)
                    <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">⚡ {{ __('Energy flow') }}</h3>
                        @if ($evccError)
                            <p class="mt-2 text-sm text-red-700 dark:text-red-300">{{ $evccError }}</p>
                        @else
                            <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                @foreach ([['☀️', __('PV'), $watts($evcc['pv'])], ['🏠', __('Consumption'), $watts($evcc['home'])], ['🔌', ($evcc['grid'] ?? 0) < 0 ? __('Feed-in') : __('Grid'), $watts($evcc['grid'] === null ? null : abs($evcc['grid']))], ['🔋', __('Battery'), $evcc['batterySoc'] !== null ? number_format($evcc['batterySoc'], 0).' % · '.$watts($evcc['battery']) : $watts($evcc['battery'])]] as [$icon, $label, $value])
                                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40"><dt class="text-xs text-gray-500 dark:text-gray-400">{{ $icon }} {{ $label }}</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</dd></div>
                                @endforeach
                            </dl>
                            @foreach ($evcc['loadpoints'] as $point)
                                <div class="mt-4 rounded-xl border border-gray-100 p-4 dark:border-gray-700" wire:key="loadpoint-{{ $point['id'] }}">
                                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                                        <p class="font-medium text-gray-900 dark:text-gray-100">🚗 {{ $point['title'] }}@if ($point['vehicle']) · {{ $point['vehicle'] }}@endif</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            {{ $point['charging'] ? __('Charging with :power', ['power' => $watts($point['power'])]) : ($point['connected'] ? __('Plugged in') : __('Not plugged in')) }}
                                            @if ($point['vehicleSoc'] !== null) · {{ number_format($point['vehicleSoc'], 0) }} %@endif
                                        </p>
                                    </div>
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        @foreach ($evccModes as $mode => $label)
                                            <button type="button" wire:click="chargeMode({{ $point['id'] }}, '{{ $mode }}')" @class(['rounded-full px-3 py-1.5 text-sm', 'bg-emerald-600 text-white' => $point['mode'] === $mode, 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' => $point['mode'] !== $mode])>{{ $label }}</button>
                                        @endforeach
                                        <label class="ms-auto flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">{{ __('Limit') }}
                                            <select x-on:change="$wire.chargeLimit({{ $point['id'] }}, Number($event.target.value))" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                                @foreach ([50, 60, 70, 80, 90, 100] as $soc)
                                                    <option value="{{ $soc }}" @selected((int) $point['limitSoc'] === $soc)>{{ $soc }} %</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </section>
                @endif

                @if ($entities->isNotEmpty())
                    <div class="flex justify-end"><button type="button" wire:click="reloadStates" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">↻ {{ __('Refresh') }}</button></div>
                    @foreach ($groups as $area => $items)
                        <section wire:key="area-{{ md5($area) }}">
                            <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $area }}</h3>
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                @foreach ($items as $entity)
                                    <x-smart-home.entity :entity="$entity" :may-control="$mayControl" wire:key="entity-{{ $entity->id }}" />
                                @endforeach
                            </div>
                        </section>
                    @endforeach
                @elseif ($connections->contains('type', 'homeassistant'))
                    <p class="rounded-xl bg-white px-4 py-3 text-sm text-gray-600 shadow-xs dark:bg-gray-800 dark:text-gray-300">{{ __('Pick the devices to show under “Connections & devices”.') }}</p>
                @endif
            @elseif ($tab === 'energy')
                @php($kwh = fn (?float $value) => $value === null ? '–' : number_format($value, 1, ',', '.').' kWh')
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Today') }}</h3>
                    <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                        @foreach ([['☀️', __('PV yield'), $today['pv_kwh']], ['🏠', __('Consumption'), $today['home_kwh']], ['⬇️', __('Grid import'), $today['import_kwh']], ['⬆️', __('Feed-in'), $today['export_kwh']], ['🚗', __('Charged'), $today['charged_kwh']]] as [$icon, $label, $value])
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40"><dt class="text-xs text-gray-500 dark:text-gray-400">{{ $icon }} {{ $label }}</dt><dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $kwh($value) }}</dd></div>
                        @endforeach
                    </dl>
                    @if (count($curve) > 1)
                        <x-line-chart class="mt-4" :points="$curve" :value-label="__('PV (kW)')" :compare-label="__('Consumption (kW)')" :currency="false" suffix=" kW" :height="200" />
                    @else
                        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('The curve of the day appears after the first values (recorded every five minutes).') }}</p>
                    @endif
                </section>
                @if ($days->isNotEmpty())
                    <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Last 30 days') }}</h3>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                            {{ __('PV :pv · consumption :home · feed-in :export · charged :charged', ['pv' => $kwh($days->sum('pv_kwh')), 'home' => $kwh($days->sum('home_kwh')), 'export' => $kwh($days->sum('export_kwh')), 'charged' => $kwh($days->sum('charged_kwh'))]) }}
                            @php($self = $days->sum('home_kwh') + $days->sum('charged_kwh'))
                            @if ($self > 0) · {{ __('Self-sufficiency :percent %', ['percent' => (int) round(max(0, 1 - $days->sum('import_kwh') / $self) * 100)]) }} @endif
                        </p>
                        @if ($days->count() > 1)
                            <x-line-chart class="mt-4" :points="$days->map(fn ($day) => ['label' => $day->date->format('d.m.'), 'value' => (float) $day->pv_kwh, 'compare' => $day->home_kwh])->values()->all()" :value-label="__('PV (kWh)')" :compare-label="__('Consumption (kWh)')" :currency="false" suffix=" kWh" :height="200" />
                        @endif
                    </section>
                @endif
            @else
                @unless ($isOwner)
                    <p class="rounded-xl bg-white px-4 py-3 text-sm text-gray-600 shadow-xs dark:bg-gray-800 dark:text-gray-300">{{ __('Only the owner of the household sets up the connections.') }}</p>
                @endunless

                <section class="space-y-3 rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Connections') }}</h3>
                        @if ($isOwner)
                            <div class="flex gap-2">
                                <x-secondary-button type="button" wire:click="create('homeassistant')">+ Home Assistant</x-secondary-button>
                                <x-secondary-button type="button" wire:click="create('evcc')">+ evcc</x-secondary-button>
                            </div>
                        @endif
                    </div>

                    @foreach ($connections as $connection)
                        <div class="rounded-xl border border-gray-100 p-3 text-sm dark:border-gray-700" wire:key="connection-{{ $connection->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ \App\Models\SmartHomeConnection::types()[$connection->type]['icon'] }} {{ $connection->name }}</p>
                                <div class="flex gap-3">
                                    <button type="button" wire:click="testConnection({{ $connection->id }})" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Test') }}</button>
                                    @if ($isOwner)<button type="button" wire:click="edit({{ $connection->id }})" class="text-gray-600 underline dark:text-gray-300">{{ __('Edit') }}</button>@endif
                                </div>
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                @if ($connection->url) 🔐 {{ __('Home network (VPN)') }}: {{ $connection->url }} @endif
                                @if ($connection->external_url) · 🌍 {{ __('External') }}: {{ $connection->external_url }} @endif
                            </p>
                            <p class="text-xs {{ $connection->last_error ? 'text-red-700 dark:text-red-300' : 'text-gray-500 dark:text-gray-400' }}">
                                @if ($connection->last_contact_at) ✓ {{ __('Last contact :time via :route', ['time' => $connection->last_contact_at->diffForHumans(), 'route' => ['vpn' => __('VPN'), 'external' => __('external address'), 'webhook' => __('webhook'), 'websocket' => __('live connection')][$connection->last_route] ?? $connection->last_route]) }} @endif
                                @if ($connection->last_error) · {{ $connection->last_error }} @endif
                            </p>
                            @if ($isOwner && ! $connection->isHomeAssistant() && $vehicles->isNotEmpty())
                                <label class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-600 dark:text-gray-300">🚗 {{ __('Car at charging point 1 (daily charging goes to its log)') }}
                                    <select x-on:change="$wire.setVehicle({{ $connection->id }}, $event.target.value)" class="rounded-md border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                        <option value="">–</option>
                                        @foreach ($vehicles as $vehicle)
                                            <option value="{{ $vehicle->id }}" @selected((int) (($connection->vehicle_map ?? [])[1] ?? 0) === $vehicle->id)>{{ $vehicle->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endif
                            @if ($isOwner && $connection->isHomeAssistant())
                                <details class="mt-2 text-xs text-gray-600 dark:text-gray-300">
                                    <summary class="cursor-pointer">{{ __('Send values to E11LifeOS at once (webhook)') }}</summary>
                                    <p class="mt-1">{{ __('Add this to configuration.yaml in Home Assistant and send the entities used in automations with an automation (state trigger → action “rest_command.e11lifeos”):') }}</p>
                                    <pre class="mt-1 overflow-x-auto rounded-md bg-gray-50 p-2 dark:bg-gray-900">rest_command:
  e11lifeos:
    url: "{{ route('api.v1.smart-home.webhook', $connection->webhook_secret) }}"
    method: post
    content_type: "application/json"
    payload: '{"entity_id": "@{{ entity_id }}", "state": "@{{ state }}"}'</pre>
                                    <p class="mt-1">{{ __('Without the webhook the values are fetched every minute.') }}</p>
                                </details>
                            @endif
                        </div>
                    @endforeach

                    @if ($showForm)
                        <form wire:submit="save" class="grid gap-3 rounded-xl bg-gray-50 p-4 sm:grid-cols-2 dark:bg-gray-900/40">
                            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="form.name" class="{{ $input }}"><x-input-error :messages="$errors->get('form.name')" /></label>
                            <label class="text-sm text-gray-700 dark:text-gray-300">{{ $form['type'] === 'evcc' ? __('Password (if set in evcc)') : __('Long-lived access token') }}
                                <input type="password" wire:model="form.token" class="{{ $input }}" autocomplete="new-password" placeholder="{{ $editingId ? __('unchanged') : '' }}"><x-input-error :messages="$errors->get('form.token')" /></label>
                            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Address in the home network (through the VPN)') }}<input type="url" wire:model="form.url" class="{{ $input }}" placeholder="{{ $form['type'] === 'evcc' ? 'http://192.168.1.20:7070' : 'http://192.168.1.10:8123' }}"><x-input-error :messages="$errors->get('form.url')" /></label>
                            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('External address (optional, e.g. Nabu Casa)') }}<input type="url" wire:model="form.external_url" class="{{ $input }}" placeholder="https://xxxx.ui.nabu.casa"><x-input-error :messages="$errors->get('form.external_url')" /></label>
                            <p class="text-xs text-gray-500 sm:col-span-2 dark:text-gray-400">
                                {{ $form['type'] === 'evcc' ? __('evcc runs on port 7070. It is reached through the VPN; set a password in evcc if it is reachable from outside.') : __('Home Assistant: Profile → Security → Long-lived access tokens → Create token. First the home network address through the VPN is used, the external address when the VPN is down.') }}
                                @unless ($vpnReady) {{ __('The VPN is not set up yet (Admin → Settings → VPN) – until then only the external address works.') }} @endunless
                            </p>
                            <div class="flex flex-wrap gap-2 sm:col-span-2">
                                <x-primary-button>{{ __('Save and test') }}</x-primary-button>
                                <x-secondary-button type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-secondary-button>
                                @if ($editingId)
                                    <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Remove this connection?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Remove') }}</button>
                                @endif
                            </div>
                        </form>
                    @endif
                </section>

                @if ($connections->contains('type', 'homeassistant') && $isOwner)
                    <section class="space-y-3 rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Devices on the overview') }}</h3>
                            <x-secondary-button type="button" wire:click="loadEntities">{{ __('Load from Home Assistant') }}</x-secondary-button>
                        </div>
                        @foreach ($entities as $entity)
                            <div class="flex flex-wrap items-center gap-2 text-sm" wire:key="picked-{{ $entity->id }}">
                                <span>{{ $entity->icon() }}</span>
                                <input type="text" value="{{ $entity->name }}" x-on:change="$wire.updateEntity({{ $entity->id }}, 'name', $event.target.value)" class="min-w-0 flex-1 rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Name') }}">
                                <input type="text" value="{{ $entity->area }}" x-on:change="$wire.updateEntity({{ $entity->id }}, 'area', $event.target.value)" placeholder="{{ __('Room') }}" class="w-32 rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Room') }}">
                                <span class="text-xs text-gray-500">{{ $entity->entity_id }}</span>
                                <button type="button" wire:click="toggleFavorite({{ $entity->id }})" aria-pressed="{{ $entity->favorite ? 'true' : 'false' }}" title="{{ __('On the dashboard') }}" class="{{ $entity->favorite ? 'text-amber-500' : 'text-gray-300 dark:text-gray-600' }}">★</button>
                                @if (is_numeric($entity->state) && $meters->isNotEmpty())
                                    <select x-on:change="$wire.setEntityMeter({{ $entity->id }}, $event.target.value)" class="rounded-md border-gray-300 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Meter') }}">
                                        <option value="">{{ __('No meter') }}</option>
                                        @foreach ($meters as $meter)
                                            <option value="{{ $meter->id }}" @selected($entity->meter_id === $meter->id)>📟 {{ $meter->name }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <button type="button" wire:click="removeEntity({{ $entity->id }})" class="text-red-600 dark:text-red-400" aria-label="{{ __('Remove') }}">✕</button>
                            </div>
                        @endforeach
                        @if ($available !== [])
                            <input type="search" wire:model.live.debounce.300ms="entitySearch" placeholder="{{ __('Search, e.g. light, temperature, kitchen') }}" class="{{ $input }}">
                            <ul class="max-h-80 divide-y divide-gray-100 overflow-y-auto text-sm dark:divide-gray-700">
                                @foreach ($offered as $state)
                                    <li class="flex items-center justify-between gap-2 py-1.5" wire:key="offer-{{ $state['entity_id'] }}">
                                        <span class="min-w-0"><span class="text-gray-900 dark:text-gray-100">{{ $state['name'] }}</span> <span class="text-xs text-gray-500">{{ $state['entity_id'] }} · {{ $state['state'] }}{{ $state['unit'] ? ' '.$state['unit'] : '' }}</span></span>
                                        <button type="button" wire:click="addEntity('{{ $state['entity_id'] }}')" class="shrink-0 text-indigo-600 underline dark:text-indigo-400">+ {{ __('Add') }}</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif
            @endif
        </div>
    </div>
</div>
