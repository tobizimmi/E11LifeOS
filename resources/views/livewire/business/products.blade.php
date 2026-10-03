<?php

use App\Livewire\Concerns\InteractsWithBusiness;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithBusiness;
    use InteractsWithHousehold;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array{name: string, description: string, unit: string, unit_price: string, vat_rate: string}
     */
    public array $form = [];

    private function products()
    {
        return Product::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId);
    }

    public function newProduct(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form = ['name' => '', 'description' => '', 'unit' => '', 'unit_price' => '', 'vat_rate' => (string) ($this->businessProfile()?->default_vat_rate ?? 19)];
        $this->showForm = true;
    }

    public function editProduct(int $productId): void
    {
        $product = $this->products()->find($productId) ?? abort(404);
        $this->resetValidation();
        $this->editingId = $product->id;
        $this->form = [
            'name' => $product->name,
            'description' => (string) $product->description,
            'unit' => (string) $product->unit,
            'unit_price' => number_format($product->unit_price, 2, ',', ''),
            'vat_rate' => rtrim(rtrim(number_format($product->vat_rate, 2, '.', ''), '0'), '.'),
        ];
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function saveProduct(): void
    {
        $this->form['unit_price'] = str_replace(',', '.', str_replace('.', '', trim((string) $this->form['unit_price'])));

        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.description' => ['nullable', 'string', 'max:2000'],
            'form.unit' => ['nullable', 'string', 'max:24'],
            'form.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'form.vat_rate' => ['required', 'numeric', 'min:0', 'max:99'],
        ], attributes: ['form.name' => __('Name'), 'form.unit_price' => __('Unit price')])['form'];

        $data = ['description' => $data['description'] ?: null, 'unit' => $data['unit'] ?: null] + $data;

        if ($this->editingId) {
            ($this->products()->find($this->editingId) ?? abort(404))->update($data);
        } else {
            Product::create(['user_id' => $this->currentUser()->id, 'household_id' => $this->householdId] + $data);
        }

        $this->showForm = false;
    }

    public function deleteProduct(int $productId): void
    {
        ($this->products()->find($productId) ?? abort(404))->delete();
        $this->showForm = false;
    }

    public function with(): array
    {
        $term = mb_strtolower(trim($this->search));

        return [
            'profile' => $this->businessProfile(),
            'productList' => $this->products()
                ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(description) LIKE ?', ['%'.$term.'%'])))
                ->orderBy('name')->get(),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $profile?->company_name ?: __('Small business') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your business data.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('business.partials.nav', ['active' => 'business.products'])

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search catalogue …') }}" aria-label="{{ __('Search') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 sm:w-auto sm:min-w-0 sm:flex-1">
                <x-primary-button type="button" wire:click="newProduct">＋ {{ __('New service or product') }}</x-primary-button>
            </div>

            @if ($productList->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center shadow-xs dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">📦</p>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $search !== '' ? __('Nothing found.') : __('No services or products yet. Save what you sell regularly – with price and unit it is added to invoices and quotes with one click.') }}</p>
                </div>
            @else
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white shadow-xs dark:divide-gray-700 dark:bg-gray-800">
                    @foreach ($productList as $product)
                        <li wire:key="product-{{ $product->id }}">
                            <button type="button" wire:click="editProduct({{ $product->id }})" class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $product->name }}</span>
                                    @if ($product->description)
                                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $product->description }}</span>
                                    @endif
                                </span>
                                <span class="shrink-0 text-end text-sm tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($product->unit_price) }}<span class="text-xs text-gray-500">{{ $product->unit ? ' / '.$product->unit : '' }}</span></span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @if ($showForm)
        <x-sheet close="closeForm" :label="$editingId ? __('Edit service or product') : __('New service or product')" wire:key="product-form-{{ $editingId ?? 'new' }}">
            <x-slot:header><strong class="text-gray-800 dark:text-gray-100">📦 {{ $editingId ? __('Edit service or product') : __('New service or product') }}</strong></x-slot:header>

            <form id="product-form" wire:submit="saveProduct" class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-3">
                    <x-input-label for="product-name" :value="__('Name')" />
                    <x-text-input id="product-name" wire:model="form.name" class="mt-1 block w-full" placeholder="{{ __('e.g. Consulting hour') }}" />
                    <x-input-error :messages="$errors->get('form.name')" class="mt-1" />
                </div>
                <div class="sm:col-span-3">
                    <x-input-label for="product-description" :value="__('Description (optional)')" />
                    <textarea id="product-description" wire:model="form.description" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"></textarea>
                </div>
                <div>
                    <x-input-label for="product-price" :value="__('Unit price (net, €)')" />
                    <x-text-input id="product-price" wire:model="form.unit_price" inputmode="decimal" class="mt-1 block w-full" placeholder="0,00" />
                    <x-input-error :messages="$errors->get('form.unit_price')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="product-unit" :value="__('Unit')" />
                    <x-text-input id="product-unit" wire:model="form.unit" class="mt-1 block w-full" placeholder="{{ __('Hrs.') }}" />
                </div>
                <div>
                    <x-input-label for="product-vat" :value="__('VAT')" />
                    <select id="product-vat" wire:model="form.vat_rate" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        <option value="19">19 %</option>
                        <option value="7">7 %</option>
                        <option value="0">0 %</option>
                    </select>
                </div>
            </form>

            <x-slot:footer>
                <x-primary-button form="product-form">{{ __('Save') }}</x-primary-button>
                @if ($editingId)
                    <button type="button" wire:click="deleteProduct({{ $editingId }})" wire:confirm="{{ __('Delete from the catalogue? Existing documents stay unchanged.') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                @endif
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
