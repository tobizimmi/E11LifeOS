<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\ShoppingListEntry;
use App\Models\ShoppingStore;
use App\Models\ShoppingWatch;
use App\Support\Shopping\Offers;
use App\Support\Shopping\OffersUnavailable;
use App\Support\ShoppingLists;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Current offers of the chosen supermarkets near the household's postcode: for the brands and
 * products on the watch list, for items on the shopping list, or for a free search. Offers go
 * onto a shopping list with one tap.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Url(as: 'list', except: null)]
    public ?int $listId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $zip = '';

    /**
     * @var list<string>
     */
    public array $chains = [];

    public string $watchTerm = '';

    public bool $forList = false;

    public function mount(): void
    {
        $this->resolveHousehold();
        $household = Household::find($this->householdId);
        $this->zip = (string) $household->offers_zip;
        $this->chains = array_values($household->offers_chains ?? []);
        $this->listId = $this->targetList()->id;
    }

    private function targetList(): ShoppingList
    {
        return ($this->listId ? ShoppingList::where('household_id', $this->householdId)->find($this->listId) : null)
            ?? ShoppingList::defaultFor((int) $this->householdId);
    }

    public function saveSettings(): void
    {
        $data = $this->validate([
            'zip' => ['nullable', 'regex:/^\d{5}$/'],
            'chains' => ['array'],
            'chains.*' => [Rule::in(array_keys(ShoppingStore::CHAINS))],
        ], attributes: ['zip' => __('Postcode')]);

        Household::whereKey($this->householdId)->update([
            'offers_zip' => $data['zip'] ?: null,
            'offers_chains' => json_encode(array_values($data['chains'])),
        ]);
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function toggleChain(string $chain): void
    {
        abort_unless(array_key_exists($chain, ShoppingStore::CHAINS) && $chain !== 'other', 404);
        $this->chains = in_array($chain, $this->chains, true)
            ? array_values(array_diff($this->chains, [$chain]))
            : [...$this->chains, $chain];
        $this->saveSettings();
    }

    public function addWatch(): void
    {
        $this->validate(['watchTerm' => ['required', 'string', 'min:2', 'max:60']], attributes: ['watchTerm' => __('Brand or product')]);

        ShoppingWatch::firstOrCreate(['household_id' => $this->householdId, 'term' => trim($this->watchTerm)], ['created_by' => Auth::id()]);
        $this->watchTerm = '';
    }

    public function removeWatch(int $id): void
    {
        ShoppingWatch::where('household_id', $this->householdId)->whereKey($id)->delete();
    }

    /**
     * Puts an offer on the chosen list, with store, price and end date as note.
     *
     * @param  array{title: string, brand: ?string, store: string, price: ?float, unit: ?string, valid_to: ?string}  $offer
     */
    public function addOffer(array $offer): void
    {
        $title = mb_substr(trim((string) ($offer['title'] ?? '')), 0, 200);
        abort_if($title === '', 422);
        $brand = trim((string) ($offer['brand'] ?? ''));
        $label = $brand !== '' && ! str_contains(mb_strtolower($title), mb_strtolower($brand)) ? $brand.' '.$title : $title;
        $validTo = ! empty($offer['valid_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $offer['valid_to']) ? Carbon::parse($offer['valid_to'])->format('d.m.') : null;
        $note = trim(__('Offer').' '.mb_substr((string) ($offer['store'] ?? ''), 0, 60).($validTo ? ' '.__('until :date', ['date' => $validTo]) : ''));

        ShoppingLists::add($this->targetList(), $label, isset($offer['unit']) ? mb_substr((string) $offer['unit'], 0, 60) : null, Auth::id(), [
            'note' => $note,
            'price' => isset($offer['price']) && is_numeric($offer['price']) ? round((float) $offer['price'], 2) : null,
        ]);
        $this->dispatch('toast', message: __(':item is on the list “:list”.', ['item' => $label, 'list' => $this->targetList()->name]));
    }

    public function with(): array
    {
        $offers = app(Offers::class);
        $watches = ShoppingWatch::where('household_id', $this->householdId)->orderBy('term')->get();
        $sections = [];
        $error = null;
        $zipValid = (bool) preg_match('/^\d{5}$/', $this->zip);

        if ($zipValid) {
            try {
                if (trim($this->search) !== '') {
                    $sections[] = ['title' => __('Search: :term', ['term' => $this->search]), 'offers' => $offers->search($this->search, $this->zip, $this->chains)];
                }

                if ($this->forList) {
                    ShoppingListEntry::where('shopping_list_id', $this->targetList()->id)->where('checked', false)->orderBy('label')->limit(12)->pluck('label')
                        ->each(function (string $label) use (&$sections, $offers) {
                            $found = $offers->search($label, $this->zip, $this->chains, 6);

                            if ($found !== []) {
                                $sections[] = ['title' => '🛒 '.$label, 'offers' => $found];
                            }
                        });
                }

                foreach ($watches as $watch) {
                    $sections[] = ['title' => '⭐ '.$watch->term, 'offers' => $offers->search($watch->term, $this->zip, $this->chains, 12), 'watch' => $watch->id];
                }
            } catch (OffersUnavailable $exception) {
                $error = $exception->getMessage();
            }
        }

        return [
            'watches' => $watches,
            'sections' => $sections,
            'error' => $error,
            'zipValid' => $zipValid,
            'allChains' => collect(ShoppingStore::CHAINS)->except('other')->all(),
            'lists' => ShoppingList::where('household_id', $this->householdId)->orderBy('position')->orderBy('id')->get(),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $input = 'block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <a href="{{ route('shopping-list.index', ['list' => $listId]) }}" wire:navigate class="text-sm text-gray-500 hover:underline dark:text-gray-400">← {{ __('Shopping List') }}</a>
                <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🏷️ {{ __('Offers') }}</h2>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            {{-- Kept inside the component: the header slot is rendered outside of it, where wire:model does not reach --}}
            <div class="flex justify-end">
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('Add to') }}
                    <select wire:model.live="listId" class="rounded-lg border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        @foreach ($lists as $option)
                            <option value="{{ $option->id }}">{{ $option->icon }} {{ $option->name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <div class="grid gap-4 md:grid-cols-[15rem_1fr]">
                    <form wire:submit="saveSettings">
                        <x-input-label for="offers-zip" :value="__('Your postcode')" />
                        <div class="mt-1 flex gap-2">
                            <input id="offers-zip" wire:model="zip" inputmode="numeric" maxlength="5" placeholder="80331" class="{{ $input }} min-w-0 flex-1">
                            <x-secondary-button type="submit">{{ __('Save') }}</x-secondary-button>
                        </div>
                        <x-input-error :messages="$errors->get('zip')" class="mt-1" />
                    </form>
                    <div>
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Your stores') }} <span class="font-normal text-gray-500">{{ $chains === [] ? __('(all)') : '' }}</span></p>
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            @foreach ($allChains as $key => $chain)
                                <button type="button" wire:key="chain-{{ $key }}" wire:click="toggleChain('{{ $key }}')" aria-pressed="{{ in_array($key, $chains, true) ? 'true' : 'false' }}"
                                    @class(['rounded-full px-3 py-1 text-xs font-medium ring-1', 'bg-indigo-600 text-white ring-indigo-600' => in_array($key, $chains, true), 'bg-white text-gray-700 ring-gray-200 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700' => ! in_array($key, $chains, true)])>{{ $chain['name'] }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <div>
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">⭐ {{ __('Brands & products you follow') }}</p>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            @foreach ($watches as $watch)
                                <span wire:key="watch-{{ $watch->id }}" class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs text-amber-800 ring-1 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-200 dark:ring-amber-800">
                                    {{ $watch->term }}
                                    <button type="button" wire:click="removeWatch({{ $watch->id }})" class="text-amber-600 hover:text-red-600" aria-label="{{ __('Stop following :term', ['term' => $watch->term]) }}">×</button>
                                </span>
                            @endforeach
                            <form wire:submit="addWatch" class="flex items-center gap-1">
                                <input wire:model="watchTerm" placeholder="{{ __('e.g. Nutella, Barilla, coffee') }}" aria-label="{{ __('Brand or product') }}" class="w-44 rounded-full border-gray-300 px-3 py-1 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                <button type="submit" class="rounded-full bg-gray-100 px-2.5 py-1 text-xs dark:bg-gray-700 dark:text-gray-200">＋</button>
                            </form>
                        </div>
                        <x-input-error :messages="$errors->get('watchTerm')" class="mt-1" />
                    </div>
                    <div>
                        <label for="offers-search" class="text-sm font-medium text-gray-700 dark:text-gray-300">🔎 {{ __('Search offers') }}</label>
                        <div class="mt-1 flex gap-2">
                            <input id="offers-search" type="search" wire:model.live.debounce.500ms="search" placeholder="{{ __('e.g. butter') }}" class="{{ $input }}">
                            <button type="button" wire:click="$toggle('forList')" aria-pressed="{{ $forList ? 'true' : 'false' }}" @class(['shrink-0 rounded-lg px-3 text-xs font-medium ring-1', 'bg-emerald-600 text-white ring-emerald-600' => $forList, 'bg-white text-gray-700 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700' => ! $forList])>🛒 {{ __('For my list') }}</button>
                        </div>
                    </div>
                </div>
            </section>

            @if (! $zipValid)
                <p class="rounded-2xl bg-white p-6 text-center text-sm text-gray-600 shadow-sm dark:bg-gray-800 dark:text-gray-300">📍 {{ __('Enter your postcode to see the offers of the stores near you.') }}</p>
            @elseif ($error)
                <p class="rounded-2xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ $error }} {{ __('The weekly flyers of your stores are linked below.') }}</p>
            @elseif ($sections === [])
                <p class="rounded-2xl bg-white p-6 text-center text-sm text-gray-600 shadow-sm dark:bg-gray-800 dark:text-gray-300">⭐ {{ __('Follow brands or products, search, or tap “For my list” to see matching offers.') }}</p>
            @endif

            @foreach ($sections as $index => $section)
                <section wire:key="offers-section-{{ $index }}-{{ md5($section['title']) }}" class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $section['title'] }} <span class="text-sm font-normal text-gray-500">· {{ count($section['offers']) }}</span></h3>
                    @if ($section['offers'] === [])
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('No current offers.') }}</p>
                    @else
                        <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($section['offers'] as $offer)
                                <li wire:key="offer-{{ $index }}-{{ $offer['id'] }}" class="flex gap-3 rounded-xl border border-gray-100 p-3 dark:border-gray-700">
                                    @if ($offer['image'])
                                        <img src="{{ $offer['image'] }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="h-16 w-16 shrink-0 rounded-lg bg-gray-50 object-contain">
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $offer['store'] }}@if ($offer['valid_to']) · {{ __('until :date', ['date' => \Illuminate\Support\Carbon::parse($offer['valid_to'])->format('d.m.')]) }}@endif</p>
                                        <p class="line-clamp-2 text-sm font-medium text-gray-900 dark:text-gray-100">@if ($offer['brand']){{ $offer['brand'] }} @endif{{ $offer['title'] }}</p>
                                        <p class="mt-0.5 flex items-baseline gap-2">
                                            @if ($offer['price'] !== null)<span class="font-semibold text-red-600 dark:text-red-400">{{ $euro($offer['price']) }}</span>@endif
                                            @if ($offer['old_price'])<span class="text-xs text-gray-400 line-through">{{ $euro($offer['old_price']) }}</span>@endif
                                            @if ($offer['old_price'] && $offer['price'])<span class="rounded bg-red-50 px-1 text-xs font-medium text-red-700 dark:bg-red-900/40 dark:text-red-300">−{{ round((1 - $offer['price'] / $offer['old_price']) * 100) }} %</span>@endif
                                        </p>
                                        @if ($offer['unit'])<p class="text-xs text-gray-500">{{ $offer['unit'] }}</p>@endif
                                    </div>
                                    <button type="button" wire:click="addOffer({{ \Illuminate\Support\Js::from(\Illuminate\Support\Arr::only($offer, ['title', 'brand', 'store', 'price', 'unit', 'valid_to'])) }})" class="self-start rounded-lg bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/40 dark:text-indigo-200" aria-label="{{ __('Add :item to the list', ['item' => $offer['title']]) }}">＋ 🛒</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">📰 {{ __('Weekly flyers') }}</h3>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($chains === [] ? array_keys($allChains) : $chains as $key)
                        @if (! empty($allChains[$key]['flyer']))
                            <a href="{{ $allChains[$key]['flyer'] }}" target="_blank" rel="noopener" class="rounded-lg bg-gray-50 px-3 py-1.5 text-sm text-gray-700 ring-1 ring-gray-200 hover:bg-gray-100 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-700">{{ $allChains[$key]['name'] }} ↗</a>
                        @endif
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Offers come from marktguru.de and may differ per branch. Prices without guarantee.') }}</p>
            </section>
        </div>
    </div>
</div>
