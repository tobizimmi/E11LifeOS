<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\ExpenseGroup;
use App\Models\Item;
use App\Models\Place;
use App\Models\Trip;
use App\Models\TripBooking;
use App\Models\TripStop;
use App\Support\Life\TripCovers;
use App\Support\Life\PlaceFinder;
use App\Support\Life\TripItinerary;
use App\Support\Life\TripPlanner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * One trip, laid out like Lambus: a cover with countdown and travellers, the day-by-day plan with
 * ideas and drag & drop, routes per day, bookings with documents, costs and the packing list.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold, WithFileUploads;

    private const TABS = ['overview', 'plan', 'map', 'bookings', 'costs', 'packing'];

    #[Locked]
    public int $tripId;

    /** overview, plan, map, bookings, costs or packing */
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

    /** Cover photo while uploading (saved right away). */
    public $cover = null;

    /**
     * @var array{kind: string, title: string, day: string, time: string, end_time: string, location: string, lat: string, lng: string, cost: string, notes: string}
     */
    public array $stop = [];

    public ?int $editingStopId = null;

    public bool $stopSheet = false;

    public string $idea = '';

    public function mount(Trip $trip): void
    {
        $this->resolveHousehold();
        $this->tripId = $this->findTrip($trip->id)->id;
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'overview';
        $this->resetStop();
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

    public function updatedCover(): void
    {
        $this->validate(['cover' => ['image', 'max:15360']], attributes: ['cover' => __('Cover photo')]);
        $trip = $this->findTrip();
        $old = $trip->cover_path;
        $trip->update(['cover_path' => TripCovers::store($this->cover, $trip->household_id)]);
        TripCovers::delete($old);
        $this->cover = null;
    }

    public function removeCover(): void
    {
        $trip = $this->findTrip();
        TripCovers::delete($trip->cover_path);
        $trip->update(['cover_path' => null]);
    }

    public function setColor(string $color): void
    {
        abort_unless(array_key_exists($color, Trip::colorSchemes()), 422);
        $this->findTrip()->update(['color' => $color]);
    }

    private function resetStop(?string $day = null, string $kind = 'sight'): void
    {
        $this->stop = ['kind' => $kind, 'title' => '', 'day' => (string) $day, 'time' => '', 'end_time' => '', 'location' => '', 'lat' => '', 'lng' => '', 'cost' => '', 'notes' => ''];
        $this->editingStopId = null;
        $this->resetValidation();
    }

    /**
     * Opens the entry form for a day (Y-m-d) or, without one, for the ideas.
     */
    public function openStop(?string $day = null): void
    {
        $this->resetStop($day !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? $day : null);
        $this->stopSheet = true;
    }

    public function editStop(int $id): void
    {
        $stop = $this->findStop($id);
        $this->stop = [
            'kind' => $stop->kind, 'title' => $stop->title, 'day' => (string) $stop->day?->toDateString(),
            'time' => (string) $stop->time, 'end_time' => (string) $stop->end_time, 'location' => (string) $stop->location,
            'lat' => $stop->lat !== null ? (string) $stop->lat : '', 'lng' => $stop->lng !== null ? (string) $stop->lng : '',
            'cost' => $stop->cost !== null ? number_format($stop->cost, 2, ',', '') : '', 'notes' => (string) $stop->notes,
        ];
        $this->editingStopId = $stop->id;
        $this->resetValidation();
        $this->stopSheet = true;
    }

    public function closeStop(): void
    {
        $this->stopSheet = false;
        $this->resetStop();
    }

    private function findStop(int $id): TripStop
    {
        return $this->findTrip()->stops()->find($id) ?? abort(404);
    }

    public function saveStop(): void
    {
        $this->stop['cost'] = TripPlanner::number((string) $this->stop['cost']);
        $data = $this->validate([
            'stop.kind' => ['required', Rule::in(TripStop::KINDS)],
            'stop.title' => ['required', 'string', 'max:150'],
            'stop.day' => ['nullable', 'date_format:Y-m-d'],
            'stop.time' => ['nullable', 'date_format:H:i'],
            'stop.end_time' => ['nullable', 'date_format:H:i'],
            'stop.location' => ['nullable', 'string', 'max:255'],
            'stop.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'stop.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'stop.cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'stop.notes' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['stop.title' => __('Title'), 'stop.cost' => __('Cost (€)'), 'stop.time' => __('Time')])['stop'];

        $trip = $this->findTrip();
        $values = [
            'kind' => $data['kind'], 'title' => $data['title'], 'day' => $data['day'] ?: null,
            'time' => $data['time'] ?: null, 'end_time' => $data['end_time'] ?: null, 'location' => $data['location'] ?: null,
            'lat' => $data['lat'] !== '' && $data['lat'] !== null ? (float) $data['lat'] : null,
            'lng' => $data['lng'] !== '' && $data['lng'] !== null ? (float) $data['lng'] : null,
            'cost' => $data['cost'] !== '' ? $data['cost'] : null, 'notes' => $data['notes'] ?: null,
        ];
        $previous = $this->editingStopId ? $this->findStop($this->editingStopId) : null;

        // A new or changed address gets its position on the map (unless it was picked on the map).
        if ($values['location'] && ($previous === null ? $values['lat'] === null : ($previous->location !== $values['location'] && $previous->lat === $values['lat']))) {
            $position = $this->limited(fn () => PlaceFinder::locate($values['location']));
            $values['lat'] = $position['lat'] ?? null;
            $values['lng'] = $position['lng'] ?? null;
        } elseif (! $values['location'] && $previous?->location) {
            $values['lat'] = $values['lng'] = null;
        }

        if ($this->editingStopId) {
            $stop = $this->findStop($this->editingStopId);
            $moved = $stop->day?->toDateString() !== $values['day'];
            $stop->update($values + ($moved ? ['position' => $this->nextPosition($trip, $values['day'])] : []));
        } else {
            $trip->stops()->create($values + ['position' => $this->nextPosition($trip, $values['day']), 'created_by' => Auth::id()]);
        }

        $this->closeStop();
        $this->dispatch('toast', message: __('Saved.'));
    }

    /**
     * Quick idea from the plan: just a title, planned later.
     */
    public function addIdea(): void
    {
        $title = trim($this->idea);

        if ($title === '') {
            return;
        }

        $trip = $this->findTrip();
        $trip->stops()->create(['kind' => 'sight', 'title' => mb_substr($title, 0, 150), 'position' => $this->nextPosition($trip, null), 'created_by' => Auth::id()]);
        $this->idea = '';
    }

    private function nextPosition(Trip $trip, ?string $day): int
    {
        return (int) $trip->stops()->when($day, fn ($query) => $query->whereDate('day', $day), fn ($query) => $query->whereNull('day'))->max('position') + 1;
    }

    /**
     * Drag & drop in the plan: puts the entry on the day ("ideas" for none) at the given position.
     */
    public function moveStop(int $id, string $day, int $position): void
    {
        $trip = $this->findTrip();
        $stop = $this->findStop($id);
        $targetDay = $day === 'ideas' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) ? null : $day;
        $siblings = $trip->stops()->where('id', '!=', $stop->id)
            ->when($targetDay, fn ($query) => $query->whereDate('day', $targetDay), fn ($query) => $query->whereNull('day'))
            ->get()->values();
        $siblings->splice(max(0, min($position, $siblings->count())), 0, [$stop]);

        foreach ($siblings as $index => $sibling) {
            $sibling->forceFill(['position' => $index + 1] + ($sibling->is($stop) ? ['day' => $targetDay] : []))->save();
        }
    }

    public function toggleStopDone(int $id): void
    {
        $stop = $this->findStop($id);
        $stop->update(['done' => ! $stop->done]);
    }

    public function deleteStop(int $id): void
    {
        $this->findStop($id)->delete();
        $this->closeStop();
    }

    /**
     * Calls OpenStreetMap at most 40 times a minute per person; null when over the limit.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function limited(callable $callback): mixed
    {
        $key = 'places:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, 40)) {
            return null;
        }

        RateLimiter::hit($key, 60);

        return $callback();
    }

    /**
     * Place search on the map (results in the visible area first).
     *
     * @param  list<float>  $bounds  south, west, north, east
     * @return list<array{name: string, address: string, lat: float, lng: float, kind: string}>
     */
    public function searchPlaces(string $query, array $bounds = []): array
    {
        return $this->limited(fn () => PlaceFinder::search($query, count($bounds) === 4 ? array_map('floatval', array_values($bounds)) : null)) ?? [];
    }

    /**
     * What is at a point tapped on the map.
     *
     * @return ?array{name: string, address: string, lat: float, lng: float, kind: string}
     */
    public function placeAt(float $lat, float $lng): ?array
    {
        abort_unless(abs($lat) <= 90 && abs($lng) <= 180, 422);

        return $this->limited(fn () => PlaceFinder::reverse($lat, $lng));
    }

    /**
     * Sights and restaurants in the visible map area.
     *
     * @param  list<float>  $bounds  south, west, north, east
     * @return list<array{name: string, address: string, lat: float, lng: float, kind: string}>
     */
    public function placesNearby(array $bounds): array
    {
        abort_unless(count($bounds) === 4, 422);

        return $this->limited(fn () => PlaceFinder::nearby(array_map('floatval', array_values($bounds)))) ?? [];
    }

    /**
     * Adds a place chosen on the map to a day ("ideas" for none).
     *
     * @param  array{name?: mixed, address?: mixed, lat?: mixed, lng?: mixed, kind?: mixed}  $place
     */
    public function addPlace(array $place, string $day = 'ideas'): void
    {
        $data = validator(['place' => $place, 'day' => $day], [
            'place.name' => ['required', 'string', 'max:150'],
            'place.address' => ['nullable', 'string', 'max:255'],
            'place.lat' => ['required', 'numeric', 'between:-90,90'],
            'place.lng' => ['required', 'numeric', 'between:-180,180'],
            'place.kind' => ['nullable', Rule::in(TripStop::KINDS)],
            'day' => ['required', 'string'],
        ])->validate();

        $trip = $this->findTrip();
        $targetDay = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['day']) ? $data['day'] : null;
        $trip->stops()->create([
            'kind' => $data['place']['kind'] ?? 'sight', 'title' => $data['place']['name'], 'day' => $targetDay,
            'location' => ($data['place']['address'] ?? null) ?: null, 'lat' => (float) $data['place']['lat'], 'lng' => (float) $data['place']['lng'],
            'position' => $this->nextPosition($trip, $targetDay), 'created_by' => Auth::id(),
        ]);
        $this->dispatch('toast', message: __(':place added to the plan.', ['place' => $data['place']['name']]));
    }

    /**
     * Finds the map position of entries that only have an address (a few at a time).
     */
    public function locateMissing(): void
    {
        $found = 0;

        foreach ($this->findTrip()->stops()->whereNull('lat')->whereNotNull('location')->limit(5)->get() as $index => $stop) {
            if ($index > 0) {
                usleep(1_000_000); // OpenStreetMap allows one search per second.
            }

            $position = $this->limited(fn () => PlaceFinder::locate((string) $stop->location));

            if ($position) {
                $stop->update($position);
                $found++;
            }
        }

        $this->dispatch('toast', message: trans_choice(':count place found on the map.|:count places found on the map.', $found));
    }

    /**
     * Opens a booking from the plan in the bookings tab.
     */
    public function showBooking(int $id): void
    {
        $this->tab = 'bookings';
        $this->editBooking($id);
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
        $trip = $this->findTrip()->load(['bookings', 'packingItems', 'stops', 'participants']);

        return [
            'trip' => $trip,
            'itinerary' => $itinerary = TripItinerary::build($trip),
            'mapGroups' => $this->tab === 'map' ? TripItinerary::mapGroups($itinerary) : [],
            'mapCenter' => $this->tab === 'map' && $trip->destination ? Cache::remember('places:center:'.md5($trip->destination), now()->addDays(30), fn () => PlaceFinder::locate((string) $trip->destination)) : null,
            'missingPositions' => $trip->stops->filter(fn (TripStop $stop) => $stop->location && ! $stop->hasPosition())->count(),
            'stopKinds' => TripStop::kinds(),
            'colors' => Trip::colorSchemes(),
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
    $scheme = $trip->colorScheme();
    $travellers = $trip->travellerNames();
    $dayLabel = fn (\Illuminate\Support\Carbon $date, int $number) => __('Day :number', ['number' => $number]).' · '.$date->translatedFormat('D, j. M');
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('trips.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ __('Trips') }}</a>
            <h2 class="flex w-full flex-wrap items-center gap-2 font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $trip->title }} <x-audience-badge :model="$trip" /></h2>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            {{-- Cover: photo or colour, countdown, dates and travellers (like Lambus) --}}
            <section class="relative isolate overflow-hidden rounded-3xl shadow-sm" style="background-image: linear-gradient(135deg, {{ $scheme['from'] }}, {{ $scheme['to'] }})">
                @if ($trip->coverUrl())
                    <img src="{{ $trip->coverUrl() }}" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover">
                @endif
                <div class="absolute inset-0 -z-10 bg-gradient-to-t from-black/70 via-black/20 to-black/10"></div>
                <div class="flex min-h-52 flex-col justify-between gap-6 p-5 text-white sm:min-h-64 sm:p-6">
                    <div class="flex items-start justify-between gap-3">
                        <span class="rounded-full bg-white/20 px-3 py-1 text-sm font-medium backdrop-blur">
                            @if ($trip->isPast())
                                {{ __('Travelled') }}
                            @elseif ($trip->isOngoing())
                                {{ __('On the road') }} · {{ __('Day :number', ['number' => (int) $trip->starts_on->diffInDays(today()) + 1]) }}
                            @else
                                {{ trans_choice('in :count day|in :count days', $overview['days_until']) }}
                            @endif
                        </span>
                        <div class="flex items-center gap-1" x-data="{ open: false }">
                            <label class="cursor-pointer rounded-full bg-white/20 p-2 backdrop-blur hover:bg-white/30" title="{{ __('Change cover photo') }}">
                                <span class="sr-only">{{ __('Change cover photo') }}</span>
                                <span aria-hidden="true" wire:loading.remove wire:target="cover">📷</span>
                                <span aria-hidden="true" wire:loading wire:target="cover">⏳</span>
                                <input type="file" accept="image/*" wire:model="cover" class="sr-only">
                            </label>
                            <button type="button" x-on:click="open = ! open" class="rounded-full bg-white/20 p-2 backdrop-blur hover:bg-white/30" title="{{ __('Colour') }}" aria-label="{{ __('Colour') }}">🎨</button>
                            <div x-show="open" x-cloak x-on:click.outside="open = false" class="absolute end-5 top-16 z-10 flex gap-2 rounded-2xl bg-white p-2 shadow-lg dark:bg-gray-800">
                                @foreach ($colors as $key => $color)
                                    <button type="button" wire:click="setColor('{{ $key }}')" x-on:click="open = false" class="h-8 w-8 rounded-full ring-2 ring-offset-2 {{ $trip->color === $key ? 'ring-gray-900 dark:ring-white' : 'ring-transparent' }}" style="background-image: linear-gradient(135deg, {{ $color['from'] }}, {{ $color['to'] }})" aria-label="{{ $key }}"></button>
                                @endforeach
                                @if ($trip->cover_path)
                                    <button type="button" wire:click="removeCover" x-on:click="open = false" class="rounded-full px-2 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">{{ __('Remove photo') }}</button>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div class="min-w-0">
                            @if ($trip->destination)
                                <p class="text-sm font-medium uppercase tracking-wide text-white/80">📍 {{ $trip->destination }}</p>
                            @endif
                            <p class="text-3xl font-bold leading-tight drop-shadow sm:text-4xl">{{ $trip->title }}</p>
                            <p class="mt-1 text-sm text-white/90">{{ $trip->starts_on->translatedFormat('j. M') }} – {{ $trip->ends_on->translatedFormat('j. M Y') }} · {{ trans_choice(':count night|:count nights', $overview['nights']) }}</p>
                        </div>
                        @if ($travellers !== [])
                            <div class="flex -space-x-2" title="{{ implode(', ', $travellers) }}">
                                @foreach (array_slice($travellers, 0, 4) as $name)
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-white/90 text-sm font-semibold text-gray-800 ring-2 ring-white/60">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>
                                @endforeach
                                @if (count($travellers) > 4)
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-black/40 text-xs font-semibold text-white ring-2 ring-white/60">+{{ count($travellers) - 4 }}</span>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
                <x-input-error :messages="$errors->get('cover')" class="px-5 pb-3 text-white" />
            </section>

            <div class="sticky top-14 z-10 -mx-1 flex max-w-full gap-1 overflow-x-auto rounded-2xl bg-white/90 p-1 shadow-sm ring-1 ring-gray-200 backdrop-blur lg:top-2 dark:bg-gray-800/90 dark:ring-gray-700" role="tablist">
                @foreach (['overview' => ['🧭', __('Overview')], 'plan' => ['🗓️', __('Itinerary')], 'map' => ['🗺️', __('Map')], 'bookings' => ['🎫', __('Bookings')], 'costs' => ['💶', __('Costs')], 'packing' => ['🧳', __('Packing list')]] as $key => [$icon, $label])
                    <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" @class(['flex shrink-0 flex-col items-center rounded-xl px-3 py-1.5 text-xs sm:flex-row sm:gap-1.5 sm:text-sm', 'bg-indigo-600 text-white' => $tab === $key, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $tab !== $key])><span class="text-base" aria-hidden="true">{{ $icon }}</span>{{ $label }}</button>
                @endforeach
                <a href="{{ route('trips.group', $trip) }}" wire:navigate class="flex shrink-0 flex-col items-center rounded-xl px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-100 sm:flex-row sm:gap-1.5 sm:text-sm dark:text-gray-300 dark:hover:bg-gray-700"><span class="text-base" aria-hidden="true">👥</span>{{ __('Group') }}</a>
            </div>

            @if ($tab === 'overview')
                @if ($itinerary['next'])
                    @php($next = $itinerary['next'])
                    <button type="button" wire:click="$set('tab', 'plan')" class="flex w-full items-center gap-4 rounded-2xl bg-white p-4 text-left shadow-sm transition hover:ring-1 hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-50 text-2xl dark:bg-indigo-900/40" aria-hidden="true">{{ $next['icon'] }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">{{ __('Up next') }}</span>
                            <span class="block truncate font-semibold text-gray-900 dark:text-gray-100">{{ $next['title'] }}</span>
                            <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $next['when']->isToday() ? __('Today') : ($next['when']->isTomorrow() ? __('Tomorrow') : $next['when']->translatedFormat('D, j. M')) }}@if ($next['when']->format('H:i') !== '23:59') · {{ $next['when']->format('H:i') }}@endif @if ($next['day']) · {{ __('Day :number', ['number' => $next['day']]) }}@endif</span>
                        </span>
                        <span class="text-gray-300" aria-hidden="true">›</span>
                    </button>
                @endif

                <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <button type="button" wire:click="$set('tab', 'plan')" class="rounded-2xl bg-white p-4 text-left shadow-sm transition hover:ring-1 hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400">🗓️ {{ __('Itinerary') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $itinerary['planned_days'] }} / {{ count($itinerary['days']) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('days planned') }}@if ($itinerary['ideas']->isNotEmpty()) · {{ trans_choice(':count idea|:count ideas', $itinerary['ideas']->count()) }}@endif</p>
                    </button>
                    <button type="button" wire:click="$set('tab', 'costs')" class="rounded-2xl bg-white p-4 text-left shadow-sm transition hover:ring-1 hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400">💶 {{ __('Costs') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($overview['costs']) }}</p>
                        @if ($overview['budget_percent'] !== null)
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ (int) $overview['budget_percent'] }}" aria-valuemin="0" aria-valuemax="100"><div @class(['h-full rounded-full', 'bg-emerald-500' => $overview['budget_percent'] < 90, 'bg-amber-500' => $overview['budget_percent'] >= 90 && $overview['budget_percent'] <= 100, 'bg-red-500' => $overview['budget_percent'] > 100]) style="width: {{ min(100, $overview['budget_percent']) }}%"></div></div>
                        @endif
                    </button>
                    <button type="button" wire:click="$set('tab', 'bookings')" class="rounded-2xl bg-white p-4 text-left shadow-sm transition hover:ring-1 hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400">🎫 {{ __('Bookings') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $trip->bookings->count() }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">@if ($overview['open'] > 0){{ __(':amount still to pay', ['amount' => $euro($overview['open'])]) }}@else{{ __('Tickets & documents') }}@endif</p>
                    </button>
                    <button type="button" wire:click="$set('tab', 'packing')" class="rounded-2xl bg-white p-4 text-left shadow-sm transition hover:ring-1 hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400">🧳 {{ __('Packing list') }}</p>
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

                @if ($trip->notes)
                    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">📝 {{ __('Notes') }}</h3>
                        <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $trip->notes }}</p>
                    </section>
                @endif

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
                <details class="group rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                    <summary class="flex cursor-pointer list-none items-center justify-between p-5 font-semibold text-gray-900 dark:text-gray-100">✏️ {{ __('Edit trip') }} <span class="text-gray-400 transition group-open:rotate-90" aria-hidden="true">›</span></summary>
                    <div class="px-0 pb-0">
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
                    </div>
                </details>
            @elseif ($tab === 'plan')
                <form wire:submit="addIdea" class="flex flex-wrap gap-2">
                    <input type="text" wire:model="idea" class="block min-w-0 flex-1 basis-full rounded-xl sm:basis-0 border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300" placeholder="💡 {{ __('Add an idea – plan it on a day later …') }}" aria-label="{{ __('Add an idea') }}">
                    <x-secondary-button type="submit" class="flex-1 justify-center sm:flex-none">💡 {{ __('Add idea') }}</x-secondary-button>
                    <x-primary-button type="button" wire:click="openStop" class="flex-1 justify-center sm:flex-none">+ {{ __('Entry') }}</x-primary-button>
                </form>

                <section class="rounded-2xl bg-amber-50/70 p-4 ring-1 ring-amber-100 dark:bg-amber-900/10 dark:ring-amber-900/40">
                    <h3 class="text-sm font-semibold text-amber-900 dark:text-amber-200">💡 {{ __('Ideas & wishlist') }}</h3>
                    <p class="text-xs text-amber-700 dark:text-amber-300">{{ __('Drag onto a day to plan') }} (⠿)</p>
                    <ul class="mt-2 min-h-10 space-y-2" x-sort="$wire.moveStop($item, 'ideas', $position)" x-sort:group="trip-plan">
                        @forelse ($itinerary['ideas'] as $entry)
                            @include('livewire.trips.partials.stop', ['entry' => $entry, 'stopKinds' => $stopKinds, 'euro' => $euro])
                        @empty
                            <li class="rounded-xl border border-dashed border-amber-200 p-3 text-center text-xs text-amber-700 dark:border-amber-800 dark:text-amber-300">{{ __('Collect sights, restaurants and tips here.') }}</li>
                        @endforelse
                    </ul>
                </section>

                @foreach ($itinerary['days'] as $day)
                    <section class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800" wire:key="day-{{ $day['date']->toDateString() }}">
                        <div class="flex items-center gap-3">
                            <span @class(['flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-xl text-white', 'ring-2 ring-offset-2 ring-indigo-400 dark:ring-offset-gray-800' => $day['date']->isToday()]) style="background-image: linear-gradient(135deg, {{ $scheme['from'] }}, {{ $scheme['to'] }})">
                                <span class="text-[10px] uppercase leading-none opacity-90">{{ __('Day') }}</span>
                                <span class="text-base font-bold leading-none">{{ $day['number'] }}</span>
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $day['date']->translatedFormat('l, j. F') }}</h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $day['date']->isToday() ? __('Today').' · ' : '' }}{{ trans_choice(':count entry|:count entries', $day['stops']->count() + count($day['bookings'])) }}</p>
                            </div>
                            @if ($day['route'])
                                <a href="{{ $day['route'] }}" target="_blank" rel="noopener noreferrer" class="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200">🗺️ {{ __('Route') }}</a>
                            @endif
                            <button type="button" wire:click="openStop('{{ $day['date']->toDateString() }}')" class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-600 text-lg text-white hover:bg-indigo-500" aria-label="{{ __('Add entry to :day', ['day' => $dayLabel($day['date'], $day['number'])]) }}">+</button>
                        </div>

                        @if ($day['bookings'] !== [])
                            <ul class="mt-3 space-y-2">
                                @foreach ($day['bookings'] as $event)
                                    <li wire:key="day-booking-{{ $event['booking']->id }}-{{ $event['time'] }}-{{ $loop->index }}">
                                        <button type="button" wire:click="showBooking({{ $event['booking']->id }})" class="flex w-full items-center gap-3 rounded-xl bg-indigo-50/70 p-2.5 text-left text-sm ring-1 ring-indigo-100 hover:ring-indigo-300 dark:bg-indigo-900/20 dark:ring-indigo-900/60">
                                            <span class="w-11 shrink-0 text-center text-xs font-semibold tabular-nums text-indigo-700 dark:text-indigo-300">{{ $event['time'] }}</span>
                                            <span class="text-lg" aria-hidden="true">{{ $kinds[$event['booking']->kind]['icon'] ?? '📌' }}</span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $event['label'] }}</span>
                                                <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ collect([$event['label'] !== $event['booking']->title ? $event['booking']->title : null, $event['booking']->provider])->filter()->implode(' · ') ?: ($kinds[$event['booking']->kind]['label'] ?? '') }}</span>
                                            </span>
                                            <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-gray-800 dark:text-indigo-300">🎫 {{ __('Booking') }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <ul class="mt-2 min-h-10 space-y-2" x-sort="$wire.moveStop($item, '{{ $day['date']->toDateString() }}', $position)" x-sort:group="trip-plan">
                            @foreach ($day['stops'] as $entry)
                                @include('livewire.trips.partials.stop', ['entry' => $entry, 'stopKinds' => $stopKinds, 'euro' => $euro])
                            @endforeach
                            @if ($day['stops']->isEmpty() && $day['bookings'] === [])
                                <li class="rounded-xl border border-dashed border-gray-200 p-3 text-center text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ __('Nothing planned yet – tap + or drag an idea here.') }}</li>
                            @endif
                        </ul>
                    </section>
                @endforeach
            @elseif ($tab === 'map')
                @php($mapTexts = ['edit' => __('Edit'), 'add' => __('Add to plan'), 'idea' => __('Idea – no day yet'), 'searching' => __('Looking up the place …'), 'pinned' => __('Pinned place'), 'zoomIn' => __('Nothing found here – zoom in to a town or district.'), 'icons' => collect($stopKinds)->map(fn ($kind) => $kind['icon'])->all()])
                <section class="relative overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800" x-data="tripMap(@js($mapTexts), @js($mapCenter))" wire:key="trip-map-{{ $trip->id }}">
                    <div x-ref="data" class="hidden" data-groups="{{ json_encode($mapGroups) }}"></div>
                    <div class="relative z-[500] flex flex-wrap gap-2 p-3">
                        <div class="relative min-w-0 flex-1 basis-full sm:basis-0">
                            <input type="search" x-model="query" x-on:input.debounce.500ms="search()" x-on:keydown.escape="results = []" placeholder="🔎 {{ __('Search a place, sight or restaurant …') }}" aria-label="{{ __('Search a place') }}" class="block w-full rounded-xl border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <ul x-show="results.length > 0" x-cloak class="absolute inset-x-0 top-full z-[1000] mt-1 max-h-72 overflow-y-auto rounded-xl bg-white py-1 shadow-lg ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                                <template x-for="place in results" :key="place.lat + ',' + place.lng">
                                    <li><button type="button" x-on:click="choose(place)" class="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-700"><span class="block font-medium text-gray-900 dark:text-gray-100" x-text="place.name"></span><span class="block truncate text-xs text-gray-500 dark:text-gray-400" x-text="place.address"></span></button></li>
                                </template>
                            </ul>
                            <span x-show="searching" x-cloak class="absolute end-3 top-2.5 text-xs text-gray-400">…</span>
                        </div>
                        <button type="button" x-on:click="nearby()" x-bind:disabled="loadingNearby" class="flex-1 rounded-xl bg-gray-100 px-3 py-2 text-sm font-medium text-gray-800 hover:bg-gray-200 sm:flex-none dark:bg-gray-700 dark:text-gray-100"><span x-show="! loadingNearby">📍 {{ __('Places nearby') }}</span><span x-show="loadingNearby" x-cloak>⏳ {{ __('Searching …') }}</span></button>
                        <button type="button" x-on:click="fitAll()" class="rounded-xl bg-gray-100 px-3 py-2 text-sm text-gray-800 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-100" title="{{ __('Show all') }}" aria-label="{{ __('Show all') }}">⤢</button>
                    </div>
                    <div wire:ignore class="relative z-0">
                        <div x-ref="map" class="h-[58vh] min-h-80 w-full bg-gray-100 dark:bg-gray-900"></div>
                        <p x-show="! ready" class="absolute inset-0 flex items-center justify-center text-sm text-gray-500">{{ __('Loading map …') }}</p>
                    </div>
                    <p class="px-3 pt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Tap the map or search to add a place to a day.') }}</p>
                    <div class="p-3">
                        <h3 class="mb-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('Day plan') }}</h3>
                        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                            <template x-for="group in groups" :key="group.key">
                                <li>
                                    <button type="button" x-on:click="toggle(group.key)" class="flex w-full items-center gap-3 py-2 text-left">
                                        <span class="h-5 w-5 shrink-0 rounded-full ring-2 ring-white dark:ring-gray-800" x-bind:style="'background:' + group.color" x-bind:class="hidden.includes(group.key) && 'opacity-30'"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100" x-text="group.label"></span>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400" x-text="group.pins.length + ' / ' + group.count + ' {{ __('on the map') }}'"></span>
                                        </span>
                                        <span class="text-lg" x-bind:class="hidden.includes(group.key) ? 'text-gray-300 dark:text-gray-600' : 'text-emerald-500'" aria-hidden="true">✓</span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </section>
                @if ($missingPositions > 0)
                    <section class="flex flex-wrap items-center gap-3 rounded-2xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-100 dark:bg-amber-900/20 dark:text-amber-200 dark:ring-amber-900/40">
                        <span class="min-w-0 flex-1">{{ trans_choice(':count entry has an address but no place on the map yet.|:count entries have an address but no place on the map yet.', $missingPositions) }}</span>
                        <button type="button" wire:click="locateMissing" wire:loading.attr="disabled" class="rounded-full bg-amber-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-amber-500"><span wire:loading.remove wire:target="locateMissing">📍 {{ __('Find on the map') }}</span><span wire:loading wire:target="locateMissing">⏳</span></button>
                    </section>
                @endif
                @php($mappedDays = collect($itinerary['days'])->filter(fn ($day) => $day['route'] !== null))
                @php($mappedIdeas = $itinerary['ideas']->filter(fn ($entry) => $entry->mapsUrl() !== null))
                @foreach ($mappedDays as $day)
                    <section class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800" wire:key="map-day-{{ $day['number'] }}">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $dayLabel($day['date'], $day['number']) }}</h3>
                            <a href="{{ $day['route'] }}" target="_blank" rel="noopener noreferrer" class="rounded-full bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-500">🧭 {{ __('Open route') }}</a>
                        </div>
                        <ol class="mt-3 space-y-2">
                            @foreach ($day['stops']->filter(fn ($entry) => $entry->mapsUrl() && $entry->kind !== 'note')->values() as $index => $entry)
                                <li class="flex items-center gap-3 text-sm" wire:key="map-stop-{{ $entry->id }}">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white" style="background-color: {{ $scheme['to'] }}">{{ $index + 1 }}</span>
                                    <span class="min-w-0 flex-1"><span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $stopKinds[$entry->kind]['icon'] ?? '' }} {{ $entry->title }}</span><span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $entry->location }}</span></span>
                                    <a href="{{ $entry->mapsUrl() }}" target="_blank" rel="noopener noreferrer" class="text-xs text-indigo-600 underline dark:text-indigo-400">{{ __('Map') }}</a>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endforeach
                @if ($mappedIdeas->isNotEmpty())
                    <section class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">💡 {{ __('Ideas') }}</h3>
                        <ul class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($mappedIdeas as $entry)
                                <li wire:key="map-idea-{{ $entry->id }}"><a href="{{ $entry->mapsUrl() }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2 rounded-xl bg-gray-50 p-2 text-sm hover:bg-gray-100 dark:bg-gray-900/40 dark:hover:bg-gray-700"><span aria-hidden="true">{{ $stopKinds[$entry->kind]['icon'] ?? '📍' }}</span><span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ $entry->title }}</span><span class="truncate text-xs text-gray-500">{{ $entry->location }}</span></a></li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                @if ($places->isNotEmpty())
                    <section class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">📍 {{ __('Places for this trip') }}</h3>
                        <ul class="mt-2 grid gap-2 sm:grid-cols-2">
                            @foreach ($places as $place)
                                <li wire:key="map-place-{{ $place->id }}"><a href="{{ $place->mapsUrl() }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2 rounded-xl bg-gray-50 p-2 text-sm hover:bg-gray-100 dark:bg-gray-900/40 dark:hover:bg-gray-700"><span aria-hidden="true">{{ \App\Models\Place::categories()[$place->category]['icon'] ?? '📍' }}</span><span class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100">{{ $place->name }}</span></a></li>
                            @endforeach
                        </ul>
                    </section>
                @endif
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
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">📎 {{ __('Tickets & documents') }}</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Booking confirmations, boarding passes and visas – stored encrypted.') }}</p>
                    <div class="mt-3"><livewire:documents-panel type="trip" :id="$trip->id" :wire:key="'documents-trip-'.$trip->id" /></div>
                </section>
            @elseif ($tab === 'costs')
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Costs of the trip') }}</p>
                    <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($overview['costs']) }}</p>
                    @if ($overview['budget_percent'] !== null)
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ (int) $overview['budget_percent'] }}" aria-valuemin="0" aria-valuemax="100"><div @class(['h-full rounded-full', 'bg-emerald-500' => $overview['budget_percent'] < 90, 'bg-amber-500' => $overview['budget_percent'] >= 90 && $overview['budget_percent'] <= 100, 'bg-red-500' => $overview['budget_percent'] > 100]) style="width: {{ min(100, $overview['budget_percent']) }}%"></div></div>
                        <p @class(['mt-1 text-sm', 'text-red-600 dark:text-red-400' => $overview['budget_left'] < 0, 'text-gray-500 dark:text-gray-400' => $overview['budget_left'] >= 0])>{{ $overview['budget_left'] >= 0 ? __(':amount of the budget left', ['amount' => $euro($overview['budget_left'])]) : __(':amount over budget', ['amount' => $euro(-$overview['budget_left'])]) }}</p>
                    @else
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Set a budget under Overview → Edit trip to see how much is left.') }}</p>
                    @endif
                    @if ($overview['open'] > 0)
                        <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">{{ __(':amount still to pay', ['amount' => $euro($overview['open'])]) }}</p>
                    @endif
                </section>

                @if ($overview['by_kind'] !== [])
                    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Costs by type') }}</h3>
                        <ul class="mt-3 space-y-2 text-sm">
                            @foreach ($overview['by_kind'] as $kind => $amount)
                                <li>
                                    <div class="flex justify-between gap-2"><span class="text-gray-700 dark:text-gray-200">{{ $kind === 'plan' ? '🗓️ '.__('Itinerary (on site)') : ($kinds[$kind]['icon'] ?? '').' '.($kinds[$kind]['label'] ?? $kind) }}</span><span class="tabular-nums text-gray-600 dark:text-gray-300">{{ $euro($amount) }}</span></div>
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full" style="width: {{ $overview['costs'] > 0 ? round($amount / $overview['costs'] * 100) : 0 }}%; background-color: {{ $scheme['to'] }}"></div></div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section class="flex flex-wrap items-center gap-3 rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                    <span class="text-2xl" aria-hidden="true">🧾</span>
                    <p class="min-w-0 flex-1 text-sm text-gray-700 dark:text-gray-300">{{ __('Share costs on the trip: who paid what and who owes whom.') }}</p>
                    @forelse ($expenseGroups as $expenseGroup)
                        <a href="{{ route('splits.show', $expenseGroup) }}" wire:navigate class="rounded-lg bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/40 dark:text-indigo-200">{{ $expenseGroup->icon }} {{ $expenseGroup->title }}</a>
                    @empty
                        <a href="{{ route('splits.index', ['trip' => $trip->id]) }}" wire:navigate class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500">+ {{ __('Split costs') }}</a>
                    @endforelse
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

    @if ($stopSheet)
        <x-sheet close="closeStop" :label="$editingStopId ? __('Edit entry') : __('New entry')" size="md" wire:key="trip-stop-sheet-{{ $editingStopId ?? 'new' }}">
            <x-slot:header>
                <span class="truncate">{{ $editingStopId ? __('Edit entry') : __('New entry') }}</span>
            </x-slot:header>
            <form wire:submit="saveStop" id="trip-stop-form" class="space-y-4">
                <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="{{ __('Type') }}">
                    @foreach ($stopKinds as $key => $stopKind)
                        <button type="button" role="radio" aria-checked="{{ $stop['kind'] === $key ? 'true' : 'false' }}" wire:click="$set('stop.kind', '{{ $key }}')" @class(['rounded-full px-3 py-1 text-sm', 'bg-indigo-600 text-white' => $stop['kind'] === $key, 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200' => $stop['kind'] !== $key])>{{ $stopKind['icon'] }} {{ $stopKind['label'] }}</button>
                    @endforeach
                </div>
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Title') }}<input type="text" wire:model="stop.title" class="{{ $input }}" placeholder="{{ __('e.g. Sagrada Família') }}" autofocus><x-input-error :messages="$errors->get('stop.title')" /></label>
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Day') }}
                    <select wire:model="stop.day" class="{{ $input }}">
                        <option value="">💡 {{ __('Idea – no day yet') }}</option>
                        @foreach ($itinerary['days'] as $day)
                            <option value="{{ $day['date']->toDateString() }}">{{ $dayLabel($day['date'], $day['number']) }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('From') }}<input type="time" wire:model="stop.time" class="{{ $input }}"><x-input-error :messages="$errors->get('stop.time')" /></label>
                    <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Until') }}<input type="time" wire:model="stop.end_time" class="{{ $input }}"></label>
                </div>
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Place / address') }}<input type="text" wire:model="stop.location" class="{{ $input }}" placeholder="{{ __('e.g. Carrer de Mallorca 401, Barcelona') }}"></label>
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Cost (€)') }}<input type="text" inputmode="decimal" wire:model="stop.cost" class="{{ $input }}"><x-input-error :messages="$errors->get('stop.cost')" /></label>
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Notes') }}<textarea wire:model="stop.notes" rows="3" class="{{ $input }}" placeholder="{{ __('Opening hours, tickets, tips …') }}"></textarea></label>
            </form>
            <x-slot:footer>
                <x-primary-button form="trip-stop-form">{{ __('Save') }}</x-primary-button>
                <x-secondary-button type="button" wire:click="closeStop">{{ __('Cancel') }}</x-secondary-button>
                @if ($editingStopId)
                    <button type="button" wire:click="deleteStop({{ $editingStopId }})" wire:confirm="{{ __('Delete this entry?') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                @endif
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
