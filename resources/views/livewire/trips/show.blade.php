<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\ExpenseGroup;
use App\Models\Item;
use App\Models\Place;
use App\Models\Trip;
use App\Models\TripBooking;
use App\Models\TripPackingItem;
use App\Support\Life\TripPlanner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * One trip: countdown, costs against the budget and checks before leaving, the bookings with
 * their codes and documents, and the packing list.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $tripId;

    /** overview, bookings or packing */
    #[Url(except: 'overview')]
    public string $tab = 'overview';

    /**
     * @var array<string, mixed>
     */
    public array $booking = [];

    public ?int $editingBookingId = null;

    /**
     * @var array{label: string, category: string, person: string}
     */
    public array $item = ['label' => '', 'category' => 'other', 'person' => ''];

    public string $template = 'beach';

    public string $templatePerson = '';

    /**
     * @var array<string, mixed>
     */
    public array $details = [];

    public function mount(Trip $trip): void
    {
        $this->resolveHousehold();
        $this->tripId = $this->findTrip($trip->id)->id;
        $this->tab = in_array($this->tab, ['overview', 'bookings', 'packing'], true) ? $this->tab : 'overview';
        $this->resetBooking();
        $this->fillDetails();
    }

    private function findTrip(?int $id = null): Trip
    {
        return Trip::query()->visibleTo($this->currentUser(), (int) $this->householdId)->find($id ?? $this->tripId) ?? abort(404);
    }

    private function resetBooking(string $kind = 'flight'): void
    {
        $this->booking = ['kind' => $kind, 'title' => '', 'provider' => '', 'starts_at' => '', 'ends_at' => '', 'reference' => '', 'cost' => '', 'paid' => false, 'notes' => ''];
        $this->editingBookingId = null;
        $this->resetValidation();
    }

    private function fillDetails(): void
    {
        $trip = $this->findTrip();
        $this->details = [
            'title' => $trip->title, 'destination' => (string) $trip->destination,
            'starts_on' => $trip->starts_on->toDateString(), 'ends_on' => $trip->ends_on->toDateString(),
            'budget' => $trip->budget !== null ? number_format($trip->budget, 2, ',', '') : '', 'travelers' => (string) $trip->travelers,
            'notes' => (string) $trip->notes, 'private' => $trip->visibility === Item::VISIBILITY_PRIVATE,
        ];
    }

    public function saveDetails(): void
    {
        $this->details['budget'] = TripPlanner::number((string) $this->details['budget']);
        $data = $this->validate([
            'details.title' => ['required', 'string', 'max:150'],
            'details.destination' => ['nullable', 'string', 'max:150'],
            'details.starts_on' => ['required', 'date'],
            'details.ends_on' => ['required', 'date', 'after_or_equal:details.starts_on'],
            'details.budget' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'details.travelers' => ['nullable', 'string', 'max:255'],
            'details.notes' => ['nullable', 'string', 'max:5000'],
        ], attributes: ['details.title' => __('Title'), 'details.ends_on' => __('Until')])['details'];

        $trip = $this->findTrip();
        $trip->update([
            'title' => $data['title'], 'destination' => $data['destination'] ?: null,
            'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
            'budget' => $data['budget'] !== '' ? $data['budget'] : null, 'travelers' => $data['travelers'] ?: null,
            'notes' => $data['notes'] ?: null,
            'visibility' => $trip->created_by === Auth::id() && $this->details['private'] ? Item::VISIBILITY_PRIVATE : ($trip->created_by === Auth::id() ? Item::VISIBILITY_SHARED : $trip->visibility),
        ]);
        $this->fillDetails();
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function deleteTrip(): void
    {
        $trip = $this->findTrip();
        // Group members from other households may edit, but not delete.
        abort_unless($trip->household_id === $this->householdId, 403);
        $trip->delete();
        $this->redirectRoute('trips.index', navigate: true);
    }

    public function editBooking(int $id): void
    {
        $booking = $this->findTrip()->bookings()->find($id) ?? abort(404);
        $this->booking = [
            'kind' => $booking->kind, 'title' => $booking->title, 'provider' => (string) $booking->provider,
            'starts_at' => (string) $booking->starts_at?->format('Y-m-d\TH:i'), 'ends_at' => (string) $booking->ends_at?->format('Y-m-d\TH:i'),
            'reference' => (string) $booking->reference, 'cost' => $booking->cost !== null ? number_format($booking->cost, 2, ',', '') : '',
            'paid' => $booking->paid, 'notes' => (string) $booking->notes,
        ];
        $this->editingBookingId = $booking->id;
    }

    public function cancelBooking(): void
    {
        $this->resetBooking();
    }

    public function saveBooking(): void
    {
        $this->booking['cost'] = TripPlanner::number((string) $this->booking['cost']);
        $data = $this->validate([
            'booking.kind' => ['required', Rule::in(TripBooking::KINDS)],
            'booking.title' => ['required', 'string', 'max:150'],
            'booking.provider' => ['nullable', 'string', 'max:150'],
            'booking.starts_at' => ['nullable', 'date'],
            'booking.ends_at' => ['nullable', 'date', 'after_or_equal:booking.starts_at'],
            'booking.reference' => ['nullable', 'string', 'max:255'],
            'booking.cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'booking.paid' => ['boolean'],
            'booking.notes' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['booking.title' => __('Title'), 'booking.cost' => __('Cost (€)')])['booking'];

        $values = [
            'kind' => $data['kind'], 'title' => $data['title'], 'provider' => $data['provider'] ?: null,
            'starts_at' => $data['starts_at'] ?: null, 'ends_at' => $data['ends_at'] ?: null, 'reference' => $data['reference'] ?: null,
            'cost' => $data['cost'] !== '' ? $data['cost'] : null, 'paid' => (bool) $data['paid'], 'notes' => $data['notes'] ?: null,
        ];
        $trip = $this->findTrip();

        if ($this->editingBookingId) {
            ($trip->bookings()->find($this->editingBookingId) ?? abort(404))->update($values);
        } else {
            $trip->bookings()->create($values);
        }

        $this->resetBooking($data['kind']);
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function togglePaid(int $id): void
    {
        $booking = $this->findTrip()->bookings()->find($id) ?? abort(404);
        $booking->update(['paid' => ! $booking->paid]);
    }

    public function deleteBooking(int $id): void
    {
        ($this->findTrip()->bookings()->find($id) ?? abort(404))->delete();
        $this->resetBooking();
    }

    public function addItem(): void
    {
        $data = $this->validate([
            'item.label' => ['required', 'string', 'max:150'],
            'item.category' => ['required', Rule::in(array_keys(TripPlanner::categories()))],
            'item.person' => ['nullable', 'string', 'max:60'],
        ], attributes: ['item.label' => __('Item')])['item'];

        $trip = $this->findTrip();
        $trip->packingItems()->create(['label' => $data['label'], 'category' => $data['category'], 'person' => $data['person'] ?: null, 'position' => (int) $trip->packingItems()->max('position') + 1]);
        $this->item['label'] = '';
    }

    public function applyTemplate(): void
    {
        abort_unless(array_key_exists($this->template, TripPlanner::templates()), 422);
        $added = TripPlanner::applyTemplate($this->findTrip(), $this->template, mb_substr($this->templatePerson, 0, 60));
        $this->dispatch('toast', message: trans_choice(':count item added.|:count items added.', $added));
    }

    public function togglePacked(int $id): void
    {
        $item = $this->findTrip()->packingItems()->find($id) ?? abort(404);
        $item->update(['packed' => ! $item->packed]);
    }

    public function deleteItem(int $id): void
    {
        ($this->findTrip()->packingItems()->find($id) ?? abort(404))->delete();
    }

    public function unpackAll(): void
    {
        $this->findTrip()->packingItems()->update(['packed' => false]);
    }

    public function with(): array
    {
        $trip = $this->findTrip()->load(['bookings', 'packingItems']);

        return [
            'trip' => $trip,
            'overview' => TripPlanner::overview($trip, $this->currentUser()),
            'kinds' => TripBooking::kinds(),
            'categories' => TripPlanner::categories(),
            'templates' => TripPlanner::templates(),
            'people' => $trip->packingItems->pluck('person')->filter()->unique()->values(),
            'expenseGroups' => ExpenseGroup::query()->visibleTo($this->currentUser(), (int) $this->householdId)->where('trip_id', $trip->id)->get(),
            'places' => Place::query()->visibleTo($this->currentUser(), (int) $this->householdId)->where('trip_id', $trip->id)->orderBy('visited')->orderBy('name')->get(),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
    $levels = ['critical' => 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-900/30 dark:text-red-200 dark:ring-red-800', 'warning' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-200 dark:ring-amber-800', 'hint' => 'bg-gray-50 text-gray-700 ring-gray-200 dark:bg-gray-900/40 dark:text-gray-300 dark:ring-gray-700'];
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('trips.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ __('Trips') }}</a>
            <h2 class="flex w-full flex-wrap items-center gap-2 font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">✈️ {{ $trip->title }} <x-audience-badge :model="$trip" /></h2>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex max-w-full gap-1 overflow-x-auto rounded-lg bg-white p-1 shadow-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" role="tablist">
                @foreach (['overview' => '🧭 '.__('Overview'), 'bookings' => '🎫 '.__('Bookings'), 'packing' => '🧳 '.__('Packing list')] as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" @class(['shrink-0 rounded-md px-3 py-1.5 text-sm', 'bg-indigo-600 text-white' => $tab === $key, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $tab !== $key])>{{ $label }}</button>
                @endforeach
                <a href="{{ route('trips.group', $trip) }}" wire:navigate class="shrink-0 rounded-md px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">👥 {{ __('Group & trip folder') }}</a>
            </div>

            @if ($tab === 'overview')
                <livewire:sharing.panel type="trip" :id="$trip->id" :wire:key="'share-trip-'.$trip->id" />
                <section class="flex flex-wrap items-center gap-3 rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                    <span class="text-2xl" aria-hidden="true">🧾</span>
                    <p class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">{{ __('Share costs on the trip: who paid what and who owes whom.') }}</p>
                    @forelse ($expenseGroups as $expenseGroup)
                        <a href="{{ route('splits.show', $expenseGroup) }}" wire:navigate class="rounded-lg bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/40 dark:text-indigo-200">{{ $expenseGroup->icon }} {{ $expenseGroup->title }}</a>
                    @empty
                        <a href="{{ route('splits.index', ['trip' => $trip->id]) }}" wire:navigate class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">+ {{ __('Split costs') }}</a>
                    @endforelse
                </section>
                @if ($places->isNotEmpty())
                    <section class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800" aria-labelledby="trip-places">
                        <div class="flex items-center justify-between gap-3">
                            <h3 id="trip-places" class="font-semibold text-gray-900 dark:text-gray-100">📍 {{ __('Places for this trip') }}</h3>
                            <a href="{{ route('places.index') }}" wire:navigate class="text-sm text-indigo-600 underline dark:text-indigo-400">{{ __('All places') }}</a>
                        </div>
                        <ul class="mt-2 grid gap-1 sm:grid-cols-2">
                            @foreach ($places as $place)
                                <li wire:key="trip-place-{{ $place->id }}" class="flex items-center gap-2 text-sm">
                                    <span aria-hidden="true">{{ \App\Models\Place::categories()[$place->category]['icon'] ?? '📍' }}</span>
                                    <a href="{{ $place->mapsUrl() }}" target="_blank" rel="noopener noreferrer" @class(['min-w-0 flex-1 truncate hover:underline', 'text-gray-900 dark:text-gray-100' => ! $place->visited, 'text-gray-500 dark:text-gray-400' => $place->visited])>{{ $place->name }}</a>
                                    @if ($place->visited)
                                        <span class="text-xs text-emerald-600 dark:text-emerald-400">✓</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                <section class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $trip->isPast() ? __('Travelled') : ($trip->isOngoing() ? __('On the road') : __('Departure')) }}</p>
                        <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $trip->isOngoing() || $trip->isPast() ? $trip->starts_on->format('d.m.') : trans_choice(':count day|:count days', $overview['days_until']) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $trip->starts_on->translatedFormat('D, j. M') }} – {{ $trip->ends_on->translatedFormat('D, j. M Y') }} · {{ trans_choice(':count night|:count nights', $overview['nights']) }}</p>
                    </div>
                    <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Costs') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($overview['costs']) }}</p>
                        @if ($overview['budget_percent'] !== null)
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ (int) $overview['budget_percent'] }}" aria-valuemin="0" aria-valuemax="100"><div @class(['h-full rounded-full', 'bg-emerald-500' => $overview['budget_percent'] < 90, 'bg-amber-500' => $overview['budget_percent'] >= 90 && $overview['budget_percent'] <= 100, 'bg-red-500' => $overview['budget_percent'] > 100]) style="width: {{ min(100, $overview['budget_percent']) }}%"></div></div>
                            <p @class(['mt-1 text-xs', 'text-red-600 dark:text-red-400' => $overview['budget_left'] < 0, 'text-gray-500 dark:text-gray-400' => $overview['budget_left'] >= 0])>{{ $overview['budget_left'] >= 0 ? __(':amount of the budget left', ['amount' => $euro($overview['budget_left'])]) : __(':amount over budget', ['amount' => $euro(-$overview['budget_left'])]) }}</p>
                        @elseif ($overview['open'] > 0)
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __(':amount still to pay', ['amount' => $euro($overview['open'])]) }}</p>
                        @endif
                    </div>
                    <button type="button" wire:click="$set('tab', 'packing')" class="rounded-2xl bg-white p-5 text-left shadow-sm transition hover:ring-1 hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Packing list') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $overview['packed'] }} / {{ $overview['packing_total'] }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('packed') }}</p>
                    </button>
                </section>

                @if ($overview['warnings'] !== [])
                    <section class="space-y-2">
                        @foreach ($overview['warnings'] as $warning)
                            <div class="flex flex-wrap items-center gap-2 rounded-xl p-3 text-sm ring-1 {{ $levels[$warning['level']] }}" @if ($warning['level'] !== 'hint') role="alert" @endif>
                                <span aria-hidden="true">{{ ['critical' => '⛔', 'warning' => '⚠️', 'hint' => '💡'][$warning['level']] }}</span>
                                <span class="min-w-0 flex-1">{{ $warning['text'] }}</span>
                                @if ($warning['url'])<a href="{{ $warning['url'] }}" wire:navigate class="text-xs font-medium underline">{{ __('Check now') }}</a>@endif
                            </div>
                        @endforeach
                    </section>
                @endif

                @if ($overview['by_kind'] !== [])
                    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Costs by type') }}</h3>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($overview['by_kind'] as $kind => $amount)
                                <li class="flex justify-between gap-2"><span class="text-gray-700 dark:text-gray-200">{{ $kinds[$kind]['icon'] ?? '' }} {{ $kinds[$kind]['label'] ?? $kind }}</span><span class="tabular-nums text-gray-600 dark:text-gray-300">{{ $euro($amount) }}</span></li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">📎 {{ __('Tickets & documents') }}</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Booking confirmations, boarding passes and visas – stored encrypted.') }}</p>
                    <div class="mt-3"><livewire:documents-panel type="trip" :id="$trip->id" :wire:key="'documents-trip-'.$trip->id" /></div>
                </section>

                <form wire:submit="saveDetails" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 sm:col-span-6 dark:text-gray-100">✏️ {{ __('Details') }}</h3>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Title') }}<input type="text" wire:model="details.title" class="{{ $input }}"><x-input-error :messages="$errors->get('details.title')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Destination') }}<input type="text" wire:model="details.destination" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('From') }}<input type="date" wire:model="details.starts_on" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Until') }}<input type="date" wire:model="details.ends_on" class="{{ $input }}"><x-input-error :messages="$errors->get('details.ends_on')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Budget (€)') }}<input type="text" inputmode="decimal" wire:model="details.budget" class="{{ $input }}"><x-input-error :messages="$errors->get('details.budget')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('Travellers') }}<input type="text" wire:model="details.travelers" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('Notes') }}<textarea wire:model="details.notes" rows="3" class="{{ $input }}" placeholder="{{ __('Address of the accommodation, emergency numbers, ideas …') }}"></textarea></label>
                    @if ($trip->created_by === auth()->id())
                        <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-6 dark:text-gray-300"><input type="checkbox" wire:model="details.private" class="rounded border-gray-300 text-indigo-600"> {{ __('Only visible to me') }}</label>
                    @endif
                    <div class="flex flex-wrap gap-2 sm:col-span-6">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        @if ($trip->household_id === $householdId)
                            <button type="button" wire:click="deleteTrip" wire:confirm="{{ __('Delete this trip with all bookings and the packing list?') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete trip') }}</button>
                        @endif
                    </div>
                </form>
            @elseif ($tab === 'bookings')
                <form wire:submit="saveBooking" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 sm:col-span-6 dark:text-gray-100">{{ $editingBookingId ? __('Edit booking') : __('New booking') }}</h3>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Type') }}
                        <select wire:model="booking.kind" class="{{ $input }}">
                            @foreach ($kinds as $key => $kind)
                                <option value="{{ $key }}">{{ $kind['icon'] }} {{ $kind['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-4 dark:text-gray-300">{{ __('Title') }}<input type="text" wire:model="booking.title" class="{{ $input }}" placeholder="{{ __('e.g. FRA → PMI LH1234') }}"><x-input-error :messages="$errors->get('booking.title')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Start / check-in') }}<input type="datetime-local" wire:model="booking.starts_at" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('End / check-out') }}<input type="datetime-local" wire:model="booking.ends_at" class="{{ $input }}"><x-input-error :messages="$errors->get('booking.ends_at')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Provider') }}<input type="text" wire:model="booking.provider" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Booking code') }}<input type="text" wire:model="booking.reference" class="{{ $input }}" autocomplete="off"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Cost (€)') }}<input type="text" inputmode="decimal" wire:model="booking.cost" class="{{ $input }}"><x-input-error :messages="$errors->get('booking.cost')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('Notes') }}<input type="text" wire:model="booking.notes" class="{{ $input }}"></label>
                    <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-6 dark:text-gray-300"><input type="checkbox" wire:model="booking.paid" class="rounded border-gray-300 text-indigo-600"> {{ __('Already paid') }}</label>
                    <div class="flex gap-2 sm:col-span-6">
                        <x-primary-button>{{ $editingBookingId ? __('Save') : __('Add') }}</x-primary-button>
                        @if ($editingBookingId)
                            <x-secondary-button type="button" wire:click="cancelBooking">{{ __('Cancel') }}</x-secondary-button>
                            <button type="button" wire:click="deleteBooking({{ $editingBookingId }})" wire:confirm="{{ __('Delete this booking?') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                        @endif
                    </div>
                </form>

                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    @if ($trip->bookings->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No bookings yet. Flights, trains and check-ins with a time appear in the calendar and are reminded the day before.') }}</p>
                    @else
                        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($trip->bookings as $entry)
                                <li wire:key="booking-{{ $entry->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm">
                                    <span class="text-xl" aria-hidden="true">{{ $kinds[$entry->kind]['icon'] ?? '📌' }}</span>
                                    <button type="button" wire:click="editBooking({{ $entry->id }})" class="min-w-0 flex-1 text-left">
                                        <span class="block font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $entry->title }}</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ collect([$entry->starts_at?->translatedFormat('D, j. M H:i'), $entry->provider, $entry->reference ? __('Code :code', ['code' => $entry->reference]) : null])->filter()->implode(' · ') }}</span>
                                    </button>
                                    @if ($entry->cost !== null)
                                        <span class="tabular-nums text-gray-700 dark:text-gray-300">{{ $euro($entry->cost) }}</span>
                                        <button type="button" wire:click="togglePaid({{ $entry->id }})" @class(['rounded-full px-2 py-0.5 text-xs', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $entry->paid, 'bg-amber-50 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => ! $entry->paid])>{{ $entry->paid ? __('paid') : __('open') }}</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @else
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <form wire:submit="addItem" class="flex flex-wrap items-end gap-2">
                        <label class="min-w-0 flex-1 basis-full text-sm text-gray-700 sm:basis-0 dark:text-gray-300">{{ __('Item') }}<input type="text" wire:model="item.label" class="{{ $input }}" placeholder="{{ __('e.g. Snorkel') }}"><x-input-error :messages="$errors->get('item.label')" /></label>
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Category') }}
                            <select wire:model="item.category" class="{{ $input }}">
                                @foreach ($categories as $key => $category)
                                    <option value="{{ $key }}">{{ $category['icon'] }} {{ $category['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="w-28 text-sm text-gray-700 dark:text-gray-300">{{ __('For') }}<input type="text" wire:model="item.person" list="trip-people" class="{{ $input }}" placeholder="{{ __('everyone') }}"></label>
                        <x-primary-button>{{ __('Add') }}</x-primary-button>
                    </form>
                    <datalist id="trip-people">@foreach ($people as $person)<option value="{{ $person }}">@endforeach</datalist>

                    <form wire:submit="applyTemplate" class="mt-3 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3 dark:border-gray-700">
                        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Add template') }}
                            <select wire:model="template" class="{{ $input }}">
                                @foreach ($templates as $key => $packingTemplate)
                                    <option value="{{ $key }}">{{ $packingTemplate['icon'] }} {{ $packingTemplate['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="w-32 text-sm text-gray-700 dark:text-gray-300">{{ __('For') }}<input type="text" wire:model="templatePerson" list="trip-people" class="{{ $input }}" placeholder="{{ __('everyone') }}"></label>
                        <x-secondary-button type="submit">{{ __('Add') }}</x-secondary-button>
                        @if ($overview['packed'] > 0)
                            <button type="button" wire:click="unpackAll" class="ms-auto rounded-lg px-3 py-2 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">↺ {{ __('Unpack all') }}</button>
                        @endif
                    </form>
                </section>

                @if ($trip->packingItems->isEmpty())
                    <p class="rounded-2xl bg-white p-5 text-sm text-gray-500 shadow-sm dark:bg-gray-800 dark:text-gray-400">{{ __('The packing list is empty – add a template to start.') }}</p>
                @else
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach ($trip->packingItems->groupBy('category')->sortBy(fn ($items, $key) => array_search($key, array_keys($categories))) as $key => $items)
                            <section class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800" wire:key="category-{{ $key }}">
                                <h3 class="flex items-center justify-between text-sm font-semibold text-gray-900 dark:text-gray-100"><span>{{ $categories[$key]['icon'] ?? '' }} {{ $categories[$key]['label'] ?? $key }}</span><span class="text-xs font-normal text-gray-500">{{ $items->where('packed', true)->count() }}/{{ $items->count() }}</span></h3>
                                <ul class="mt-2 space-y-1">
                                    @foreach ($items as $entry)
                                        <li wire:key="pack-{{ $entry->id }}" class="group flex items-center gap-2 text-sm">
                                            <label class="flex min-w-0 flex-1 items-center gap-2 py-1 pointer-coarse:py-2">
                                                <input type="checkbox" @checked($entry->packed) wire:click="togglePacked({{ $entry->id }})" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900">
                                                <span @class(['min-w-0 truncate', 'text-gray-400 line-through dark:text-gray-500' => $entry->packed, 'text-gray-900 dark:text-gray-100' => ! $entry->packed])>{{ $entry->label }}</span>
                                                @if ($entry->person)<span class="shrink-0 rounded-full bg-indigo-50 px-2 text-xs text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ $entry->person }}</span>@endif
                                            </label>
                                            <button type="button" wire:click="deleteItem({{ $entry->id }})" class="rounded p-1 text-gray-400 opacity-0 hover:text-red-600 group-hover:opacity-100 focus:opacity-100 pointer-coarse:opacity-100" aria-label="{{ __('Remove :item', ['item' => $entry->label]) }}">✕</button>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
