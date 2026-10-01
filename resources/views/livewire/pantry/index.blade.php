<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\ShoppingList;
use App\Support\Pantry;
use App\Support\ShoppingLists;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * The household stock: groceries and household supplies (toilet paper, detergent, …) with
 * quantity, minimum stock, storage place and best-before date. Kept in the pantry collection,
 * so recipes and the shopping list use the same stock.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Url(except: 'all')]
    public string $kind = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    /**
     * @var array{name: string, kind: string, quantity: string, unit: string, minimum: string, place: string, best_before: string}
     */
    public array $entry = ['name' => '', 'kind' => Pantry::KIND_FOOD, 'quantity' => '1', 'unit' => '', 'minimum' => '', 'place' => '', 'best_before' => ''];

    public function mount(): void
    {
        $this->resolveHousehold();
    }

    public function create(): void
    {
        $this->reset('entry');
        $this->entry['kind'] = $this->kind === Pantry::KIND_SUPPLIES ? Pantry::KIND_SUPPLIES : Pantry::KIND_FOOD;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->entry['quantity'] = str_replace(',', '.', trim($this->entry['quantity']));
        $this->entry['minimum'] = str_replace(',', '.', trim($this->entry['minimum']));

        $data = $this->validate([
            'entry.name' => ['required', 'string', 'max:255'],
            'entry.kind' => ['required', Rule::in([Pantry::KIND_FOOD, Pantry::KIND_SUPPLIES])],
            'entry.quantity' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'entry.unit' => ['nullable', 'string', 'max:30'],
            'entry.minimum' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'entry.place' => ['nullable', 'string', 'max:20'],
            'entry.best_before' => ['nullable', 'date'],
        ])['entry'];

        Pantry::add(Auth::user(), (int) $this->householdId, [
            'name' => $data['name'],
            'kind' => $data['kind'],
            'quantity' => ($data['quantity'] ?? '') !== '' ? (float) $data['quantity'] : null,
            'unit' => trim((string) ($data['unit'] ?? '')),
            'minimum' => ($data['minimum'] ?? '') !== '' ? (float) $data['minimum'] : null,
            'place' => (string) ($data['place'] ?? ''),
            'best_before' => ($data['best_before'] ?? '') ?: null,
        ]);

        $this->showForm = false;
        $this->dispatch('toast', message: __(':name is in stock.', ['name' => $data['name']]));
    }

    public function adjust(int $itemId, int $delta): void
    {
        Pantry::adjust($this->findEntry($itemId), $delta > 0 ? 1 : -1);
    }

    /**
     * Puts one entry (or, without id, everything running low) on the shopping list.
     */
    public function toShoppingList(?int $itemId = null): void
    {
        $rows = Pantry::inventory(Auth::user(), (int) $this->householdId)
            ->filter(fn (array $row) => $itemId === null ? $row['low'] : $row['item']->id === $itemId);
        $list = ShoppingList::defaultFor((int) $this->householdId);

        foreach ($rows as $row) {
            $missing = $row['minimum'] !== null ? max(1, $row['minimum'] - ($row['quantity'] ?? 0) + 1) : null;
            ShoppingLists::add($list, $row['name'], $missing !== null ? trim(($missing + 0).' '.$row['unit']) : null, Auth::id());
        }

        $this->dispatch('toast', message: trans_choice(':count item put on the shopping list.|:count items put on the shopping list.', $rows->count()));
    }

    private function findEntry(int $itemId): Item
    {
        $collection = Pantry::collectionFor((int) $this->householdId);

        return Item::query()->visibleTo(Auth::user())->where('collection_id', $collection?->id)->find($itemId) ?? abort(404);
    }

    public function with(): array
    {
        $user = Auth::user();
        $collection = Pantry::ensure($user, (int) $this->householdId);
        $all = Pantry::inventory($user, (int) $this->householdId);
        $term = mb_strtolower(trim($this->search));
        $rows = $all
            ->filter(fn (array $row) => $this->kind === 'all' || $row['kind'] === $this->kind)
            ->filter(fn (array $row) => $term === '' || str_contains(mb_strtolower($row['name']), $term))
            ->sortBy(fn (array $row) => [$row['low'] ? 0 : 1, mb_strtolower($row['name'])])
            ->values();

        return [
            'collection' => $collection,
            'rows' => $rows,
            'counts' => ['all' => $all->count(), Pantry::KIND_FOOD => $all->where('kind', Pantry::KIND_FOOD)->count(), Pantry::KIND_SUPPLIES => $all->where('kind', Pantry::KIND_SUPPLIES)->count()],
            'lowCount' => $all->where('low', true)->count(),
            'expiring' => Pantry::expiring($user, (int) $this->householdId, 7),
            'places' => Pantry::places($collection),
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $amount = fn (?float $value) => $value === null ? '–' : rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    $tabs = ['all' => __('All'), Pantry::KIND_FOOD => '🥫 '.__('Groceries'), Pantry::KIND_SUPPLIES => '🧻 '.__('Household supplies')];
@endphp

<div x-on:page-primary.window="$wire.create()">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Pantry') }}</h2>
            <x-primary-button type="button" x-on:click="$dispatch('page-primary')" class="shrink-0">+ {{ __('Add to stock') }}</x-primary-button>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            @if ($lowCount > 0 || $expiring->isNotEmpty())
                <section class="grid gap-3 sm:grid-cols-2" aria-label="{{ __('What needs attention') }}">
                    @if ($lowCount > 0)
                        <div class="flex items-center justify-between gap-3 rounded-2xl bg-amber-50 p-4 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:ring-amber-800">
                            <p class="text-sm text-amber-900 dark:text-amber-200">⚠️ {{ trans_choice(':count item is running low.|:count items are running low.', $lowCount) }}</p>
                            <button type="button" wire:click="toShoppingList" class="shrink-0 rounded-lg bg-white px-3 py-1.5 text-xs font-medium text-amber-800 ring-1 ring-amber-300 hover:bg-amber-100 dark:bg-gray-800 dark:text-amber-200 dark:ring-amber-700">🛒 {{ __('On the shopping list') }}</button>
                        </div>
                    @endif
                    @if ($expiring->isNotEmpty())
                        <div class="rounded-2xl bg-red-50 p-4 ring-1 ring-red-200 dark:bg-red-900/20 dark:ring-red-800">
                            <p class="text-sm text-red-900 dark:text-red-200">⏳ {{ __('Use soon') }}: {{ $expiring->take(5)->map(fn (array $row) => $row['name'].' ('.$row['best_before']->format('d.m.').')')->implode(', ') }}</p>
                            <a href="{{ route('recipes.index', ['pantry' => $expiring->pluck('name')->implode(', ')]) }}" wire:navigate class="mt-1 inline-block text-xs text-red-800 underline dark:text-red-300">{{ __('Find recipes for it') }}</a>
                        </div>
                    @endif
                </section>
            @endif

            @if ($showForm)
                <form wire:submit="save" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-4 dark:text-gray-300">{{ __('What?') }}<input type="text" wire:model="entry.name" class="{{ $input }}" placeholder="{{ $entry['kind'] === Pantry::KIND_SUPPLIES ? __('e.g. toilet paper, dish soap, batteries') : __('e.g. pasta, rice, canned tomatoes') }}" autofocus><x-input-error :messages="$errors->get('entry.name')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Kind') }}
                        <select wire:model.live="entry.kind" class="{{ $input }}">
                            <option value="{{ Pantry::KIND_FOOD }}">🥫 {{ __('Groceries') }}</option>
                            <option value="{{ Pantry::KIND_SUPPLIES }}">🧻 {{ __('Household supplies') }}</option>
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-1 dark:text-gray-300">{{ __('Quantity') }}<input type="text" inputmode="decimal" wire:model="entry.quantity" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.quantity')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-1 dark:text-gray-300">{{ __('Unit') }}<input type="text" wire:model="entry.unit" class="{{ $input }}" placeholder="{{ __('pcs') }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Minimum stock') }}<input type="text" inputmode="decimal" wire:model="entry.minimum" class="{{ $input }}" placeholder="{{ __('optional') }}"><x-input-error :messages="$errors->get('entry.minimum')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Place') }}
                        <select wire:model="entry.place" class="{{ $input }}">
                            <option value="">–</option>
                            @foreach ($places as $placeId => $placeName)
                                <option value="{{ $placeId }}">{{ $placeName }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($entry['kind'] === Pantry::KIND_FOOD)
                        <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Best before') }}<input type="date" wire:model="entry.best_before" class="{{ $input }}"></label>
                    @endif
                    <div class="flex flex-wrap items-end gap-2 sm:col-span-6">
                        <x-primary-button>{{ __('Add') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-secondary-button>
                        <p class="ms-auto text-xs text-gray-500 dark:text-gray-400">{{ __('Already in stock? The quantity is added.') }}</p>
                    </div>
                </form>
            @endif

            <div class="flex flex-wrap items-center gap-2">
                <nav class="flex gap-1 overflow-x-auto" role="tablist" aria-label="{{ __('Kind') }}">
                    @foreach ($tabs as $tabKey => $tabLabel)
                        <button type="button" role="tab" wire:click="$set('kind', '{{ $tabKey }}')" aria-selected="{{ $kind === $tabKey ? 'true' : 'false' }}" @class(['shrink-0 rounded-full px-3 py-1.5 text-sm', 'bg-indigo-600 text-white' => $kind === $tabKey, 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-700' => $kind !== $tabKey])>
                            {{ $tabLabel }} <span class="opacity-70">{{ $counts[$tabKey] }}</span>
                        </button>
                    @endforeach
                </nav>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="🔎 {{ __('Search') }}" aria-label="{{ __('Search') }}" class="ms-auto w-full rounded-full border-gray-300 px-4 py-1.5 text-sm sm:w-56 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
            </div>

            @if ($rows->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-3xl" aria-hidden="true">🥫🧻</p>
                    <p class="mt-2 font-medium text-gray-900 dark:text-gray-100">{{ $search !== '' ? __('Nothing found.') : __('Nothing in stock yet.') }}</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Keep track of groceries and household supplies – with a minimum stock they land on the shopping list in time.') }}</p>
                    <x-primary-button type="button" wire:click="create" class="mt-4">+ {{ __('Add to stock') }}</x-primary-button>
                </div>
            @else
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white shadow-sm dark:divide-gray-700 dark:bg-gray-800">
                    @foreach ($rows as $row)
                        @php($expiresSoon = $row['best_before'] && $row['best_before']->lte(today()->addDays(7)))
                        <li wire:key="stock-{{ $row['item']->id }}" class="flex items-center gap-3 px-4 py-3">
                            <span class="text-lg" aria-hidden="true">{{ $row['kind'] === Pantry::KIND_SUPPLIES ? '🧻' : '🥫' }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-gray-900 dark:text-gray-100">{{ $row['name'] }}</p>
                                <p class="flex flex-wrap gap-x-2 text-xs text-gray-500 dark:text-gray-400">
                                    @if ($row['place'] !== '')
                                        <span>📍 {{ $row['place'] }}</span>
                                    @endif
                                    @if ($row['minimum'] !== null)
                                        <span>{{ __('Minimum :amount', ['amount' => $amount($row['minimum'])]) }}</span>
                                    @endif
                                    @if ($row['best_before'])
                                        <span @class(['font-medium text-red-700 dark:text-red-300' => $expiresSoon])>⏳ {{ $row['best_before']->format('d.m.Y') }}</span>
                                    @endif
                                    @if ($row['low'])
                                        <span class="font-medium text-amber-700 dark:text-amber-300">⚠️ {{ __('Running low') }}</span>
                                    @endif
                                </p>
                            </div>
                            @if ($row['low'])
                                <button type="button" wire:click="toShoppingList({{ $row['item']->id }})" class="hidden rounded-lg px-2 py-1 text-xs text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50 sm:inline-block dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700" title="{{ __('On the shopping list') }}">🛒</button>
                            @endif
                            <div class="flex items-center gap-1" role="group" aria-label="{{ __('Quantity') }}: {{ $row['name'] }}">
                                <button type="button" wire:click="adjust({{ $row['item']->id }}, -1)" class="flex h-8 w-8 items-center justify-center rounded-full text-lg text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-700 pointer-coarse:h-10 pointer-coarse:w-10" aria-label="{{ __('One less') }}">−</button>
                                <span class="min-w-[4.5rem] text-center text-sm tabular-nums text-gray-900 dark:text-gray-100">{{ $amount($row['quantity']) }} {{ $row['unit'] }}</span>
                                <button type="button" wire:click="adjust({{ $row['item']->id }}, 1)" class="flex h-8 w-8 items-center justify-center rounded-full text-lg text-gray-600 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-700 pointer-coarse:h-10 pointer-coarse:w-10" aria-label="{{ __('One more') }}">+</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Cooking a recipe takes its ingredients out of the stock.') }}
                    <a href="{{ route('collections.show', $collection) }}" wire:navigate class="underline">{{ __('Edit all fields in the collection') }}</a>
                </p>
            @endif
        </div>
    </div>
</div>
