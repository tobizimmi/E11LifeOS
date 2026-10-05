<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\HomeTask;
use App\Models\Meter;
use App\Support\Life\HomeMaintenance;
use App\Support\Life\MeterStats;
use App\Support\Life\Warranties;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * At home: a maintenance plan from a library of recurring jobs, meters with readings, consumption
 * projection and a check of the monthly advance payment, and the warranties of appliances.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /** maintenance, meters or warranties */
    #[Url(except: 'maintenance')]
    public string $tab = 'maintenance';

    public bool $showLibrary = false;

    /** @var list<string> */
    public array $picked = [];

    /**
     * @var array{title: string, interval_months: string, next_due_on: string}
     */
    public array $custom = ['title' => '', 'interval_months' => '12', 'next_due_on' => ''];

    public bool $showMeterForm = false;

    /**
     * @var array{name: string, kind: string, number: string, price_per_unit: string, base_fee_monthly: string, monthly_advance: string}
     */
    public array $meter = ['name' => '', 'kind' => 'electricity', 'number' => '', 'price_per_unit' => '', 'base_fee_monthly' => '', 'monthly_advance' => ''];

    public ?int $editingMeterId = null;

    /** @var array<int, array{read_on: string, value: string}> */
    public array $reading = [];

    /**
     * @var array{title: string, purchased_on: string, years: string, until: string, price: string}
     */
    public array $device = ['title' => '', 'purchased_on' => '', 'years' => '2', 'until' => '', 'price' => ''];

    /**
     * An appliance with its warranty: the end date given, or purchase date plus the warranty years.
     */
    public function addDevice(): void
    {
        $data = $this->validate([
            'device.title' => ['required', 'string', 'max:150'],
            'device.purchased_on' => ['required', 'date', 'before_or_equal:today'],
            'device.years' => ['nullable', 'integer', 'min:0', 'max:30'],
            'device.until' => ['nullable', 'date', 'after_or_equal:device.purchased_on'],
            'device.price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
        ], attributes: ['device.title' => __('Appliance'), 'device.purchased_on' => __('Purchased on')])['device'];

        $purchased = Carbon::parse($data['purchased_on']);
        $until = $data['until'] ? Carbon::parse($data['until']) : $purchased->copy()->addYears((int) ($data['years'] ?: 2));
        Warranties::add(Auth::user(), (int) $this->householdId, $data['title'], $purchased, $until, $data['price'] !== '' && $data['price'] !== null ? (float) $data['price'] : null);

        $this->device = ['title' => '', 'purchased_on' => '', 'years' => '2', 'until' => '', 'price' => ''];
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function mount(): void
    {
        $this->resolveHousehold();

        if (! in_array($this->tab, ['maintenance', 'meters', 'warranties'], true)) {
            $this->tab = 'maintenance';
        }

        $this->prepareReadings();
    }

    /**
     * Empty reading forms (dated today) for every meter.
     */
    private function prepareReadings(): void
    {
        foreach (Meter::where('household_id', $this->householdId)->pluck('id') as $id) {
            $this->reading[$id] ??= ['read_on' => today()->toDateString(), 'value' => ''];
        }
    }

    private static function number(string $value): string
    {
        $value = trim(str_replace(['€', ' '], '', $value));

        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            return str_replace('.', '', $value);
        }

        return str_contains($value, ',') ? str_replace(',', '.', str_replace('.', '', $value)) : $value;
    }

    public function addPicked(): void
    {
        $library = HomeMaintenance::library();
        $existing = HomeTask::where('household_id', $this->householdId)->pluck('key')->all();

        foreach (array_unique($this->picked) as $key) {
            if (! isset($library[$key]) || in_array($key, $existing, true)) {
                continue;
            }

            HomeTask::create([
                'household_id' => $this->householdId,
                'created_by' => Auth::id(),
                'key' => $key,
                'title' => $library[$key]['title'],
                'icon' => $library[$key]['icon'],
                'interval_months' => $library[$key]['interval'],
                'next_due_on' => HomeMaintenance::firstDueOn($library[$key]),
                'notes' => $library[$key]['hint'] ?: null,
            ]);
        }

        $this->picked = [];
        $this->showLibrary = false;
    }

    public function addCustom(): void
    {
        $data = $this->validate([
            'custom.title' => ['required', 'string', 'max:255'],
            'custom.interval_months' => ['required', 'integer', 'between:1,120'],
            'custom.next_due_on' => ['required', 'date'],
        ], attributes: ['custom.title' => __('Title'), 'custom.next_due_on' => __('Next time')])['custom'];

        HomeTask::create(['household_id' => $this->householdId, 'created_by' => Auth::id(), 'title' => $data['title'], 'icon' => '🔧', 'interval_months' => (int) $data['interval_months'], 'next_due_on' => $data['next_due_on']]);
        $this->custom = ['title' => '', 'interval_months' => '12', 'next_due_on' => ''];
    }

    public function done(int $id): void
    {
        $task = $this->findTask($id);
        $task->markDone();
        $this->dispatch('toast', message: __('Done – next time on :date.', ['date' => $task->next_due_on->format('d.m.Y')]));
    }

    public function deleteTask(int $id): void
    {
        $this->findTask($id)->delete();
    }

    private function findTask(int $id): HomeTask
    {
        return HomeTask::where('household_id', $this->householdId)->find($id) ?? abort(404);
    }

    public function newMeter(): void
    {
        $this->resetValidation();
        $this->editingMeterId = null;
        $this->meter = ['name' => '', 'kind' => 'electricity', 'number' => '', 'price_per_unit' => '', 'base_fee_monthly' => '', 'monthly_advance' => ''];
        $this->showMeterForm = true;
    }

    public function editMeter(int $id): void
    {
        $meter = $this->findMeter($id);
        $this->resetValidation();
        $this->editingMeterId = $meter->id;
        $this->meter = [
            'name' => $meter->name,
            'kind' => $meter->kind,
            'number' => (string) $meter->number,
            'price_per_unit' => $meter->price_per_unit !== null ? (string) $meter->price_per_unit : '',
            'base_fee_monthly' => $meter->base_fee_monthly !== null ? (string) $meter->base_fee_monthly : '',
            'monthly_advance' => $meter->monthly_advance !== null ? (string) $meter->monthly_advance : '',
        ];
        $this->showMeterForm = true;
    }

    public function saveMeter(): void
    {
        foreach (['price_per_unit', 'base_fee_monthly', 'monthly_advance'] as $field) {
            $this->meter[$field] = self::number((string) $this->meter[$field]);
        }

        $data = $this->validate([
            'meter.name' => ['required', 'string', 'max:120'],
            'meter.kind' => ['required', Rule::in(Meter::KINDS)],
            'meter.number' => ['nullable', 'string', 'max:64'],
            'meter.price_per_unit' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'meter.base_fee_monthly' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'meter.monthly_advance' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], attributes: ['meter.name' => __('Name')])['meter'];

        $attributes = collect($data)->map(fn ($value) => $value === '' ? null : $value)->all();

        if ($this->editingMeterId) {
            $this->findMeter($this->editingMeterId)->update($attributes);
        } else {
            Meter::create($attributes + ['household_id' => $this->householdId, 'created_by' => Auth::id()]);
        }

        $this->showMeterForm = false;
        $this->editingMeterId = null;
        $this->prepareReadings();
    }

    public function deleteMeter(int $id): void
    {
        $this->findMeter($id)->delete();
        $this->showMeterForm = false;
    }

    public function addReading(int $meterId): void
    {
        $this->reading[$meterId]['value'] = self::number((string) ($this->reading[$meterId]['value'] ?? ''));
        $this->reading[$meterId]['read_on'] ??= today()->toDateString();

        $data = $this->validate([
            "reading.{$meterId}.read_on" => ['required', 'date', 'before_or_equal:today'],
            "reading.{$meterId}.value" => ['required', 'numeric', 'min:0', 'max:99999999999'],
        ], attributes: ["reading.{$meterId}.value" => __('Meter reading')])['reading'][$meterId];

        $this->findMeter($meterId)->readings()->create(['created_by' => Auth::id(), 'read_on' => $data['read_on'], 'value' => $data['value']]);
        $this->reading[$meterId] = ['read_on' => today()->toDateString(), 'value' => ''];
        $this->dispatch('toast', message: __('Reading saved.'));
    }

    public function deleteReading(int $meterId, int $readingId): void
    {
        $this->findMeter($meterId)->readings()->whereKey($readingId)->delete();
    }

    private function findMeter(int $id): Meter
    {
        return Meter::where('household_id', $this->householdId)->find($id) ?? abort(404);
    }

    public function with(): array
    {
        $meters = Meter::where('household_id', $this->householdId)->with('readings')->orderBy('name')->get();

        return [
            'tasks' => HomeTask::where('household_id', $this->householdId)->orderBy('next_due_on')->get(),
            'library' => HomeMaintenance::library(),
            'meters' => $meters->map(fn (Meter $meter) => ['meter' => $meter, 'stats' => MeterStats::for($meter)]),
            'kinds' => Meter::kindLabels(),
            'warranties' => $this->tab === 'warranties' ? Warranties::list(Auth::user(), (int) $this->householdId) : collect(),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
    $amount = fn (float $value, string $unit) => number_format($value, $value < 100 ? 2 : 0, ',', '.').($unit !== '' ? ' '.$unit : '');
    $tabClass = 'flex-1 rounded-lg px-3 py-2 text-sm font-medium transition pointer-coarse:min-h-11';
    $existingKeys = $tasks->pluck('key')->all();
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('At home') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <x-section-nav :label="__('Home')" :active="$tab" :groups="['' => ['maintenance' => ['🔧', __('Maintenance')], 'meters' => ['🔢', __('Meters & consumption')], 'warranties' => ['🧾', __('Warranties')]]]" />

            @if ($tab === 'maintenance')
                <div class="flex flex-wrap justify-end gap-2">
                    <x-primary-button type="button" wire:click="$toggle('showLibrary')">📚 {{ __('Add from the library') }}</x-primary-button>
                </div>

                @if ($showLibrary)
                    <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('What should be reminded?') }}</h3>
                        <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($library as $key => $preset)
                                <li>
                                    <label @class(['flex items-start gap-3 rounded-lg p-3 ring-1', 'ring-gray-200 dark:ring-gray-700' => ! in_array($key, $existingKeys, true), 'opacity-50 ring-gray-100 dark:ring-gray-800' => in_array($key, $existingKeys, true)])>
                                        <input type="checkbox" value="{{ $key }}" wire:model="picked" class="mt-1 rounded-sm border-gray-300 text-indigo-600" @disabled(in_array($key, $existingKeys, true))>
                                        <span class="text-sm">
                                            <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $preset['icon'] }} {{ $preset['title'] }}</span>
                                            <span class="block text-gray-500 dark:text-gray-400">{{ trans_choice('every month|every :count months', $preset['interval']) }}@if ($preset['hint']) · {{ $preset['hint'] }}@endif</span>
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                        <div class="mt-3 flex gap-2">
                            <x-primary-button type="button" wire:click="addPicked">{{ __('Add selected') }}</x-primary-button>
                            <x-secondary-button type="button" wire:click="$set('showLibrary', false)">{{ __('Cancel') }}</x-secondary-button>
                        </div>
                    </section>
                @endif

                <section class="overflow-hidden rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($tasks as $task)
                            <li wire:key="home-task-{{ $task->id }}" class="flex items-center gap-3 px-5 py-3">
                                <button type="button" wire:click="done({{ $task->id }})" class="rounded-full p-1 text-gray-400 hover:text-emerald-600 pointer-coarse:min-h-11 pointer-coarse:min-w-11" title="{{ __('Mark as done') }}" aria-label="{{ __('Mark as done') }}"><x-icon name="check-circle" class="h-6 w-6" /></button>
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $task->icon }} {{ $task->title }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice('every month|every :count months', $task->interval_months) }}@if ($task->last_done_on) · {{ __('last on :date', ['date' => $task->last_done_on->format('d.m.Y')]) }}@endif @if ($task->notes) · {{ $task->notes }}@endif</p>
                                </div>
                                <span @class(['shrink-0 rounded-full px-2 py-0.5 text-xs tabular-nums', 'bg-red-50 text-red-800 dark:bg-red-900/40 dark:text-red-200' => $task->isOverdue(), 'bg-amber-50 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $task->isDueSoon(), 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => ! $task->isOverdue() && ! $task->isDueSoon()])>{{ $task->next_due_on->format('d.m.Y') }}</span>
                                <button type="button" wire:click="deleteTask({{ $task->id }})" wire:confirm="{{ __('Remove this job from the plan?') }}" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                            </li>
                        @empty
                            <li class="px-5 py-6 text-center text-sm text-gray-500 dark:text-gray-400">{{ __('No maintenance plan yet – pick the jobs that fit your home from the library.') }}</li>
                        @endforelse
                    </ul>
                </section>

                <form wire:submit="addCustom" class="grid gap-3 rounded-2xl bg-white p-5 shadow-xs sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Own job') }}<input type="text" wire:model="custom.title" class="{{ $input }}" placeholder="{{ __('e.g. Service the garage door') }}"><x-input-error :messages="$errors->get('custom.title')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-1 dark:text-gray-300">{{ __('Months') }}<input type="number" min="1" max="120" wire:model="custom.interval_months" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Next time') }}<input type="date" wire:model="custom.next_due_on" class="{{ $input }}"><x-input-error :messages="$errors->get('custom.next_due_on')" /></label>
                    <div class="sm:col-span-6"><x-secondary-button type="submit">+ {{ __('Add') }}</x-secondary-button></div>
                </form>
            @else
                <div class="flex justify-end">
                    <x-primary-button type="button" wire:click="newMeter">+ {{ __('Add meter') }}</x-primary-button>
                </div>

                @if ($showMeterForm)
                    <form wire:submit="saveMeter" class="grid gap-3 rounded-2xl bg-white p-5 shadow-xs sm:grid-cols-6 dark:bg-gray-800">
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="meter.name" class="{{ $input }}" placeholder="{{ __('e.g. Electricity house') }}"><x-input-error :messages="$errors->get('meter.name')" /></label>
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Type') }}
                            <select wire:model="meter.kind" class="{{ $input }}">
                                @foreach ($kinds as $key => $kind)
                                    <option value="{{ $key }}">{{ $kind['icon'] }} {{ $kind['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Meter number') }}<input type="text" wire:model="meter.number" class="{{ $input }}"></label>
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Price per unit (€)') }}<input type="text" inputmode="decimal" wire:model="meter.price_per_unit" class="{{ $input }}" placeholder="0,32"><x-input-error :messages="$errors->get('meter.price_per_unit')" /></label>
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Base fee per month (€)') }}<input type="text" inputmode="decimal" wire:model="meter.base_fee_monthly" class="{{ $input }}"><x-input-error :messages="$errors->get('meter.base_fee_monthly')" /></label>
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Monthly advance (€)') }}<input type="text" inputmode="decimal" wire:model="meter.monthly_advance" class="{{ $input }}"><x-input-error :messages="$errors->get('meter.monthly_advance')" /></label>
                        <div class="flex items-center gap-2 sm:col-span-6">
                            <x-primary-button>{{ __('Save') }}</x-primary-button>
                            <x-secondary-button type="button" wire:click="$set('showMeterForm', false)">{{ __('Cancel') }}</x-secondary-button>
                            @if ($editingMeterId)
                                <button type="button" wire:click="deleteMeter({{ $editingMeterId }})" wire:confirm="{{ __('Delete this meter with all readings?') }}" class="ms-auto text-sm text-red-600 hover:underline">{{ __('Delete meter') }}</button>
                            @endif
                        </div>
                    </form>
                @endif

                @forelse ($meters as $row)
                    @php
                        $gauge = $row['meter'];
                        $stats = $row['stats'];
                        $unit = $gauge->unit();
                        $chart = collect(array_reverse($stats['periods']))->map(fn (array $period) => ['label' => \Illuminate\Support\Carbon::parse($period['to'])->format('d.m.y'), 'value' => $period['per_day']])->all();
                    @endphp
                    <section wire:key="meter-{{ $gauge->id }}" class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $kinds[$gauge->kind]['icon'] ?? '' }} {{ $gauge->name }}</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $gauge->number ? __('No. :number', ['number' => $gauge->number]).' · ' : '' }}{{ $stats['latest'] !== null ? __('last reading :value', ['value' => $amount($stats['latest'], $unit)]) : __('No readings yet') }}</p>
                            </div>
                            <button type="button" wire:click="editMeter({{ $gauge->id }})" class="text-gray-400 hover:text-indigo-600" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="h-5 w-5" /></button>
                        </div>

                        @if ($stats['yearly'] !== null)
                            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $gauge->kind === 'pv' ? __('Yield per year') : __('Consumption per year') }}</p>
                                    <p class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $amount($stats['yearly'], $unit) }}</p>
                                </div>
                                @if ($stats['yearly_cost'] !== null)
                                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Costs per year') }}</p>
                                        <p class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($stats['yearly_cost']) }}</p>
                                    </div>
                                @endif
                                @if ($stats['balance'] !== null)
                                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $stats['balance'] >= 0 ? __('Expected credit') : __('Expected additional payment') }}</p>
                                        <p @class(['font-semibold tabular-nums', 'text-emerald-700 dark:text-emerald-400' => $stats['balance'] >= 0, 'text-rose-700 dark:text-rose-400' => $stats['balance'] < 0])>{{ $euro(abs($stats['balance'])) }}</p>
                                    </div>
                                    <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-700/40">
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Fitting advance') }}</p>
                                        <p class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($stats['suggested_advance']) }}</p>
                                    </div>
                                @endif
                            </div>
                            @if ($stats['balance'] !== null && abs($stats['balance']) >= 60)
                                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $stats['balance'] < 0 ? __('Your advance of :advance is too low – raise it to about :suggested to avoid an additional payment.', ['advance' => $euro($gauge->monthly_advance), 'suggested' => $euro($stats['suggested_advance'])]) : __('Your advance of :advance is higher than needed – about :suggested would be enough.', ['advance' => $euro($gauge->monthly_advance), 'suggested' => $euro($stats['suggested_advance'])]) }}</p>
                            @endif
                        @endif

                        @if (count($chart) >= 2)
                            <div class="mt-4">
                                <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Consumption per day') }}</p>
                                <x-line-chart :points="$chart" :currency="false" :suffix="' '.$unit" :value-label="__('Per day')" height="160" />
                            </div>
                        @endif

                        <form wire:submit="addReading({{ $gauge->id }})" class="mt-4 flex flex-wrap items-end gap-2">
                            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Date') }}<input type="date" wire:model="reading.{{ $gauge->id }}.read_on" class="{{ $input }}"></label>
                            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Meter reading') }}{{ $unit !== '' ? ' ('.$unit.')' : '' }}<input type="text" inputmode="decimal" wire:model="reading.{{ $gauge->id }}.value" class="{{ $input }}"></label>
                            <x-secondary-button type="submit">{{ __('Save reading') }}</x-secondary-button>
                            <x-input-error class="w-full" :messages="$errors->get('reading.'.$gauge->id.'.value')" />
                            <x-input-error class="w-full" :messages="$errors->get('reading.'.$gauge->id.'.read_on')" />
                        </form>

                        @if ($stats['periods'] !== [])
                            <details class="mt-3 text-sm">
                                <summary class="cursor-pointer text-gray-600 dark:text-gray-300">{{ __('Readings and periods') }}</summary>
                                <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                                    @foreach ($stats['periods'] as $period)
                                        <li class="flex flex-wrap justify-between gap-x-3 py-1.5">
                                            <span class="text-gray-700 dark:text-gray-200">{{ \Illuminate\Support\Carbon::parse($period['from'])->format('d.m.Y') }} – {{ \Illuminate\Support\Carbon::parse($period['to'])->format('d.m.Y') }}</span>
                                            <span class="tabular-nums text-gray-600 dark:text-gray-300">{{ $amount($period['consumption'], $unit) }} · {{ $amount($period['per_day'], $unit) }}/{{ __('day') }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <ul class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($gauge->readings->sortByDesc('read_on') as $entry)
                                        <li class="flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $entry->read_on->format('d.m.Y') }}: {{ $amount($entry->value, $unit) }}<button type="button" wire:click="deleteReading({{ $gauge->id }}, {{ $entry->id }})" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="x-mark" class="h-3 w-3" stroke-width="2" /></button></li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </section>
                @empty
                    <section class="rounded-2xl bg-white p-6 text-center text-sm text-gray-500 shadow-xs dark:bg-gray-800 dark:text-gray-400">{{ __('No meters yet – add electricity, gas or water with tariff and advance payment.') }}</section>
                @endforelse
            @endif

            @if ($tab === 'warranties')
                <form wire:submit="addDevice" class="grid gap-3 rounded-2xl bg-white p-5 shadow-xs sm:grid-cols-6 dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 sm:col-span-6 dark:text-gray-100">{{ __('Add an appliance') }}</h3>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Appliance') }}<input type="text" wire:model="device.title" class="{{ $input }}" placeholder="{{ __('e.g. washing machine') }}"><x-input-error :messages="$errors->get('device.title')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Purchased on') }}<input type="date" wire:model="device.purchased_on" class="{{ $input }}"><x-input-error :messages="$errors->get('device.purchased_on')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Warranty (years)') }}<input type="number" min="0" max="30" wire:model="device.years" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('or until') }}<input type="date" wire:model="device.until" class="{{ $input }}"><x-input-error :messages="$errors->get('device.until')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Price (€)') }}<input type="text" inputmode="decimal" wire:model="device.price" class="{{ $input }}"><x-input-error :messages="$errors->get('device.price')" /></label>
                    <div class="sm:col-span-6"><x-primary-button>{{ __('Add') }}</x-primary-button></div>
                    <p class="text-xs text-gray-500 sm:col-span-6 dark:text-gray-400">{{ __('In the EU you have 2 years of statutory warranty against the seller; many manufacturers give more. Attach the receipt to the entry – you get a reminder 30 days before the end.') }}</p>
                </form>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    @forelse ($warranties as $row)
                        @if ($loop->first)<ul class="divide-y divide-gray-100 dark:divide-gray-700">@endif
                        <li wire:key="warranty-{{ $row['item']->id }}" class="flex flex-wrap items-center gap-3 py-3 text-sm">
                            <span class="min-w-0 flex-1">
                                <a href="{{ route('items.show', $row['item']) }}" class="font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $row['item']->title }}</a>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ collect([$row['collection']->icon.' '.$row['collection']->name, $row['purchased'] ? __('bought :date', ['date' => $row['purchased']->format('d.m.Y')]) : null, $row['price'] !== null ? $euro($row['price']) : null])->filter()->implode(' · ') }}</span>
                            </span>
                            <span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-amber-50 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => $row['status'] === 'soon', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $row['status'] === 'active', 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' => $row['status'] === 'expired'])>
                                @if ($row['status'] === 'expired')
                                    {{ __('ended :date', ['date' => $row['until']->format('d.m.Y')]) }}
                                @else
                                    {{ __('until :date', ['date' => $row['until']->format('d.m.Y')]) }} · {{ trans_choice(':count day left|:count days left', $row['days']) }}
                                @endif
                            </span>
                        </li>
                        @if ($loop->last)</ul>@endif
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No warranties yet. Add appliances above or use the household inventory collection.') }}</p>
                    @endforelse
                </section>
            @endif
        </div>
    </div>
</div>
