<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\Place;
use App\Models\Trip;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Places to remember: restaurants to try, favourite cafés, sights – with rating and visits.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $city = '';

    /** "" all, "want" not visited yet, "visited", "favourites" rated 4+. */
    #[Url(except: '')]
    public string $status = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** Suggestion of the "Where to go?" button. */
    public ?int $suggestedId = null;

    /**
     * @var array{name: string, category: string, cuisine: string, address: string, city: string, url: string, phone: string, price_level: string, recommended_by: string, notes: string, trip_id: string, visited: bool, private: bool}
     */
    public array $place = ['name' => '', 'category' => 'restaurant', 'cuisine' => '', 'address' => '', 'city' => '', 'url' => '', 'phone' => '', 'price_level' => '', 'recommended_by' => '', 'notes' => '', 'trip_id' => '', 'visited' => false, 'private' => false];

    public function mount(): void
    {
        $this->resolveHousehold();
    }

    private function findPlace(int $id): Place
    {
        return Place::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($id) ?? abort(404);
    }

    public function create(): void
    {
        $this->reset(['editingId', 'place']);
        $this->resetValidation();
        $this->place['category'] = $this->category !== '' ? $this->category : 'restaurant';
        $this->place['city'] = $this->city;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $place = $this->findPlace($id);
        $this->editingId = $place->id;
        $this->place = [
            'name' => $place->name, 'category' => $place->category, 'cuisine' => (string) $place->cuisine, 'address' => (string) $place->address,
            'city' => (string) $place->city, 'url' => (string) $place->url, 'phone' => (string) $place->phone, 'price_level' => (string) $place->price_level,
            'recommended_by' => (string) $place->recommended_by, 'notes' => (string) $place->notes, 'trip_id' => (string) $place->trip_id,
            'visited' => $place->visited, 'private' => $place->isPrivate(),
        ];
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'place.name' => ['required', 'string', 'max:150'],
            'place.category' => ['required', Rule::in(array_keys(Place::categories()))],
            'place.cuisine' => ['nullable', 'string', 'max:60'],
            'place.address' => ['nullable', 'string', 'max:255'],
            'place.city' => ['nullable', 'string', 'max:100'],
            'place.url' => ['nullable', 'url:http,https', 'max:500'],
            'place.phone' => ['nullable', 'string', 'max:40'],
            'place.price_level' => ['nullable', 'integer', 'between:1,4'],
            'place.recommended_by' => ['nullable', 'string', 'max:100'],
            'place.notes' => ['nullable', 'string', 'max:5000'],
            'place.trip_id' => ['nullable', 'integer'],
        ], attributes: ['place.name' => __('Name'), 'place.url' => __('Website')])['place'];

        $trip = $data['trip_id'] ? Trip::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($data['trip_id']) : null;
        $attributes = [
            'name' => $data['name'],
            'category' => $data['category'],
            'trip_id' => $trip?->id,
            'visibility' => $this->place['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'visited' => $this->place['visited'],
            'price_level' => $data['price_level'] !== '' && $data['price_level'] !== null ? (int) $data['price_level'] : null,
        ];

        foreach (['cuisine', 'address', 'city', 'url', 'phone', 'recommended_by', 'notes'] as $field) {
            $attributes[$field] = trim((string) $data[$field]) ?: null;
        }

        if ($this->editingId) {
            $place = $this->findPlace($this->editingId);
            $keep = ($place->created_by === Auth::id() ? [] : ['visibility' => true]) + ($place->household_id === $this->householdId ? [] : ['trip_id' => true]);
            $place->update(array_diff_key($attributes, $keep));
        } else {
            Place::create($attributes + ['household_id' => $this->householdId, 'created_by' => Auth::id(), 'visit_count' => $attributes['visited'] ? 1 : 0, 'last_visited_on' => $attributes['visited'] ? today() : null]);
        }

        $this->reset(['editingId', 'place', 'showForm']);
    }

    /**
     * "We were there (again)": counts the visit; the rating follows with the stars.
     */
    public function markVisited(int $id): void
    {
        $place = $this->findPlace($id);
        $place->update(['visited' => true, 'visit_count' => $place->visit_count + 1, 'last_visited_on' => today()]);
        $this->suggestedId = null;
    }

    public function rate(int $id, int $stars): void
    {
        abort_unless($stars >= 0 && $stars <= 5, 422);
        $place = $this->findPlace($id);
        $place->update(['rating' => $stars ?: null, 'visited' => true, 'visit_count' => max(1, $place->visit_count), 'last_visited_on' => $place->last_visited_on ?? today()]);
    }

    public function delete(int $id): void
    {
        $place = $this->findPlace($id);
        // Group members from other households may edit, but not delete.
        abort_unless($place->household_id === $this->householdId, 403);
        $place->delete();
        $this->reset(['editingId', 'place', 'showForm']);
    }

    /**
     * Picks a place from the current filter: new places first, otherwise one of the favourites.
     */
    public function suggest(): void
    {
        $places = $this->filtered()->get();
        $pool = $places->where('visited', false);
        $pool = $pool->isNotEmpty() ? $pool : $places->where('rating', '>=', 4);
        $pool = $pool->isNotEmpty() ? $pool : $places;

        $this->suggestedId = $pool->isNotEmpty() ? $pool->random()->id : null;

        if (! $this->suggestedId) {
            $this->dispatch('toast', message: __('No place matches the filter.'));
        }
    }

    public function filterBy(string $field, string $value): void
    {
        abort_unless(in_array($field, ['category', 'city', 'status'], true), 422);
        $this->{$field} = $this->{$field} === $value ? '' : $value;
        $this->suggestedId = null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Place>
     */
    private function filtered()
    {
        $term = trim($this->search);

        return Place::query()->visibleTo(Auth::user(), (int) $this->householdId)
            ->when($this->category !== '', fn ($query) => $query->where('category', $this->category))
            ->when($this->city !== '', fn ($query) => $query->where('city', $this->city))
            ->when($this->status === 'want', fn ($query) => $query->where('visited', false))
            ->when($this->status === 'visited', fn ($query) => $query->where('visited', true))
            ->when($this->status === 'favourites', fn ($query) => $query->where('rating', '>=', 4))
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('cuisine', 'like', "%{$term}%")
                ->orWhere('address', 'like', "%{$term}%")->orWhere('notes', 'like', "%{$term}%")->orWhere('recommended_by', 'like', "%{$term}%")));
    }

    public function with(): array
    {
        $all = Place::query()->visibleTo(Auth::user(), (int) $this->householdId)->get(['id', 'category', 'city', 'visited']);

        return [
            'editingForeign' => $this->editingId !== null && $this->findPlace($this->editingId)->household_id !== $this->householdId,
            'places' => $this->filtered()->with('trip', 'shareGroups', 'creator')->orderBy('visited')->orderByDesc('rating')->orderBy('name')->get(),
            'suggested' => $this->suggestedId ? Place::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($this->suggestedId) : null,
            'cities' => $all->pluck('city')->filter()->unique()->sort()->values(),
            'categoryCounts' => $all->countBy('category'),
            'total' => $all->count(),
            'trips' => Trip::query()->visibleTo(Auth::user(), (int) $this->householdId)->orderByDesc('starts_on')->limit(30)->get(['id', 'title', 'starts_on']),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $categories = \App\Models\Place::categories();
    $chip = fn (bool $active) => $active ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600';
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Places') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="space-y-3 rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                <div class="flex flex-wrap gap-2">
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search name, cuisine, address, notes …') }}" aria-label="{{ __('Search places') }}" class="min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <x-secondary-button type="button" wire:click="suggest" class="shrink-0">🎲 {{ __('Where to go?') }}</x-secondary-button>
                    <x-primary-button type="button" wire:click="create" class="shrink-0">+ {{ __('New place') }}</x-primary-button>
                </div>
                <div class="flex flex-wrap gap-1.5" role="group" aria-label="{{ __('Status') }}">
                    @foreach (['want' => '🔖 '.__('Want to go'), 'visited' => '✓ '.__('Been there'), 'favourites' => '⭐ '.__('Favourites')] as $key => $label)
                        <button type="button" wire:click="filterBy('status', '{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}" class="rounded-full px-2.5 py-0.5 text-xs {{ $chip($status === $key) }}">{{ $label }}</button>
                    @endforeach
                    <span class="mx-1 w-px bg-gray-200 dark:bg-gray-700" aria-hidden="true"></span>
                    @foreach ($categories as $key => $categoryOption)
                        @if ($categoryCounts[$key] ?? 0)
                            <button type="button" wire:click="filterBy('category', '{{ $key }}')" aria-pressed="{{ $category === $key ? 'true' : 'false' }}" class="rounded-full px-2.5 py-0.5 text-xs {{ $chip($category === $key) }}">{{ $categoryOption['icon'] }} {{ $categoryOption['label'] }} <span class="opacity-70">{{ $categoryCounts[$key] }}</span></button>
                        @endif
                    @endforeach
                </div>
                @if ($cities->count() > 1)
                    <div class="flex flex-wrap gap-1.5" role="group" aria-label="{{ __('City') }}">
                        @foreach ($cities as $cityName)
                            <button type="button" wire:click="filterBy('city', @js($cityName))" wire:key="city-{{ md5($cityName) }}" aria-pressed="{{ $city === $cityName ? 'true' : 'false' }}" class="rounded-full px-2.5 py-0.5 text-xs {{ $chip($city === $cityName) }}">🏙️ {{ $cityName }}</button>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($showForm)
                <form wire:submit="save" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-4 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="place.name" class="{{ $input }}" autofocus><x-input-error :messages="$errors->get('place.name')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Type') }}
                        <select wire:model="place.category" class="{{ $input }}">
                            @foreach ($categories as $key => $categoryOption)
                                <option value="{{ $key }}">{{ $categoryOption['icon'] }} {{ $categoryOption['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Address') }}<input type="text" wire:model="place.address" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('City') }}<input type="text" wire:model="place.city" class="{{ $input }}" list="place-cities">
                        <datalist id="place-cities">
                            @foreach ($cities as $cityName)
                                <option value="{{ $cityName }}"></option>
                            @endforeach
                        </datalist>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Cuisine / what') }}<input type="text" wire:model="place.cuisine" class="{{ $input }}" placeholder="{{ __('e.g. Italian, brunch, rooftop') }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Price level') }}
                        <select wire:model="place.price_level" class="{{ $input }}">
                            <option value="">–</option>
                            @foreach ([1 => '€', 2 => '€€', 3 => '€€€', 4 => '€€€€'] as $level => $label)
                                <option value="{{ $level }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Recommended by') }}<input type="text" wire:model="place.recommended_by" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-4 dark:text-gray-300">{{ __('Website') }}<input type="url" wire:model="place.url" class="{{ $input }}" placeholder="https://"><x-input-error :messages="$errors->get('place.url')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Phone') }}<input type="tel" wire:model="place.phone" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">{{ __('Notes') }}<textarea wire:model="place.notes" rows="2" class="{{ $input }}" placeholder="{{ __('e.g. book a table, try the tiramisu, terrace in summer') }}"></textarea></label>
                    @if ($trips->isNotEmpty())
                        <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('For trip') }}
                            <select wire:model="place.trip_id" class="{{ $input }}">
                                <option value="">{{ __('none') }}</option>
                                @foreach ($trips as $trip)
                                    <option value="{{ $trip->id }}">{{ $trip->title }} ({{ $trip->starts_on->format('m/Y') }})</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <div class="flex flex-wrap items-end gap-4 text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="place.visited" class="rounded border-gray-300 text-indigo-600"> {{ __('Been there') }}</label>
                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="place.private" class="rounded border-gray-300 text-indigo-600"> {{ __('Only visible to me') }}</label>
                    </div>
                    <div class="flex flex-wrap gap-2 sm:col-span-6">
                        <x-primary-button>{{ $editingId ? __('Save changes') : __('Add') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-secondary-button>
                        @if ($editingId && ! $editingForeign)
                            <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Delete this place?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                        @endif
                    </div>
                </form>
                @if ($editingId)
                    <livewire:sharing.panel type="place" :id="$editingId" :wire:key="'share-place-'.$editingId" />
                @endif
            @endif

            @if ($suggested)
                <div class="rounded-2xl bg-indigo-50 p-4 ring-1 ring-indigo-200 dark:bg-indigo-900/30 dark:ring-indigo-800" role="status">
                    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700 dark:text-indigo-300">🎲 {{ __('How about') }}</p>
                    <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $categories[$suggested->category]['icon'] ?? '📍' }} {{ $suggested->name }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ collect([$suggested->cuisine, $suggested->city])->filter()->implode(' · ') }}</p>
                    <div class="mt-2 flex flex-wrap gap-3 text-sm">
                        <a href="{{ $suggested->mapsUrl() }}" target="_blank" rel="noopener noreferrer" class="text-indigo-700 underline dark:text-indigo-300">🗺️ {{ __('Route') }}</a>
                        <button type="button" wire:click="suggest" class="text-indigo-700 underline dark:text-indigo-300">{{ __('Another one') }}</button>
                    </div>
                </div>
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                @forelse ($places as $item)
                    <article wire:key="place-{{ $item->id }}" class="flex flex-col rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $categories[$item->category]['icon'] ?? '📍' }} {{ $item->name }}
                                    <x-audience-badge :model="$item" quiet />
                                </p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ collect([$item->cuisine, $item->price_level ? str_repeat('€', $item->price_level) : null, $item->city])->filter()->implode(' · ') }}</p>
                            </div>
                            @unless ($item->visited)
                                <span class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">🔖 {{ __('Want to go') }}</span>
                            @endunless
                        </div>
                        @if ($item->address)
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $item->address }}</p>
                        @endif
                        @if ($item->notes)
                            <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $item->notes }}</p>
                        @endif
                        <p class="mt-2 flex flex-wrap gap-x-3 text-xs text-gray-500 dark:text-gray-400">
                            @if ($item->recommended_by)
                                <span>👍 {{ __('Tip from :name', ['name' => $item->recommended_by]) }}</span>
                            @endif
                            @if ($item->trip)
                                <a href="{{ route('trips.show', $item->trip) }}" wire:navigate class="underline">✈️ {{ $item->trip->title }}</a>
                            @endif
                            @if ($item->visited && $item->last_visited_on)
                                <span>{{ trans_choice('Visited :count time, last on :date|Visited :count times, last on :date', max(1, $item->visit_count), ['date' => $item->last_visited_on->format('d.m.Y')]) }}</span>
                            @endif
                        </p>
                        <div class="mt-2 flex items-center gap-0.5" role="radiogroup" aria-label="{{ __('Rating') }}">
                            @for ($star = 1; $star <= 5; $star++)
                                <button type="button" role="radio" aria-checked="{{ $item->rating === $star ? 'true' : 'false' }}" wire:click="rate({{ $item->id }}, {{ $item->rating === $star ? 0 : $star }})" class="text-lg leading-none {{ ($item->rating ?? 0) >= $star ? 'text-amber-400' : 'text-gray-300 dark:text-gray-600' }}" aria-label="{{ trans_choice(':count star|:count stars', $star) }}">★</button>
                            @endfor
                        </div>
                        <div class="mt-auto flex flex-wrap items-center gap-3 pt-3 text-sm">
                            <button type="button" wire:click="markVisited({{ $item->id }})" class="rounded-lg bg-emerald-600 px-2.5 py-1 text-white hover:bg-emerald-700">{{ $item->visited ? __('Been there again') : __('Been there') }}</button>
                            <a href="{{ $item->mapsUrl() }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 underline dark:text-indigo-400">🗺️ {{ __('Map') }}</a>
                            @if ($item->url)
                                <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Website') }} ↗</a>
                            @endif
                            @if ($item->phone)
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $item->phone) }}" class="text-indigo-600 underline dark:text-indigo-400">📞</a>
                            @endif
                            <button type="button" wire:click="edit({{ $item->id }})" class="ms-auto text-gray-500 underline dark:text-gray-400">{{ __('Edit') }}</button>
                        </div>
                    </article>
                @empty
                    <section class="rounded-2xl bg-white p-6 text-center shadow-sm sm:col-span-2 dark:bg-gray-800">
                        <p class="text-4xl" aria-hidden="true">📍</p>
                        @if ($total === 0)
                            <p class="mt-2 text-gray-700 dark:text-gray-200">{{ __('No places yet.') }}</p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Collect restaurants you want to try, favourite cafés and tips from friends – and let the app pick one when you can\'t decide.') }}</p>
                        @else
                            <p class="mt-2 text-gray-700 dark:text-gray-200">{{ __('No place matches the filter.') }}</p>
                        @endif
                    </section>
                @endforelse
            </div>
        </div>
    </div>
</div>
