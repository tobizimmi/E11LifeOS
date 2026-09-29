<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Models\ShoppingStore;
use App\Support\ProductLookup;
use App\Support\ShoppingAisles;
use App\Support\ShoppingLists;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Url(as: 'list', except: null)]
    public ?int $listId = null;

    public string $label = '';

    public string $quantity = '';

    /**
     * Group by supermarket section (true) or show the newest first (false); kept for the session.
     */
    #[Session]
    public bool $byAisle = true;

    /**
     * Large rows for the store, screen stays on.
     */
    #[Session]
    public bool $shoppingMode = false;

    public bool $sharing = false;

    public ?int $editingId = null;

    /**
     * @var array{label: string, quantity: string, note: string, list: string}
     */
    public array $edit = ['label' => '', 'quantity' => '', 'note' => '', 'list' => ''];

    public bool $listFormOpen = false;

    public ?int $editingListId = null;

    /**
     * @var array{name: string, icon: string, store_id: string}
     */
    public array $listForm = ['name' => '', 'icon' => '🛒', 'store_id' => ''];

    public bool $storesOpen = false;

    public ?int $sortingStoreId = null;

    /**
     * @var array{name: string, chain: string}
     */
    public array $storeForm = ['name' => '', 'chain' => ''];

    /**
     * The last deleted entry, for "undo".
     *
     * @var array<string, mixed>|null
     */
    public ?array $lastDeleted = null;

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->listId = $this->currentList()->id;
    }

    private function currentList(): ShoppingList
    {
        return ($this->listId ? ShoppingList::where('household_id', $this->householdId)->find($this->listId) : null)
            ?? ShoppingList::defaultFor((int) $this->householdId);
    }

    private function findList(int $id): ShoppingList
    {
        return ShoppingList::where('household_id', $this->householdId)->find($id) ?? abort(404);
    }

    private function findEntry(int $id): ShoppingListEntry
    {
        return ShoppingListEntry::where('household_id', $this->householdId)->findOrFail($id);
    }

    private function findStore(int $id): ShoppingStore
    {
        return ShoppingStore::where('household_id', $this->householdId)->find($id) ?? abort(404);
    }

    public function selectList(int $id): void
    {
        $this->listId = $this->findList($id)->id;
        $this->reset('editingId', 'sharing');
    }

    // ── Entries ──────────────────────────────────────────────────────────

    public function add(): void
    {
        $validated = $this->validate([
            'label' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'string', 'max:255'],
        ]);

        ShoppingLists::add($this->currentList(), $validated['label'], $validated['quantity'], Auth::id());
        $this->reset('label', 'quantity');
    }

    public function addFrequent(string $label, ?string $quantity = null): void
    {
        abort_if(trim($label) === '' || mb_strlen($label) > 255, 422);
        ShoppingLists::add($this->currentList(), $label, $quantity, Auth::id());
    }

    /**
     * Fills the add form with the scanned product (the user still confirms with "Add").
     */
    public function lookupBarcode(string $code, ProductLookup $lookup): void
    {
        $code = preg_replace('/\D/', '', $code);
        $product = $lookup->byBarcode($code);

        if (! $product) {
            $this->dispatch('toast', message: ProductLookup::isValidBarcode($code)
                ? __('Product :code not found – please enter it yourself.', ['code' => $code])
                : __('That is not a valid barcode.'));

            return;
        }

        $this->label = $product['name'];
        $this->quantity = $product['quantity'];
        $this->dispatch('toast', message: __(':name found.', ['name' => $product['name']]));
    }

    public function toggle(int $id): void
    {
        $entry = $this->findEntry($id);
        $entry->update(['checked' => ! $entry->checked]);
    }

    /**
     * Moves an entry to another section; the household's choice is reused for the same item.
     */
    public function setAisle(int $id, string $aisle): void
    {
        abort_unless(array_key_exists($aisle, ShoppingAisles::all()), 404);
        $this->findEntry($id)->update(['aisle' => $aisle]);
    }

    /**
     * Drag & drop: puts an entry into a section at a position (section changes are learned).
     */
    public function moveEntry(int $id, string $aisle, int $position): void
    {
        abort_unless(array_key_exists($aisle, ShoppingAisles::all()), 404);
        $entry = $this->findEntry($id);
        $entry->update(['aisle' => $aisle]);

        $siblings = ShoppingListEntry::where('shopping_list_id', $entry->shopping_list_id)->where('checked', false)->where('aisle', $aisle)->whereKeyNot($entry->id)
            ->get()->sortBy([['position', 'asc'], [fn ($a, $b) => strnatcasecmp($a->label, $b->label)]])->values();
        $siblings->splice(max(0, min($position, $siblings->count())), 0, [$entry]);

        foreach ($siblings as $index => $sibling) {
            if ($sibling->position !== $index + 1) {
                $sibling->updateQuietly(['position' => $index + 1]);
            }
        }
    }

    public function delete(int $id): void
    {
        $entry = $this->findEntry($id);
        $this->lastDeleted = $entry->only(['shopping_list_id', 'label', 'quantity', 'aisle', 'note', 'price', 'position', 'checked']);
        $entry->delete();
        $this->dispatch('toast', message: __(':item removed.', ['item' => $entry->label]), action: ['label' => __('Undo'), 'event' => 'shopping-undo']);
    }

    #[On('shopping-undo')]
    public function undoDelete(): void
    {
        if (! $this->lastDeleted) {
            return;
        }

        $list = ShoppingList::where('household_id', $this->householdId)->find($this->lastDeleted['shopping_list_id']);

        if ($list) {
            ShoppingListEntry::create($this->lastDeleted + ['household_id' => $this->householdId, 'created_by' => Auth::id()]);
        }

        $this->lastDeleted = null;
    }

    public function clearDone(): void
    {
        ShoppingListEntry::where('shopping_list_id', $this->currentList()->id)->where('checked', true)->get()->each->delete();
    }

    public function uncheckAll(): void
    {
        ShoppingListEntry::where('shopping_list_id', $this->currentList()->id)->where('checked', true)->get()->each->update(['checked' => false]);
    }

    public function startEdit(int $id): void
    {
        $entry = $this->findEntry($id);
        $this->resetValidation();
        $this->editingId = $entry->id;
        $this->edit = ['label' => $entry->label, 'quantity' => (string) $entry->quantity, 'note' => (string) $entry->note, 'list' => (string) $entry->shopping_list_id];
    }

    public function saveEdit(): void
    {
        $entry = $this->findEntry((int) $this->editingId);
        $data = $this->validate([
            'edit.label' => ['required', 'string', 'max:255'],
            'edit.quantity' => ['nullable', 'string', 'max:255'],
            'edit.note' => ['nullable', 'string', 'max:255'],
            'edit.list' => ['required', 'integer'],
        ], attributes: ['edit.label' => __('Item')])['edit'];

        $entry->update([
            'label' => trim($data['label']),
            'quantity' => trim((string) $data['quantity']) ?: null,
            'note' => trim((string) $data['note']) ?: null,
            'shopping_list_id' => $this->findList((int) $data['list'])->id,
        ]);
        $this->editingId = null;
    }

    public function closeEdit(): void
    {
        $this->editingId = null;
    }

    public function closeListForm(): void
    {
        $this->listFormOpen = false;
    }

    public function closeStores(): void
    {
        $this->storesOpen = false;
    }

    // ── Lists ────────────────────────────────────────────────────────────

    public function newList(): void
    {
        $this->resetValidation();
        $this->editingListId = null;
        $this->listForm = ['name' => '', 'icon' => '🛒', 'store_id' => ''];
        $this->listFormOpen = true;
    }

    public function editList(): void
    {
        $list = $this->currentList();
        $this->resetValidation();
        $this->editingListId = $list->id;
        $this->listForm = ['name' => $list->name, 'icon' => $list->icon, 'store_id' => (string) $list->store_id];
        $this->listFormOpen = true;
    }

    public function saveList(): void
    {
        $data = $this->validate([
            'listForm.name' => ['required', 'string', 'max:60'],
            'listForm.icon' => ['required', Rule::in(ShoppingList::ICONS)],
            'listForm.store_id' => ['nullable', 'integer'],
        ], attributes: ['listForm.name' => __('Name')])['listForm'];

        $data['store_id'] = $data['store_id'] ? $this->findStore((int) $data['store_id'])->id : null;

        if ($this->editingListId) {
            $this->findList($this->editingListId)->update($data);
        } else {
            $list = ShoppingList::create($data + [
                'household_id' => $this->householdId,
                'position' => (int) ShoppingList::where('household_id', $this->householdId)->max('position') + 1,
                'created_by' => Auth::id(),
            ]);
            $this->listId = $list->id;
        }

        $this->listFormOpen = false;
    }

    public function deleteList(int $id): void
    {
        $list = $this->findList($id);
        abort_if(ShoppingList::where('household_id', $this->householdId)->count() <= 1, 422);
        $list->delete();
        $this->listFormOpen = false;
        $this->listId = ShoppingList::defaultFor((int) $this->householdId)->id;
    }

    public function sortLists(int $id, int $position): void
    {
        $lists = ShoppingList::where('household_id', $this->householdId)->orderBy('position')->orderBy('id')->get();
        $moved = $lists->firstWhere('id', $id) ?? abort(404);
        $lists = $lists->reject(fn (ShoppingList $list) => $list->id === $id)->values();
        $lists->splice(max(0, min($position, $lists->count())), 0, [$moved]);

        foreach ($lists as $index => $list) {
            $list->update(['position' => $index]);
        }
    }

    /**
     * Link for people outside the household (e.g. whoever goes shopping); any member can create or turn it off.
     */
    public function createShareLink(): void
    {
        $list = $this->currentList();

        if (! $list->share_token) {
            $list->update(['share_token' => Str::random(40)]);
        }

        $this->sharing = true;
    }

    public function revokeShareLink(): void
    {
        $this->currentList()->update(['share_token' => null]);
        $this->sharing = false;
        $this->dispatch('toast', message: __('The public link no longer works.'));
    }

    // ── Stores ───────────────────────────────────────────────────────────

    public function addStore(): void
    {
        $data = $this->validate([
            'storeForm.name' => ['required', 'string', 'max:60'],
            'storeForm.chain' => ['nullable', Rule::in(array_keys(ShoppingStore::CHAINS))],
        ], attributes: ['storeForm.name' => __('Name')])['storeForm'];

        $store = ShoppingStore::create([
            'household_id' => $this->householdId,
            'name' => trim($data['name']),
            'chain' => $data['chain'] ?: null,
            'position' => (int) ShoppingStore::where('household_id', $this->householdId)->max('position') + 1,
        ]);
        $this->storeForm = ['name' => '', 'chain' => ''];
        $this->sortingStoreId = $store->id;

        // The current list without a store uses the new one right away.
        $list = $this->currentList();

        if (! $list->store_id) {
            $list->update(['store_id' => $store->id]);
        }
    }

    public function deleteStore(int $id): void
    {
        $this->findStore($id)->delete();
        $this->sortingStoreId = null;
    }

    /**
     * Drag & drop of a section inside a store's walking route.
     */
    public function sortAisle(int $storeId, string $aisle, int $position): void
    {
        abort_unless(array_key_exists($aisle, ShoppingAisles::all()), 404);
        $store = $this->findStore($storeId);
        $order = array_values(array_filter($store->aisleOrder(), fn (string $key) => $key !== $aisle));
        array_splice($order, max(0, min($position, count($order))), 0, [$aisle]);
        $store->update(['aisle_order' => $order]);
    }

    public function setListStore(string $storeId): void
    {
        $this->currentList()->update(['store_id' => $storeId !== '' ? $this->findStore((int) $storeId)->id : null]);
    }

    public function with(): array
    {
        $list = $this->currentList()->load('store');
        $lists = ShoppingList::where('household_id', $this->householdId)->orderBy('position')->orderBy('id')
            ->withCount(['entries as open_count' => fn ($query) => $query->where('checked', false)])->get();
        $entries = ShoppingListEntry::where('shopping_list_id', $list->id)->orderBy('checked')->latest()->get();
        $aisles = ShoppingAisles::all();
        $order = array_flip($list->store?->aisleOrder() ?? array_keys($aisles));
        $open = $entries->where('checked', false);

        // Open items by section in walking order (store order if the list has a store).
        $groups = $this->byAisle
            ? $open->groupBy(fn (ShoppingListEntry $entry) => array_key_exists((string) ($entry->aisle ??= ShoppingAisles::guess($entry->label)), $aisles) ? $entry->aisle : ShoppingAisles::OTHER)
                ->sortBy(fn ($group, string $aisle) => $order[$aisle] ?? 99)
                ->map(fn ($group, string $aisle) => [
                    'key' => $aisle,
                    'label' => $aisles[$aisle]['label'],
                    'icon' => $aisles[$aisle]['icon'],
                    'entries' => $group->sortBy([['position', 'asc'], [fn ($a, $b) => strnatcasecmp($a->label, $b->label)]])->values(),
                ])
            : collect(['all' => ['key' => null, 'label' => null, 'icon' => null, 'entries' => $open->values()]]);

        return [
            'list' => $list,
            'lists' => $lists,
            'entries' => $entries,
            'openCount' => $open->count(),
            'groups' => $groups->filter(fn (array $group) => $group['entries']->isNotEmpty()),
            'done' => $entries->where('checked', true)->values(),
            'total' => round((float) $open->sum('price'), 2),
            'aisles' => $aisles,
            'stores' => ShoppingStore::where('household_id', $this->householdId)->orderBy('position')->orderBy('id')->get(),
            'chains' => ShoppingStore::CHAINS,
            'frequent' => $this->shoppingMode ? [] : ShoppingLists::frequent((int) $this->householdId, $list->id, 12),
            'suggestions' => ShoppingLists::suggestions((int) $this->householdId),
            'shareUrl' => $list->share_token ? route('shopping-list.shared', $list->share_token) : null,
            'icons' => ShoppingList::ICONS,
        ];
    }
}; ?>

@php
    $input = 'block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
@endphp

<div
    x-data="{ wakeLock: null }"
    x-effect="
        if ($wire.shoppingMode && 'wakeLock' in navigator && ! wakeLock) { navigator.wakeLock.request('screen').then(lock => wakeLock = lock).catch(() => {}) }
        if (! $wire.shoppingMode && wakeLock) { wakeLock.release(); wakeLock = null }
    "
>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🛒 {{ __('Shopping List') }}</h2>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-3xl space-y-4 px-4 sm:px-6 lg:px-8">

            {{-- Kept inside the component: the header slot is rendered outside of it, where wire:click does not reach --}}
            <div class="flex flex-wrap items-center justify-end gap-2 text-sm">
                @if ($shoppingMode)
                    <span class="me-auto text-xs text-gray-500 dark:text-gray-400">🔆 {{ __('Shopping mode is on – big buttons, the screen stays on.') }}</span>
                @endif
                <a href="{{ route('shopping-list.offers', ['list' => $list->id]) }}" wire:navigate class="rounded-lg bg-amber-50 px-3 py-1.5 font-medium text-amber-800 ring-1 ring-amber-200 hover:bg-amber-100 dark:bg-amber-900/30 dark:text-amber-200 dark:ring-amber-800">🏷️ {{ __('Offers') }}</a>
                <button type="button" wire:click="$toggle('shoppingMode')" aria-pressed="{{ $shoppingMode ? 'true' : 'false' }}" @class(['rounded-lg px-3 py-1.5 font-medium ring-1', 'bg-indigo-600 text-white ring-indigo-600' => $shoppingMode, 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700' => ! $shoppingMode])>🛍️ {{ $shoppingMode ? __('End shopping mode') : __('Shopping mode') }}</button>
            </div>

            {{-- Lists: tap to switch, drag to reorder (hidden while shopping) --}}
            @unless ($shoppingMode)
            <nav class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0" aria-label="{{ __('Shopping lists') }}">
                <ul class="flex min-w-max items-center gap-2" x-sort="$wire.sortLists($item, $position)" x-sort:config="{ filter: '.no-sort', animation: 150 }">
                    @foreach ($lists as $tab)
                        <li wire:key="list-tab-{{ $tab->id }}" x-sort:item="{{ $tab->id }}">
                            <button type="button" wire:click="selectList({{ $tab->id }})" aria-current="{{ $tab->id === $list->id ? 'true' : 'false' }}"
                                @class(['flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-sm font-medium whitespace-nowrap ring-1 transition', 'bg-indigo-600 text-white ring-indigo-600 shadow-sm' => $tab->id === $list->id, 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700' => $tab->id !== $list->id])>
                                <span aria-hidden="true">{{ $tab->icon }}</span> {{ $tab->name }}
                                @if ($tab->open_count > 0)
                                    <span @class(['rounded-full px-1.5 text-xs', 'bg-white/20' => $tab->id === $list->id, 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $tab->id !== $list->id])>{{ $tab->open_count }}</span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                    <li class="no-sort">
                        <button type="button" wire:click="newList" class="rounded-full border border-dashed border-gray-300 px-3 py-1.5 text-sm text-gray-500 hover:bg-white dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-800">＋ {{ __('New list') }}</button>
                    </li>
                </ul>
            </nav>
            @endunless

            {{-- Current list: store, actions, add --}}
            <section class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800 sm:p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="text-2xl" aria-hidden="true">{{ $list->icon }}</span>
                        <div class="min-w-0">
                            <h3 class="truncate font-semibold text-gray-900 dark:text-gray-100">{{ $list->name }}</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ trans_choice(':count item still open|:count items still open', $openCount) }}
                                @if ($total > 0) · {{ __('approx. :amount', ['amount' => $euro($total)]) }}@endif
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <label class="sr-only" for="list-store">{{ __('Store') }}</label>
                        <select id="list-store" wire:change="setListStore($event.target.value)" class="min-w-0 max-w-[60%] rounded-lg border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <option value="">🏪 {{ __('Any store') }}</option>
                            @foreach ($stores as $store)
                                <option value="{{ $store->id }}" @selected($list->store_id === $store->id)>🏪 {{ $store->name }}</option>
                            @endforeach
                        </select>
                        @unless ($shoppingMode)
                        <button type="button" wire:click="$set('storesOpen', true)" class="rounded-lg px-2 py-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" title="{{ __('Stores & walking route') }}" aria-label="{{ __('Stores & walking route') }}">⚙︎</button>
                        <button type="button" wire:click="editList" class="rounded-lg px-2 py-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" title="{{ __('Edit list') }}" aria-label="{{ __('Edit list') }}">✎</button>
                        <button type="button" wire:click="{{ $shareUrl ? '$toggle(\'sharing\')' : 'createShareLink' }}" class="rounded-lg px-2 py-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" title="{{ __('Share') }}" aria-label="{{ __('Share') }}">🔗</button>
                        @endunless
                    </div>
                </div>

                @if ($sharing && $shareUrl && ! $shoppingMode)
                    <div class="mt-3 rounded-xl bg-gray-50 p-3 text-sm dark:bg-gray-900/50">
                        <p class="text-gray-700 dark:text-gray-300">🔗 {{ __('Anyone with this link can see the list, tick items off and add new ones – no login needed.') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                            <x-text-input readonly value="{{ $shareUrl }}" class="block min-w-0 flex-1 text-sm" aria-label="{{ __('Public link') }}" x-on:focus="$el.select()" />
                            <x-secondary-button type="button" x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($shareUrl) }}).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"><span x-text="copied ? @js(__('Copied!')) : @js(__('Copy'))"></span></x-secondary-button>
                            <x-secondary-button type="button" x-show="navigator.share" x-cloak x-on:click="navigator.share({ title: {{ \Illuminate\Support\Js::from($list->name) }}, url: {{ \Illuminate\Support\Js::from($shareUrl) }} })">↗ {{ __('Share') }}</x-secondary-button>
                            <button type="button" wire:click="revokeShareLink" wire:confirm="{{ __('Turn off the public link?') }}" class="text-red-600 underline dark:text-red-400">{{ __('Turn off link') }}</button>
                        </div>
                    </div>
                @endif

                <form wire:submit="add" class="mt-4 flex flex-wrap gap-2 sm:flex-nowrap">
                    <div class="relative min-w-0 basis-full sm:basis-auto sm:flex-1">
                        <x-text-input id="shopping-label" wire:model="label" list="shopping-suggestions" autocomplete="off" class="block w-full" type="text" placeholder="{{ __('What do you need?') }}" aria-label="{{ __('Item') }}" />
                        <datalist id="shopping-suggestions">
                            @foreach ($suggestions as $suggestion)
                                <option value="{{ $suggestion }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <x-text-input wire:model="quantity" class="block w-24 flex-1 sm:flex-none" type="text" placeholder="{{ __('Qty') }}" aria-label="{{ __('Quantity') }}" />
                    <x-voice-button for="shopping-label" />
                    <button type="button" x-data x-on:click="$dispatch('open-barcode-scanner')" class="shrink-0 rounded-md border border-gray-300 dark:border-gray-600 px-3 text-lg" aria-label="{{ __('Scan barcode') }}" title="{{ __('Scan barcode') }}">📷</button>
                    <x-primary-button type="submit">{{ __('Add') }}</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('label')" class="mt-2" />

                @if ($frequent !== [])
                    <div class="mt-3">
                        <p class="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Bought often') }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($frequent as $item)
                                <button type="button" wire:key="frequent-{{ md5($item['label']) }}" wire:click="addFrequent({{ \Illuminate\Support\Js::from($item['label']) }}, {{ \Illuminate\Support\Js::from($item['quantity']) }})" class="rounded-full bg-gray-100 px-2.5 py-1 text-xs text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-indigo-900/40">＋ {{ $item['label'] }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Barcode scanner: camera via the browser's BarcodeDetector, typing the number as fallback --}}
                <div
                    x-data="barcodeScanner"
                    x-on:open-barcode-scanner.window="open()"
                    x-on:keydown.escape.window="close()"
                    x-show="visible"
                    x-cloak
                    class="fixed inset-0 z-[60] flex flex-col bg-black text-white"
                    role="dialog"
                    aria-modal="true"
                    aria-label="{{ __('Scan barcode') }}"
                >
                    <div class="flex items-center justify-between px-4 py-3">
                        <p class="font-medium">{{ __('Scan barcode') }}</p>
                        <button type="button" x-on:click="close()" class="text-2xl" aria-label="{{ __('Close') }}">✕</button>
                    </div>
                    <div class="relative flex-1 overflow-hidden">
                        <video x-ref="video" x-show="supported && ! failed" playsinline muted class="h-full w-full object-cover"></video>
                        <div x-show="supported && ! failed" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                            <div class="h-28 w-64 rounded-lg border-4 border-white/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]"></div>
                        </div>
                        <p x-show="! supported || failed" class="p-6 text-center text-gray-300" x-text="failed ? @js(__('The camera could not be started. Please allow camera access or type the number.')) : @js(__('This browser cannot open the camera here. Type the number below the barcode instead.'))"></p>
                    </div>
                    <form x-on:submit.prevent="submitManual()" class="flex gap-2 bg-gray-900 p-4">
                        <input x-model="manualCode" inputmode="numeric" pattern="[0-9]*" class="block flex-1 rounded-md border-gray-700 bg-gray-800 text-white" placeholder="{{ __('Barcode number, e.g. 4000417025005') }}" aria-label="{{ __('Barcode number') }}" />
                        <button type="submit" class="rounded-md bg-indigo-600 px-4 font-medium">{{ __('Find product') }}</button>
                    </form>
                </div>
            </section>

            {{-- Items --}}
            <section class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800 sm:p-5">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        @if ($byAisle && $list->store)
                            🏪 {{ __('Sorted by the walking route of :store', ['store' => $list->store->name]) }}
                        @else
                            <span class="sm:hidden">{{ __('Swipe an item left to delete it.') }}</span>
                            <span class="hidden sm:inline">{{ __('Drag items to sort them or to move them to another section.') }}</span>
                        @endif
                    </p>
                    <div class="inline-flex rounded-lg bg-gray-100 p-0.5 text-xs dark:bg-gray-900" role="group" aria-label="{{ __('Sorting') }}">
                        <button type="button" wire:click="$set('byAisle', true)" aria-pressed="{{ $byAisle ? 'true' : 'false' }}" @class(['rounded-md px-2 py-1', 'bg-white font-medium shadow-sm dark:bg-gray-700 dark:text-gray-100' => $byAisle, 'text-gray-600 dark:text-gray-400' => ! $byAisle])>🏪 {{ __('By section') }}</button>
                        <button type="button" wire:click="$set('byAisle', false)" aria-pressed="{{ ! $byAisle ? 'true' : 'false' }}" @class(['rounded-md px-2 py-1', 'bg-white font-medium shadow-sm dark:bg-gray-700 dark:text-gray-100' => ! $byAisle, 'text-gray-600 dark:text-gray-400' => $byAisle])>🕒 {{ __('Newest first') }}</button>
                    </div>
                </div>

                <div class="space-y-4">
                    @forelse ($groups as $groupKey => $group)
                        <div wire:key="group-{{ $list->id }}-{{ $groupKey }}">
                            @if ($group['label'])
                                <h4 class="mb-1.5 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><span aria-hidden="true">{{ $group['icon'] }}</span> {{ $group['label'] }} <span class="font-normal normal-case text-gray-400">· {{ $group['entries']->count() }}</span></h4>
                            @endif
                            <ul class="min-h-[2.5rem] space-y-1.5 rounded-xl"
                                @if ($group['key'])
                                    x-sort="$wire.moveEntry($item, @js($group['key']), $position)"
                                    x-sort:group="shopping-{{ $list->id }}"
                                    x-sort:config="{ animation: 150, handle: '.drag-handle', ghostClass: 'opacity-40' }"
                                @endif
                            >
                                @foreach ($group['entries'] as $entry)
                                    <li
                                        wire:key="entry-{{ $entry->id }}"
                                        x-sort:item="{{ $entry->id }}"
                                        x-data="e11Swipe({ onRight: () => $wire.toggle({{ $entry->id }}), leaveOnRight: true, onLeft: () => $wire.delete({{ $entry->id }}), leaveOnLeft: true })"
                                        class="relative overflow-hidden rounded-xl"
                                    >
                                        <div class="absolute inset-0 flex items-center justify-between rounded-xl px-4 text-sm font-medium text-white" aria-hidden="true" :class="dx > 0 ? 'bg-emerald-600' : (dx < 0 ? 'bg-red-600' : '')">
                                            <span x-show="dx > 0" x-cloak>✓ {{ __('Bought') }}</span>
                                            <span x-show="dx < 0" x-cloak class="ml-auto">{{ __('Delete') }}</span>
                                        </div>
                                        <div
                                            x-ref="content"
                                            style="touch-action: pan-y"
                                            x-on:pointerdown="start($event)" x-on:pointermove="move($event)" x-on:pointerup="end()" x-on:pointercancel="end()"
                                            :class="{ 'opacity-40': leaving }"
                                            @class(['relative flex items-center gap-3 rounded-xl border border-gray-200 bg-white transition-opacity dark:border-gray-700 dark:bg-gray-800', 'px-3 py-2.5' => ! $shoppingMode, 'px-4 py-4' => $shoppingMode])
                                        >
                                            @if ($group['key'])
                                                <span class="drag-handle hidden cursor-grab select-none text-gray-300 hover:text-gray-500 sm:inline dark:text-gray-600" aria-hidden="true" title="{{ __('Drag to sort') }}">⋮⋮</span>
                                            @endif
                                            <input type="checkbox" x-on:click="E11Haptic()" wire:click="toggle({{ $entry->id }})" @checked($entry->checked) aria-label="{{ __('Bought: :item', ['item' => $entry->label]) }}"
                                                @class(['rounded-full border-gray-300 text-emerald-600 focus:ring-emerald-500 dark:border-gray-600', 'h-5 w-5' => ! $shoppingMode, 'h-7 w-7' => $shoppingMode]) />
                                            <button type="button" wire:click="startEdit({{ $entry->id }})" class="min-w-0 flex-1 text-start">
                                                <span @class(['block truncate text-gray-900 dark:text-gray-100', 'text-lg font-medium' => $shoppingMode])>{{ $entry->label }}</span>
                                                @if ($entry->note || $entry->price)
                                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $entry->note }}@if ($entry->note && $entry->price) · @endif @if ($entry->price){{ $euro($entry->price) }}@endif</span>
                                                @endif
                                            </button>
                                            @if ($entry->quantity)
                                                <span @class(['shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-gray-700 dark:bg-gray-700 dark:text-gray-200', 'text-xs' => ! $shoppingMode, 'text-sm' => $shoppingMode])>{{ $entry->quantity }}</span>
                                            @endif
                                            <button type="button" wire:click="delete({{ $entry->id }})" class="hidden shrink-0 rounded-lg p-1 text-gray-300 hover:bg-red-50 hover:text-red-600 sm:inline dark:text-gray-600 dark:hover:bg-red-900/30" aria-label="{{ __('Delete :item', ['item' => $entry->label]) }}">✕</button>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @empty
                        <div class="py-8 text-center">
                            <p class="text-4xl" aria-hidden="true">{{ $done->isNotEmpty() ? '🎉' : '🧺' }}</p>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $done->isNotEmpty() ? __('Everything bought!') : __('Shopping list is empty.') }}</p>
                            @if ($done->isEmpty())
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Add items above, tap “Bought often” or add the ingredients of a recipe.') }}</p>
                            @endif
                        </div>
                    @endforelse
                </div>

                @if ($done->isNotEmpty())
                    <details class="mt-5 rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40" @if ($groups->isEmpty()) open @endif>
                        <summary class="flex cursor-pointer items-center justify-between gap-2 text-sm font-medium text-gray-600 dark:text-gray-300">
                            <span>✓ {{ trans_choice(':count bought|:count bought', $done->count()) }}</span>
                            <span class="flex gap-3 text-xs font-normal">
                                <button type="button" wire:click.stop="uncheckAll" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Back to the list') }}</button>
                                <button type="button" wire:click.stop="clearDone" class="text-red-600 underline dark:text-red-400">{{ __('Remove') }}</button>
                            </span>
                        </summary>
                        <ul class="mt-2 space-y-1">
                            @foreach ($done as $entry)
                                <li wire:key="done-{{ $entry->id }}" class="flex items-center gap-3 rounded-lg px-2 py-1.5">
                                    <input type="checkbox" x-on:click="E11Haptic()" wire:click="toggle({{ $entry->id }})" checked class="h-5 w-5 rounded-full border-gray-300 text-emerald-600 dark:border-gray-600" aria-label="{{ __('Not bought yet: :item', ['item' => $entry->label]) }}" />
                                    <span class="flex-1 text-gray-400 line-through">{{ $entry->label }}</span>
                                    @if ($entry->quantity)<span class="text-xs text-gray-400">{{ $entry->quantity }}</span>@endif
                                    <button type="button" wire:click="delete({{ $entry->id }})" class="text-xs text-gray-400 hover:text-red-600" aria-label="{{ __('Delete :item', ['item' => $entry->label]) }}">✕</button>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>
        </div>
    </div>

    {{-- Edit an item --}}
    @if ($editingId)
        <x-sheet close="closeEdit" :label="__('Edit item')" size="md" wire:key="edit-entry-{{ $editingId }}">
            <x-slot name="header">✎ {{ __('Edit item') }}</x-slot>
            <form wire:submit="saveEdit" id="edit-entry-form" class="space-y-3">
                <div>
                    <x-input-label for="edit-label" :value="__('Item')" />
                    <input id="edit-label" wire:model="edit.label" class="{{ $input }} mt-1">
                    <x-input-error :messages="$errors->get('edit.label')" class="mt-1" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <x-input-label for="edit-quantity" :value="__('Quantity')" />
                        <input id="edit-quantity" wire:model="edit.quantity" class="{{ $input }} mt-1">
                    </div>
                    <div>
                        <x-input-label for="edit-list" :value="__('List')" />
                        <select id="edit-list" wire:model="edit.list" class="{{ $input }} mt-1">
                            @foreach ($lists as $option)
                                <option value="{{ $option->id }}">{{ $option->icon }} {{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <x-input-label for="edit-note" :value="__('Note (brand, variety …)')" />
                    <input id="edit-note" wire:model="edit.note" class="{{ $input }} mt-1">
                </div>
            </form>
            <x-slot name="footer">
                <x-primary-button form="edit-entry-form">{{ __('Save') }}</x-primary-button>
                <x-secondary-button type="button" wire:click="$set('editingId', null)">{{ __('Cancel') }}</x-secondary-button>
            </x-slot>
        </x-sheet>
    @endif

    {{-- New / edit list --}}
    @if ($listFormOpen)
        <x-sheet close="closeListForm" :label="$editingListId ? __('Edit list') : __('New list')" size="md" wire:key="list-form-{{ $editingListId ?? 'new' }}">
            <x-slot name="header">{{ $editingListId ? __('Edit list') : __('New list') }}</x-slot>
            <form wire:submit="saveList" id="list-form" class="space-y-4">
                <div>
                    <x-input-label for="list-name" :value="__('Name')" />
                    <input id="list-name" wire:model="listForm.name" placeholder="{{ __('e.g. Weekly shop, Drugstore, Party') }}" class="{{ $input }} mt-1">
                    <x-input-error :messages="$errors->get('listForm.name')" class="mt-1" />
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Icon') }}</p>
                    <div class="mt-1 flex flex-wrap gap-1.5" role="radiogroup">
                        @foreach ($icons as $icon)
                            <label @class(['flex h-10 w-10 cursor-pointer items-center justify-center rounded-xl text-xl ring-1', 'bg-indigo-50 ring-indigo-500 dark:bg-indigo-900/40' => $listForm['icon'] === $icon, 'ring-gray-200 dark:ring-gray-700' => $listForm['icon'] !== $icon])>
                                <input type="radio" wire:model.live="listForm.icon" value="{{ $icon }}" class="sr-only"> {{ $icon }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <x-input-label for="list-store-form" :value="__('Store (sorts the list by its walking route)')" />
                    <select id="list-store-form" wire:model="listForm.store_id" class="{{ $input }} mt-1">
                        <option value="">{{ __('Any store') }}</option>
                        @foreach ($stores as $store)
                            <option value="{{ $store->id }}">{{ $store->name }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
            <x-slot name="footer">
                <x-primary-button form="list-form">{{ __('Save') }}</x-primary-button>
                <x-secondary-button type="button" wire:click="$set('listFormOpen', false)">{{ __('Cancel') }}</x-secondary-button>
                @if ($editingListId && $lists->count() > 1)
                    <button type="button" wire:click="deleteList({{ $editingListId }})" wire:confirm="{{ __('Delete this list with all its items?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete list') }}</button>
                @endif
            </x-slot>
        </x-sheet>
    @endif

    {{-- Stores and their walking route --}}
    @if ($storesOpen)
        <x-sheet close="closeStores" :label="__('Stores & walking route')" size="md" wire:key="stores-sheet">
            <x-slot name="header">🏪 {{ __('Stores & walking route') }}</x-slot>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Add the stores you shop at and drag the sections into the order you walk through the store. Lists with this store are sorted that way.') }}</p>

            <ul class="mt-4 space-y-2">
                @forelse ($stores as $store)
                    <li wire:key="store-{{ $store->id }}" class="rounded-xl border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between gap-2 px-3 py-2">
                            <button type="button" wire:click="$set('sortingStoreId', {{ $sortingStoreId === $store->id ? 'null' : $store->id }})" class="min-w-0 flex-1 text-start" aria-expanded="{{ $sortingStoreId === $store->id ? 'true' : 'false' }}">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $store->name }}</span>
                                @if ($store->chainName())<span class="text-xs text-gray-500"> · {{ $store->chainName() }}</span>@endif
                            </button>
                            <button type="button" wire:click="deleteStore({{ $store->id }})" wire:confirm="{{ __('Delete this store?') }}" class="text-xs text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
                        </div>
                        @if ($sortingStoreId === $store->id)
                            <ol class="space-y-1 border-t border-gray-100 p-2 dark:border-gray-700" x-sort="$wire.sortAisle({{ $store->id }}, $item, $position)" x-sort:config="{ animation: 150 }">
                                @foreach ($store->aisleOrder() as $index => $aisleKey)
                                    <li wire:key="store-{{ $store->id }}-aisle-{{ $aisleKey }}" x-sort:item="@js($aisleKey)" class="flex cursor-grab items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900/50">
                                        <span class="text-gray-400" aria-hidden="true">⋮⋮</span>
                                        <span class="w-5 text-xs text-gray-400">{{ $index + 1 }}.</span>
                                        <span aria-hidden="true">{{ $aisles[$aisleKey]['icon'] }}</span> {{ $aisles[$aisleKey]['label'] }}
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </li>
                @empty
                    <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('No stores yet.') }}</li>
                @endforelse
            </ul>

            <form wire:submit="addStore" class="mt-4 space-y-2 rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Add store') }}</p>
                <div>
                    <label class="sr-only" for="store-name">{{ __('Name') }}</label>
                    <input id="store-name" wire:model="storeForm.name" placeholder="{{ __('e.g. REWE Hauptstraße') }}" class="{{ $input }}">
                    <x-input-error :messages="$errors->get('storeForm.name')" class="mt-1" />
                </div>
                <div class="flex gap-2">
                    <label class="sr-only" for="store-chain">{{ __('Chain') }}</label>
                    <select id="store-chain" wire:model="storeForm.chain" class="{{ $input }} flex-1">
                        <option value="">{{ __('Chain (optional)') }}</option>
                        @foreach ($chains as $key => $chain)
                            <option value="{{ $key }}">{{ $key === 'other' ? __('Other') : $chain['name'] }}</option>
                        @endforeach
                    </select>
                    <x-secondary-button type="submit" class="shrink-0 justify-center">＋ {{ __('Add') }}</x-secondary-button>
                </div>
            </form>
        </x-sheet>
    @endif
</div>
