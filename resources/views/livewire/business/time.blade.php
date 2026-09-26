<?php

use App\Livewire\Concerns\InteractsWithBusiness;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Invoice;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithBusiness;
    use InteractsWithHousehold;

    #[Url(except: 'open')]
    public string $filter = 'open';

    #[Url(as: 'customer', except: '')]
    public string $customerFilter = '';

    public string $timerDescription = '';

    public string $timerCustomer = '';

    /**
     * @var array<int, string>
     */
    public array $selected = [];

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array{description: string, customer_id: string, date: string, duration: string, hourly_rate: string}
     */
    public array $form = [];

    private function entries()
    {
        return TimeEntry::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId);
    }

    private function defaultRate(): string
    {
        $rate = $this->businessProfile()?->hourly_rate;

        return $rate ? number_format($rate, 2, ',', '') : '';
    }

    public function startTimer(): void
    {
        $this->validate(['timerDescription' => ['required', 'string', 'max:255'], 'timerCustomer' => ['nullable', 'integer']], attributes: ['timerDescription' => __('What are you working on?')]);
        $this->stopTimer();

        $customer = $this->timerCustomer !== '' ? $this->customers()->find((int) $this->timerCustomer) : null;
        TimeEntry::create([
            'user_id' => $this->currentUser()->id,
            'household_id' => $this->householdId,
            'customer_id' => $customer?->id,
            'description' => trim($this->timerDescription),
            'started_at' => now(),
            'hourly_rate' => $this->businessProfile()?->hourly_rate,
        ]);

        $this->reset('timerDescription');
    }

    public function stopTimer(): void
    {
        foreach ($this->entries()->whereNull('ended_at')->get() as $entry) {
            $entry->update(['ended_at' => now(), 'minutes' => max(1, (int) $entry->started_at->diffInMinutes(now()))]);
        }
    }

    public function newEntry(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form = ['description' => '', 'customer_id' => $this->customerFilter, 'date' => today()->toDateString(), 'duration' => '1:00', 'hourly_rate' => $this->defaultRate()];
        $this->showForm = true;
    }

    public function editEntry(int $entryId): void
    {
        $entry = $this->entries()->whereNull('invoice_id')->find($entryId) ?? abort(404);
        $this->resetValidation();
        $this->editingId = $entry->id;
        $minutes = $entry->duration();
        $this->form = [
            'description' => $entry->description,
            'customer_id' => (string) ($entry->customer_id ?? ''),
            'date' => $entry->started_at->toDateString(),
            'duration' => intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT),
            'hourly_rate' => $entry->hourly_rate ? number_format($entry->hourly_rate, 2, ',', '') : '',
        ];
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    /**
     * "1:30", "1,5" or "90m" → minutes.
     */
    private function parseDuration(string $value): ?int
    {
        $value = trim(str_replace(',', '.', $value));

        return match (true) {
            (bool) preg_match('/^(\d{1,3}):([0-5]?\d)$/', $value, $match) => (int) $match[1] * 60 + (int) $match[2],
            (bool) preg_match('/^(\d{1,4})\s*m(in)?$/i', $value, $match) => (int) $match[1],
            is_numeric($value) => (int) round((float) $value * 60),
            default => null,
        };
    }

    public function saveEntry(): void
    {
        $this->validate([
            'form.description' => ['required', 'string', 'max:255'],
            'form.customer_id' => ['nullable', 'integer'],
            'form.date' => ['required', 'date'],
            'form.duration' => ['required', 'string', 'max:10'],
            'form.hourly_rate' => ['nullable', 'string', 'max:12'],
        ], attributes: ['form.description' => __('Description'), 'form.duration' => __('Duration')]);

        $minutes = $this->parseDuration($this->form['duration']);

        if (! $minutes || $minutes > 24 * 60) {
            $this->addError('form.duration', __('Enter the duration like 1:30 or 1,5.'));

            return;
        }

        $customer = $this->form['customer_id'] !== '' ? $this->customers()->find((int) $this->form['customer_id']) : null;
        $start = Carbon::parse($this->form['date'])->setTime(9, 0);
        $rate = trim($this->form['hourly_rate']) !== '' ? (float) str_replace(',', '.', str_replace('.', '', $this->form['hourly_rate'])) : null;
        $data = ['customer_id' => $customer?->id, 'description' => trim($this->form['description']), 'started_at' => $start, 'ended_at' => $start->copy()->addMinutes($minutes), 'minutes' => $minutes, 'hourly_rate' => $rate];

        if ($this->editingId) {
            ($this->entries()->whereNull('invoice_id')->find($this->editingId) ?? abort(404))->update($data);
        } else {
            TimeEntry::create(['user_id' => $this->currentUser()->id, 'household_id' => $this->householdId] + $data);
        }

        $this->showForm = false;
    }

    public function deleteEntry(int $entryId): void
    {
        ($this->entries()->whereNull('invoice_id')->find($entryId) ?? abort(404))->delete();
        $this->showForm = false;
    }

    /**
     * Turns the selected unbilled entries of one customer into an invoice draft (one line per entry).
     */
    public function bill(): void
    {
        $entries = $this->entries()->whereNull('invoice_id')->whereNotNull('ended_at')->whereIn('id', array_map('intval', $this->selected))->orderBy('started_at')->get();

        if ($entries->isEmpty()) {
            $this->addError('bill', __('Select the entries you want to bill.'));

            return;
        }

        if ($entries->pluck('customer_id')->unique()->count() > 1) {
            $this->addError('bill', __('Please select entries of one customer only.'));

            return;
        }

        $profile = $this->businessProfile() ?? abort(404);
        $customer = $entries->first()->customer;
        $invoice = Invoice::create([
            'user_id' => $this->currentUser()->id,
            'household_id' => $this->householdId,
            'type' => Invoice::INVOICE,
            'customer_id' => $customer?->id,
            'status' => Invoice::DRAFT,
            'recipient' => $customer?->addressBlock() ?? '',
            'service_period' => $entries->first()->started_at->format('d.m.Y').($entries->count() > 1 ? ' – '.$entries->last()->started_at->format('d.m.Y') : ''),
            'intro' => __('Thank you for your order. I hereby invoice the following services:'),
            'closing' => $profile->invoice_footer,
            'small_business' => $profile->small_business,
        ]);

        foreach ($entries->values() as $position => $entry) {
            $invoice->items()->create([
                'position' => $position + 1,
                'description' => $entry->started_at->format('d.m.Y').' – '.$entry->description,
                'quantity' => round($entry->duration() / 60, 2),
                'unit' => __('Hrs.'),
                'unit_price' => (float) ($entry->hourly_rate ?? $profile->hourly_rate ?? 0),
                'vat_rate' => $profile->small_business ? 0 : $profile->default_vat_rate,
            ]);
            $entry->update(['invoice_id' => $invoice->id]);
        }

        $invoice->recalculate();
        $this->redirectRoute('business.invoices.edit', ['invoice' => $invoice->id], navigate: true);
    }

    public function with(): array
    {
        $running = $this->entries()->whereNull('ended_at')->with('customer')->first();
        $entries = $this->entries()->whereNotNull('ended_at')->with(['customer', 'invoice'])
            ->when($this->filter === 'open', fn ($query) => $query->whereNull('invoice_id'))
            ->when($this->filter === 'billed', fn ($query) => $query->whereNotNull('invoice_id'))
            ->when($this->customerFilter !== '', fn ($query) => $query->where('customer_id', (int) $this->customerFilter))
            ->orderByDesc('started_at')->limit(300)->get();
        $week = $this->entries()->whereNotNull('ended_at')->where('started_at', '>=', now()->startOfWeek())->sum('minutes');
        $month = $this->entries()->whereNotNull('ended_at')->where('started_at', '>=', now()->startOfMonth())->sum('minutes');
        $open = $this->entries()->whereNotNull('ended_at')->whereNull('invoice_id')->get();

        return [
            'profile' => $this->businessProfile(),
            'running' => $running,
            'entries' => $entries,
            'customerOptions' => $this->customers()->orderBy('name')->get(['id', 'name']),
            'weekMinutes' => (int) $week,
            'monthMinutes' => (int) $month,
            'openAmount' => round($open->sum(fn (TimeEntry $entry) => $entry->amount()), 2),
            'openMinutes' => (int) $open->sum(fn (TimeEntry $entry) => $entry->duration()),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $hours = fn (int $minutes) => intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).' h';
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">💼 {{ $profile?->company_name ?: __('Small business') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your business data.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('business.partials.nav', ['active' => 'business.time'])

            {{-- Timer --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                @if ($running)
                    <div class="flex flex-wrap items-center gap-4" x-data="{ start: {{ $running->started_at->timestamp }}, now: Math.floor(Date.now() / 1000) }" x-init="setInterval(() => now = Math.floor(Date.now() / 1000), 1000)">
                        <span class="relative flex h-3 w-3"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex h-3 w-3 rounded-full bg-red-500"></span></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-gray-900 dark:text-gray-100">{{ $running->description }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $running->customer?->name ?? __('without customer') }} · {{ __('since :time', ['time' => $running->started_at->format('H:i')]) }}</p>
                        </div>
                        <span class="font-mono text-2xl tabular-nums text-gray-900 dark:text-gray-100" x-text="(() => { const s = Math.max(0, now - start); return String(Math.floor(s / 3600)).padStart(2, '0') + ':' + String(Math.floor(s % 3600 / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0') })()"></span>
                        <button type="button" wire:click="stopTimer" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">■ {{ __('Stop') }}</button>
                    </div>
                @else
                    <form wire:submit="startTimer" class="flex flex-wrap items-center gap-3">
                        <input type="text" wire:model="timerDescription" placeholder="{{ __('What are you working on?') }}" aria-label="{{ __('What are you working on?') }}" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        <select wire:model="timerCustomer" aria-label="{{ __('Customer') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            <option value="">{{ __('without customer') }}</option>
                            @foreach ($customerOptions as $customer)
                                <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">▶ {{ __('Start') }}</button>
                        <button type="button" wire:click="newEntry" class="rounded-lg px-3 py-2 text-sm text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700">＋ {{ __('Add manually') }}</button>
                    </form>
                    <x-input-error :messages="$errors->get('timerDescription')" class="mt-1" />
                @endif
            </section>

            <div class="grid grid-cols-3 gap-3">
                @foreach ([[__('This week'), $hours($weekMinutes)], [__('This month'), $hours($monthMinutes)], [__('Not yet billed'), $euro($openAmount).' · '.$hours($openMinutes)]] as [$label, $value])
                    <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100 sm:text-2xl">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <select wire:model.live="filter" aria-label="{{ __('Filter') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    <option value="open">{{ __('Not yet billed') }}</option>
                    <option value="billed">{{ __('Billed') }}</option>
                    <option value="all">{{ __('All') }}</option>
                </select>
                <select wire:model.live="customerFilter" aria-label="{{ __('Customer') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    <option value="">{{ __('All customers') }}</option>
                    @foreach ($customerOptions as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                    @endforeach
                </select>
                @if ($filter !== 'billed')
                    <button type="button" wire:click="bill" @disabled($selected === []) class="ms-auto rounded-lg bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 disabled:opacity-40 dark:bg-gray-200 dark:text-gray-800">🧾 {{ __('Bill selected') }}@if ($selected !== []) ({{ count($selected) }})@endif</button>
                @endif
            </div>
            <x-input-error :messages="$errors->get('bill')" />

            @if ($entries->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">⏱</p>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('No times yet. Start the timer or add times manually – billable hours become an invoice with one click.') }}</p>
                </div>
            @else
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white shadow-sm dark:divide-gray-700 dark:bg-gray-800">
                    @foreach ($entries as $entry)
                        <li wire:key="time-{{ $entry->id }}" class="flex items-center gap-3 px-4 py-3">
                            @if (! $entry->invoice_id)
                                <input type="checkbox" wire:model.live="selected" value="{{ $entry->id }}" aria-label="{{ __('Select') }}" class="rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">
                            @else
                                <span class="w-4 text-center text-xs" title="{{ __('Billed') }}">🧾</span>
                            @endif
                            <button type="button" @if (! $entry->invoice_id) wire:click="editEntry({{ $entry->id }})" @endif class="flex min-w-0 flex-1 items-center gap-3 text-start">
                                <span class="w-20 shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $entry->started_at->format('d.m.Y') }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm text-gray-900 dark:text-gray-100">{{ $entry->description }}</span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $entry->customer?->name ?? __('without customer') }}@if ($entry->invoice) · {{ $entry->invoice->number ?? __('Draft') }}@endif</span>
                                </span>
                                <span class="w-16 shrink-0 text-end text-sm tabular-nums text-gray-700 dark:text-gray-300">{{ $hours($entry->duration()) }}</span>
                                <span class="w-24 shrink-0 text-end text-sm font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ $entry->hourly_rate ? $euro($entry->amount()) : '—' }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @if ($showForm)
        <x-sheet close="closeForm" :label="$editingId ? __('Edit time') : __('Add time')" size="md" wire:key="time-form-{{ $editingId ?? 'new' }}">
            <x-slot:header><strong class="text-gray-800 dark:text-gray-100">⏱ {{ $editingId ? __('Edit time') : __('Add time') }}</strong></x-slot:header>

            <form id="time-form" wire:submit="saveEntry" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <x-input-label for="time-description" :value="__('Description')" />
                    <x-text-input id="time-description" wire:model="form.description" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('form.description')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="time-customer" :value="__('Customer')" />
                    <select id="time-customer" wire:model="form.customer_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">{{ __('without customer') }}</option>
                        @foreach ($customerOptions as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="time-date" :value="__('Date')" />
                    <x-text-input id="time-date" type="date" wire:model="form.date" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="time-duration" :value="__('Duration (h:mm)')" />
                    <x-text-input id="time-duration" wire:model="form.duration" class="mt-1 block w-full" placeholder="1:30" />
                    <x-input-error :messages="$errors->get('form.duration')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="time-rate" :value="__('Hourly rate (€, optional)')" />
                    <x-text-input id="time-rate" wire:model="form.hourly_rate" inputmode="decimal" class="mt-1 block w-full" />
                </div>
            </form>

            <x-slot:footer>
                <x-primary-button form="time-form">{{ __('Save') }}</x-primary-button>
                @if ($editingId)
                    <button type="button" wire:click="deleteEntry({{ $editingId }})" wire:confirm="{{ __('Delete this time entry?') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                @endif
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
