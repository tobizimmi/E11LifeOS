<?php

use App\Livewire\Concerns\InteractsWithBusiness;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Customer;
use App\Models\Invoice;
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
     * @var array<string, string>
     */
    public array $form = [];

    private function emptyForm(): array
    {
        return ['name' => '', 'contact_person' => '', 'street' => '', 'postal_code' => '', 'city' => '', 'country' => '', 'email' => '', 'vat_id' => '', 'buyer_reference' => '', 'notes' => ''];
    }

    public function newCustomer(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form = $this->emptyForm();
        $this->showForm = true;
    }

    public function editCustomer(int $customerId): void
    {
        $customer = $this->customers()->find($customerId) ?? abort(404);
        $this->resetValidation();
        $this->editingId = $customer->id;
        $this->form = array_map(fn ($value) => (string) $value, $customer->only(array_keys($this->emptyForm())));
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function saveCustomer(): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.contact_person' => ['nullable', 'string', 'max:255'],
            'form.street' => ['nullable', 'string', 'max:255'],
            'form.postal_code' => ['nullable', 'string', 'max:16'],
            'form.city' => ['nullable', 'string', 'max:255'],
            'form.country' => ['nullable', 'string', 'max:64'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.vat_id' => ['nullable', 'string', 'max:32'],
            'form.buyer_reference' => ['nullable', 'string', 'max:64'],
            'form.notes' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['form.name' => __('Name')])['form'];

        if ($this->editingId) {
            ($this->customers()->find($this->editingId) ?? abort(404))->update($data);
        } else {
            Customer::create(['user_id' => $this->currentUser()->id, 'household_id' => $this->householdId] + $data);
        }

        $this->showForm = false;
    }

    public function deleteCustomer(int $customerId): void
    {
        // Invoices keep the printed recipient address.
        ($this->customers()->find($customerId) ?? abort(404))->delete();
        $this->showForm = false;
    }

    public function with(): array
    {
        $term = mb_strtolower(trim($this->search));

        return [
            'profile' => $this->businessProfile(),
            'customerList' => $this->customers()
                ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(contact_person) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(city) LIKE ?', ['%'.$term.'%'])))
                ->withCount(['invoices' => fn ($query) => $query->where('type', Invoice::INVOICE)->where('status', '!=', Invoice::DRAFT)])
                ->withSum(['invoices as paid_total' => fn ($query) => $query->where('type', '!=', Invoice::QUOTE)], 'paid_total')
                ->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">💼 {{ $profile?->company_name ?: __('Small business') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your business data.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('business.partials.nav', ['active' => 'business.customers'])

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search customers …') }}" aria-label="{{ __('Search') }}" class="w-full rounded-lg sm:w-auto sm:min-w-0 sm:flex-1 border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                <x-primary-button type="button" wire:click="newCustomer">＋ {{ __('New customer') }}</x-primary-button>
            </div>

            @if ($customerList->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">👥</p>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $search !== '' ? __('No customers found.') : __('No customers yet. Add your first customer – the address is then filled into invoices automatically.') }}</p>
                </div>
            @else
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($customerList as $customer)
                        <li wire:key="customer-{{ $customer->id }}">
                            <button type="button" wire:click="editCustomer({{ $customer->id }})" class="block h-full w-full rounded-2xl bg-white p-4 text-start shadow-sm ring-1 ring-transparent hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $customer->name }}</p>
                                <p class="mt-0.5 whitespace-pre-line text-xs text-gray-500 dark:text-gray-400">{{ collect([$customer->contact_person, trim($customer->postal_code.' '.$customer->city)])->filter()->implode(' · ') ?: '—' }}</p>
                                <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">{{ trans_choice(':count invoice|:count invoices', $customer->invoices_count) }} · {{ __('paid') }} {{ number_format((float) $customer->paid_total, 2, ',', '.') }} €</p>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @if ($showForm)
        <x-sheet close="closeForm" :label="$editingId ? __('Edit customer') : __('New customer')" wire:key="customer-form-{{ $editingId ?? 'new' }}">
            <x-slot:header><strong class="text-gray-800 dark:text-gray-100">👥 {{ $editingId ? __('Edit customer') : __('New customer') }}</strong></x-slot:header>

            <form id="customer-form" wire:submit="saveCustomer" class="grid gap-4 sm:grid-cols-2">
                @foreach ([
                    'name' => [__('Name / company'), 'sm:col-span-2'],
                    'contact_person' => [__('Contact person'), ''],
                    'email' => [__('Email'), ''],
                    'street' => [__('Street and number'), 'sm:col-span-2'],
                    'postal_code' => [__('Postal code'), ''],
                    'city' => [__('City'), ''],
                    'country' => [__('Country (if not Germany)'), ''],
                    'vat_id' => [__('VAT ID (optional)'), ''],
                    'buyer_reference' => [__('Buyer reference / Leitweg-ID (e-invoices, optional)'), ''],
                ] as $field => [$label, $span])
                    <div class="{{ $span }}">
                        <x-input-label :for="'customer-'.$field" :value="$label" />
                        <x-text-input :id="'customer-'.$field" wire:model="form.{{ $field }}" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('form.'.$field)" class="mt-1" />
                    </div>
                @endforeach
                <div class="sm:col-span-2">
                    <x-input-label for="customer-notes" :value="__('Notes')" />
                    <textarea id="customer-notes" wire:model="form.notes" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"></textarea>
                </div>
            </form>

            <x-slot:footer>
                <x-primary-button form="customer-form">{{ __('Save') }}</x-primary-button>
                @if ($editingId)
                    <a href="{{ route('business.invoices.create', ['customer' => $editingId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-gray-700">🧾 {{ __('New invoice') }}</a>
                    <a href="{{ route('business.invoices.create', ['customer' => $editingId, 'type' => 'quote']) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-gray-700">📝 {{ __('New quote') }}</a>
                    <button type="button" wire:click="deleteCustomer({{ $editingId }})" wire:confirm="{{ __('Delete this customer? Existing invoices keep the address.') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                @endif
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
