<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\Trip;
use App\Support\Life\TripPlanner;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Upcoming and past trips of the household.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public bool $showForm = false;

    /**
     * @var array{title: string, destination: string, starts_on: string, ends_on: string, budget: string, travelers: string, template: string, private: bool}
     */
    public array $trip = ['title' => '', 'destination' => '', 'starts_on' => '', 'ends_on' => '', 'budget' => '', 'travelers' => '', 'template' => 'basics', 'private' => false];

    public function mount(): void
    {
        $this->resolveHousehold();
    }

    public function create(): void
    {
        $this->trip['budget'] = TripPlanner::number($this->trip['budget']);
        $data = $this->validate([
            'trip.title' => ['required', 'string', 'max:150'],
            'trip.destination' => ['nullable', 'string', 'max:150'],
            'trip.starts_on' => ['required', 'date'],
            'trip.ends_on' => ['required', 'date', 'after_or_equal:trip.starts_on'],
            'trip.budget' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'trip.travelers' => ['nullable', 'string', 'max:255'],
            'trip.template' => ['nullable', 'in:,'.implode(',', array_keys(TripPlanner::templates()))],
        ], attributes: ['trip.title' => __('Title'), 'trip.starts_on' => __('From'), 'trip.ends_on' => __('Until')])['trip'];

        $created = Trip::create([
            'household_id' => $this->householdId,
            'created_by' => Auth::id(),
            'visibility' => $this->trip['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'title' => $data['title'],
            'destination' => $data['destination'] ?: null,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'budget' => $data['budget'] !== '' ? $data['budget'] : null,
            'travelers' => $data['travelers'] ?: null,
        ]);

        if ($data['template'] ?? '') {
            TripPlanner::applyTemplate($created, 'basics');
            TripPlanner::applyTemplate($created, $data['template']);
        }

        $this->redirectRoute('trips.show', ['trip' => $created->id], navigate: true);
    }

    public function with(): array
    {
        $trips = Trip::query()->visibleTo(Auth::user(), (int) $this->householdId)->with('shareGroups')->withSum('bookings', 'cost')->withCount(['packingItems', 'packingItems as packed_count' => fn ($query) => $query->where('packed', true)])->orderBy('starts_on')->get();

        return [
            'upcoming' => $trips->reject->isPast()->values(),
            'past' => $trips->filter->isPast()->sortByDesc('starts_on')->values(),
            'templates' => TripPlanner::templates(),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
@endphp

<div x-on:page-primary.window="$wire.set('showForm', true)">
    <x-slot name="header">
        {{-- The header lives outside the component: the button sends page-primary, the page listens. --}}
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Trips') }}</h2>
            <x-primary-button type="button" x-on:click="$dispatch('page-primary')" class="shrink-0">+ {{ __('Plan trip') }}</x-primary-button>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">

            @if ($showForm)
                <form wire:submit="create" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Title') }}<input type="text" wire:model="trip.title" class="{{ $input }}" placeholder="{{ __('e.g. Summer holiday') }}" autofocus><x-input-error :messages="$errors->get('trip.title')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Destination') }}<input type="text" wire:model="trip.destination" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('From') }}<input type="date" wire:model="trip.starts_on" class="{{ $input }}"><x-input-error :messages="$errors->get('trip.starts_on')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Until') }}<input type="date" wire:model="trip.ends_on" class="{{ $input }}"><x-input-error :messages="$errors->get('trip.ends_on')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Budget (€)') }}<input type="text" inputmode="decimal" wire:model="trip.budget" class="{{ $input }}"><x-input-error :messages="$errors->get('trip.budget')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Travellers') }}<input type="text" wire:model="trip.travelers" class="{{ $input }}" placeholder="{{ __('e.g. Tobi, Anna, Mia') }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Packing list') }}
                        <select wire:model="trip.template" class="{{ $input }}">
                            <option value="">{{ __('Start empty') }}</option>
                            @foreach ($templates as $key => $template)
                                <option value="{{ $key }}">{{ $template['icon'] }} {{ $key === 'basics' ? __('Basics') : __('Basics + :template', ['template' => $template['label']]) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-6 dark:text-gray-300"><input type="checkbox" wire:model="trip.private" class="rounded border-gray-300 text-indigo-600"> {{ __('Only visible to me') }}</label>
                    <div class="flex gap-2 sm:col-span-6">
                        <x-primary-button>{{ __('Create') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-secondary-button>
                    </div>
                </form>
            @endif

            @forelse ($upcoming as $plannedTrip)
                @php($days = (int) today()->diffInDays($plannedTrip->starts_on, false))
                <a href="{{ route('trips.show', $plannedTrip) }}" wire:navigate wire:key="trip-{{ $plannedTrip->id }}" class="block rounded-2xl bg-white p-5 shadow-sm transition hover:ring-1 hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-lg font-semibold text-gray-900 dark:text-gray-100">✈️ {{ $plannedTrip->title }} <x-audience-badge :model="$plannedTrip" /></p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ collect([$plannedTrip->destination, $plannedTrip->starts_on->format('d.m.').' – '.$plannedTrip->ends_on->format('d.m.Y'), $plannedTrip->travelers])->filter()->implode(' · ') }}</p>
                        </div>
                        <span @class(['rounded-full px-3 py-1 text-sm font-medium', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $plannedTrip->isOngoing(), 'bg-indigo-50 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200' => ! $plannedTrip->isOngoing()])>
                            {{ $plannedTrip->isOngoing() ? __('On the road') : trans_choice('in :count day|in :count days', $days) }}
                        </span>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                        @if ($plannedTrip->bookings_sum_cost || $plannedTrip->budget)
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-gray-700 dark:text-gray-200">💶 {{ $euro((float) $plannedTrip->bookings_sum_cost) }}{{ $plannedTrip->budget ? ' / '.$euro($plannedTrip->budget) : '' }}</span>
                        @endif
                        @if ($plannedTrip->packing_items_count)
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-gray-700 dark:text-gray-200">🧳 {{ __(':packed of :total packed', ['packed' => $plannedTrip->packed_count, 'total' => $plannedTrip->packing_items_count]) }}</span>
                        @endif
                    </div>
                </a>
            @empty
                <section class="rounded-2xl bg-white p-6 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">🧳</p>
                    <p class="mt-2 text-gray-700 dark:text-gray-200">{{ __('No trip planned yet.') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Collect flights, accommodation and tickets with their booking codes, keep an eye on the budget, tick off the packing list – and get a reminder if your passport expires before the trip ends.') }}</p>
                </section>
            @endforelse

            @if ($past->isNotEmpty())
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Past trips') }}</h3>
                    <ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($past as $plannedTrip)
                            <li wire:key="past-{{ $plannedTrip->id }}"><a href="{{ route('trips.show', $plannedTrip) }}" wire:navigate class="flex flex-wrap items-center gap-x-3 py-2 text-sm hover:underline"><span class="min-w-0 flex-1 text-gray-900 dark:text-gray-100">{{ $plannedTrip->title }}</span><span class="text-gray-500 dark:text-gray-400">{{ $plannedTrip->starts_on->format('d.m.Y') }}{{ $plannedTrip->bookings_sum_cost ? ' · '.$euro((float) $plannedTrip->bookings_sum_cost) : '' }}</span></a></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
