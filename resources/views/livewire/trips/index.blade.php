<?php

use App\Jobs\LoadTripPlaces;
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

        if ($created->destination) {
            LoadTripPlaces::start($created);
        }

        if ($data['template'] ?? '') {
            TripPlanner::applyTemplate($created, 'basics');
            TripPlanner::applyTemplate($created, $data['template']);
        }

        $this->redirectRoute('trips.show', ['trip' => $created->id], navigate: true);
    }

    public function with(): array
    {
        $trips = Trip::query()->visibleTo(Auth::user(), (int) $this->householdId)->with(['shareGroups', 'participants'])->withSum('bookings', 'cost')->withCount(['packingItems' => fn ($query) => $query->whereNull('owner_id'), 'packingItems as packed_count' => fn ($query) => $query->whereNull('owner_id')->where('packed', true)])->orderBy('starts_on')->get();

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
                @php($scheme = $plannedTrip->colorScheme())
                @php($travellers = $plannedTrip->travellerNames())
                <a href="{{ route('trips.show', $plannedTrip) }}" wire:navigate wire:key="trip-{{ $plannedTrip->id }}" class="group relative isolate block overflow-hidden rounded-3xl shadow-sm transition hover:shadow-md" style="background-image: linear-gradient(135deg, {{ $scheme['from'] }}, {{ $scheme['to'] }})">
                    @if ($plannedTrip->coverUrl())
                        <img src="{{ $plannedTrip->coverUrl() }}" alt="" loading="lazy" class="absolute inset-0 -z-10 h-full w-full object-cover transition duration-500 group-hover:scale-105">
                    @endif
                    <div class="absolute inset-0 -z-10 bg-gradient-to-t from-black/70 via-black/20 to-transparent"></div>
                    <div class="flex min-h-44 flex-col justify-between gap-4 p-5 text-white sm:min-h-52">
                        <div class="flex items-start justify-between gap-3">
                            <span class="rounded-full bg-white/20 px-3 py-1 text-sm font-medium backdrop-blur">{{ $plannedTrip->isOngoing() ? __('On the road') : trans_choice('in :count day|in :count days', $days) }}</span>
                            <x-audience-badge :model="$plannedTrip" />
                        </div>
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div class="min-w-0">
                                @if ($plannedTrip->destination)
                                    <p class="text-xs font-medium uppercase tracking-wide text-white/80">📍 {{ $plannedTrip->destination }}</p>
                                @endif
                                <p class="text-2xl font-bold leading-tight drop-shadow">{{ $plannedTrip->title }}</p>
                                <p class="mt-0.5 text-sm text-white/90">{{ $plannedTrip->starts_on->translatedFormat('j. M') }} – {{ $plannedTrip->ends_on->translatedFormat('j. M Y') }}@if ($plannedTrip->bookings_sum_cost || $plannedTrip->budget) · 💶 {{ $euro((float) $plannedTrip->bookings_sum_cost) }}{{ $plannedTrip->budget ? ' / '.$euro($plannedTrip->budget) : '' }}@endif @if ($plannedTrip->packing_items_count) · 🧳 {{ $plannedTrip->packed_count }}/{{ $plannedTrip->packing_items_count }}@endif</p>
                            </div>
                            @if ($travellers !== [])
                                <div class="flex -space-x-2">
                                    @foreach (array_slice($travellers, 0, 4) as $name)
                                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/90 text-xs font-semibold text-gray-800 ring-2 ring-white/60">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>
                                    @endforeach
                                    @if (count($travellers) > 4)
                                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-black/40 text-xs font-semibold ring-2 ring-white/60">+{{ count($travellers) - 4 }}</span>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </a>
            @empty
                <section class="rounded-2xl bg-white p-6 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">🧳</p>
                    <p class="mt-2 text-gray-700 dark:text-gray-200">{{ __('No trip planned yet.') }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Plan each day with sights, restaurants and activities, collect flights and accommodation with their booking codes, keep an eye on the budget and tick off the packing list.') }}</p>
                </section>
            @endforelse

            @if ($past->isNotEmpty())
                <section>
                    <h3 class="mb-2 font-semibold text-gray-900 dark:text-gray-100">{{ __('Past trips') }}</h3>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($past as $plannedTrip)
                            @php($scheme = $plannedTrip->colorScheme())
                            <a href="{{ route('trips.show', $plannedTrip) }}" wire:navigate wire:key="past-{{ $plannedTrip->id }}" class="relative isolate block overflow-hidden rounded-2xl shadow-sm" style="background-image: linear-gradient(135deg, {{ $scheme['from'] }}, {{ $scheme['to'] }})">
                                @if ($plannedTrip->coverUrl())
                                    <img src="{{ $plannedTrip->coverUrl() }}" alt="" loading="lazy" class="absolute inset-0 -z-10 h-full w-full object-cover">
                                @endif
                                <div class="absolute inset-0 -z-10 bg-gradient-to-t from-black/70 to-black/10"></div>
                                <div class="flex min-h-28 flex-col justify-end p-3 text-white">
                                    <p class="truncate font-semibold">{{ $plannedTrip->title }}</p>
                                    <p class="text-xs text-white/85">{{ $plannedTrip->starts_on->translatedFormat('M Y') }}{{ $plannedTrip->bookings_sum_cost ? ' · '.$euro((float) $plannedTrip->bookings_sum_cost) : '' }}</p>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
