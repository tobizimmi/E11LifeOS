<?php

use App\Livewire\Concerns\InteractsWithBusiness;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithBusiness;
    use InteractsWithHousehold;

    #[Locked]
    public ?int $invoiceId = null;

    /**
     * invoice, quote or credit_note.
     */
    #[Locked]
    public string $type = Invoice::INVOICE;

    #[Locked]
    public ?int $relatedInvoiceId = null;

    public string $validUntil = '';

    public string $customerId = '';

    public string $recipient = '';

    public string $subject = '';

    public string $servicePeriod = '';

    public string $dueOn = '';

    public string $intro = '';

    public string $closing = '';

    /**
     * @var array<int, array{description: string, quantity: string, unit: string, unit_price: string, discount: string, vat_rate: string}>
     */
    public array $items = [];

    public function mount(?Invoice $invoice = null): void
    {
        $this->resolveHousehold();
        $profile = $this->businessProfile();

        if (! $profile) {
            $this->redirectRoute('business.settings', navigate: true);

            return;
        }

        if ($invoice?->exists) {
            abort_unless($invoice->user_id === $this->currentUser()->id && $invoice->household_id === $this->householdId, 404);

            if (! $invoice->isDraft()) {
                $this->redirectRoute('business.index', navigate: true);

                return;
            }

            $this->invoiceId = $invoice->id;
            $this->type = $invoice->type;
            $this->relatedInvoiceId = $invoice->related_invoice_id;
            $this->validUntil = (string) $invoice->valid_until?->toDateString();
            $this->customerId = (string) ($invoice->customer_id ?? '');
            $this->recipient = $invoice->recipient;
            $this->subject = (string) $invoice->subject;
            $this->servicePeriod = (string) $invoice->service_period;
            $this->dueOn = (string) $invoice->due_on?->toDateString();
            $this->intro = (string) $invoice->intro;
            $this->closing = (string) $invoice->closing;
            $this->items = $invoice->items->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => rtrim(rtrim(number_format($item->quantity, 3, '.', ''), '0'), '.'),
                'unit' => (string) $item->unit,
                'unit_price' => number_format($item->unit_price, 2, '.', ''),
                'discount' => $item->discount_percent > 0 ? rtrim(rtrim(number_format($item->discount_percent, 2, '.', ''), '0'), '.') : '',
                'vat_rate' => rtrim(rtrim(number_format($item->vat_rate, 2, '.', ''), '0'), '.'),
            ])->all();
        } else {
            $type = (string) request()->query('type', Invoice::INVOICE);
            $this->type = in_array($type, Invoice::TYPES, true) ? $type : Invoice::INVOICE;
            $this->servicePeriod = $this->type === Invoice::QUOTE ? '' : now()->translatedFormat('F Y');
            $this->intro = match ($this->type) {
                Invoice::QUOTE => __('Thank you for your enquiry. I am pleased to offer you the following:'),
                Invoice::CREDIT_NOTE => __('We credit you with the following amounts:'),
                default => __('Thank you for your order. I hereby invoice the following services:'),
            };
            $this->validUntil = $this->type === Invoice::QUOTE ? today()->addDays(30)->toDateString() : '';
            $this->closing = (string) $profile->invoice_footer;

            $customerId = request()->integer('customer');

            if ($customerId && ($customer = $this->customers()->find($customerId))) {
                $this->customerId = (string) $customer->id;
                $this->recipient = $customer->addressBlock();
            }
        }

        if ($this->items === []) {
            $this->addItem();
        }
    }

    public function updatedCustomerId(): void
    {
        $customer = $this->customerId !== '' ? $this->customers()->find((int) $this->customerId) : null;

        if ($customer) {
            $this->recipient = $customer->addressBlock();
        }
    }

    public function addItem(): void
    {
        $this->items[] = ['description' => '', 'quantity' => '1', 'unit' => '', 'unit_price' => '', 'discount' => '', 'vat_rate' => (string) ($this->businessProfile()?->default_vat_rate ?? 19)];
    }

    /**
     * Adds a line from the service catalogue (fills an empty last line first).
     */
    public function addProduct(int $productId): void
    {
        $product = Product::where('user_id', $this->currentUser()->id)->find($productId) ?? abort(404);
        $line = [
            'description' => trim($product->name.($product->description ? "\n".$product->description : '')),
            'quantity' => '1',
            'unit' => (string) $product->unit,
            'unit_price' => number_format($product->unit_price * ($this->type === Invoice::CREDIT_NOTE ? -1 : 1), 2, '.', ''),
            'discount' => '',
            'vat_rate' => rtrim(rtrim(number_format($product->vat_rate, 2, '.', ''), '0'), '.'),
        ];
        $last = array_key_last($this->items);

        if ($last !== null && trim($this->items[$last]['description']) === '' && trim($this->items[$last]['unit_price']) === '') {
            $this->items[$last] = $line;
        } else {
            $this->items[] = $line;
        }
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if ($this->items === []) {
            $this->addItem();
        }
    }

    public function moveItem(int $index, int $direction): void
    {
        $target = $index + $direction;

        if (isset($this->items[$index], $this->items[$target])) {
            [$this->items[$index], $this->items[$target]] = [$this->items[$target], $this->items[$index]];
        }
    }

    public function save(bool $issue = false): void
    {
        $profile = $this->businessProfile() ?? abort(404);

        foreach ($this->items as $index => $item) {
            $this->items[$index]['quantity'] = str_replace(',', '.', trim((string) $item['quantity']));
            $this->items[$index]['unit_price'] = str_replace(',', '.', trim((string) $item['unit_price']));
            $this->items[$index]['discount'] = str_replace(',', '.', trim((string) ($item['discount'] ?? '')));
        }

        // Drop untouched empty lines.
        $this->items = array_values(array_filter($this->items, fn (array $item) => trim($item['description']) !== '' || trim($item['unit_price']) !== ''));

        if ($this->items === []) {
            $this->addItem();
        }

        $this->validate([
            'customerId' => ['nullable', 'integer'],
            'recipient' => ['required', 'string', 'max:1000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'servicePeriod' => [$issue && $this->type === Invoice::INVOICE ? 'required' : 'nullable', 'string', 'max:255'],
            'dueOn' => ['nullable', 'date'],
            'validUntil' => ['nullable', 'date'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'intro' => ['nullable', 'string', 'max:2000'],
            'closing' => ['nullable', 'string', 'max:2000'],
            'items.*.description' => ['required', 'string', 'max:2000'],
            'items.*.quantity' => ['required', 'numeric', 'min:-99999', 'max:99999'],
            'items.*.unit' => ['nullable', 'string', 'max:24'],
            'items.*.unit_price' => ['required', 'numeric', 'min:-9999999', 'max:9999999'],
            'items.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:99'],
        ], attributes: [
            'recipient' => __('Recipient'),
            'servicePeriod' => __('Service date / period'),
            'items.*.description' => __('Description'),
            'items.*.quantity' => __('Quantity'),
            'items.*.unit_price' => __('Unit price'),
        ]);

        if ($issue && ! $profile->isCompleteForInvoices()) {
            $this->addError('recipient', __('Please complete your business details (address and tax number) in the settings first.'));

            return;
        }

        $customer = $this->customerId !== '' ? $this->customers()->find((int) $this->customerId) : null;
        $attributes = [
            'customer_id' => $customer?->id,
            'recipient' => trim($this->recipient),
            'subject' => trim($this->subject) ?: null,
            'service_period' => trim($this->servicePeriod) ?: null,
            'due_on' => $this->type === Invoice::INVOICE && $this->dueOn !== '' ? $this->dueOn : null,
            'valid_until' => $this->type === Invoice::QUOTE && $this->validUntil !== '' ? $this->validUntil : null,
            'intro' => trim($this->intro) ?: null,
            'closing' => trim($this->closing) ?: null,
            'small_business' => $profile->small_business,
        ];

        $invoice = $this->invoiceId
            ? ($this->invoices()->where('status', Invoice::DRAFT)->find($this->invoiceId) ?? abort(404))
            : new Invoice(['user_id' => $this->currentUser()->id, 'household_id' => $this->householdId, 'type' => $this->type, 'status' => Invoice::DRAFT]);

        $invoice->fill($attributes)->save();
        $invoice->items()->delete();

        foreach ($this->items as $position => $item) {
            $invoice->items()->create([
                'position' => $position + 1,
                'description' => trim($item['description']),
                'quantity' => (float) $item['quantity'],
                'unit' => trim($item['unit']) ?: null,
                'unit_price' => round((float) $item['unit_price'], 2),
                'discount_percent' => (float) ($item['discount'] ?: 0),
                'vat_rate' => $profile->small_business ? 0 : (float) $item['vat_rate'],
            ]);
        }

        $invoice->recalculate();

        if ($issue) {
            if ($invoice->due_on && $invoice->due_on->lt(today())) {
                $invoice->due_on = null;
            }

            $invoice->issue($profile);
            session()->flash('status', match ($this->type) {
                Invoice::QUOTE => __('Quote :number issued.', ['number' => $invoice->number]),
                Invoice::CREDIT_NOTE => __('Credit note :number issued.', ['number' => $invoice->number]),
                default => __('Invoice :number issued.', ['number' => $invoice->number]),
            });
        } else {
            session()->flash('status', __('Draft saved.'));
        }

        $this->redirectRoute('business.index', ['type' => $this->type === Invoice::INVOICE ? null : $this->type, 'document' => $issue ? $invoice->id : null], navigate: true);
    }

    public function with(): array
    {
        $profile = $this->businessProfile();
        $net = 0.0;
        $vat = [];

        foreach ($this->items as $item) {
            $line = round((float) str_replace(',', '.', (string) $item['quantity']) * (float) str_replace(',', '.', (string) $item['unit_price']) * (1 - min(100, (float) str_replace(',', '.', (string) ($item['discount'] ?? ''))) / 100), 2);
            $net += $line;

            if ($profile && ! $profile->small_business) {
                $rate = (string) (float) $item['vat_rate'];
                $vat[$rate] = ($vat[$rate] ?? 0) + $line * (float) $rate / 100;
            }
        }

        $vat = array_map(fn ($value) => round($value, 2), $vat);
        krsort($vat);

        return [
            'profile' => $profile,
            'customerOptions' => $this->customers()->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('user_id', $this->currentUser()->id)->where('household_id', $this->householdId)->orderBy('name')->get(),
            'related' => $this->relatedInvoiceId ? $this->invoices()->find($this->relatedInvoiceId) : null,
            'netTotal' => round($net, 2),
            'vatTotals' => array_filter($vat),
            'grossTotal' => round($net + array_sum($vat), 2),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $typeTitle = match ($type) {
        'quote' => $invoiceId ? __('Edit quote draft') : __('New quote'),
        'credit_note' => $invoiceId ? __('Edit credit note draft') : __('New credit note'),
        default => $invoiceId ? __('Edit draft') : __('New invoice'),
    };
    $number = fn ($value) => (float) str_replace(',', '.', (string) $value);
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $typeTitle }}</h2>
            <a href="{{ route('business.index', ['type' => $type === 'invoice' ? null : $type]) }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-300">← {{ __('Back to documents') }}</a>
        </div>
    </x-slot>

    <div class="py-6">
        <form wire:submit="save" class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @if ($profile && ! $profile->isCompleteForInvoices())
                <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">
                    ⚠ {{ __('Your business details are incomplete – drafts are possible, issuing needs address and tax number.') }}
                    <a href="{{ route('business.settings') }}" wire:navigate class="font-medium underline">{{ __('Complete now') }}</a>
                </p>
            @endif

            @if ($related)
                <p class="rounded-xl bg-indigo-50 px-4 py-2.5 text-sm text-indigo-900 dark:bg-indigo-900/30 dark:text-indigo-100">
                    🔗 {{ $related->isQuote() ? __('Based on quote :number.', ['number' => $related->number]) : __('Refers to invoice :number of :date.', ['number' => $related->number, 'date' => $related->issued_on?->format('d.m.Y')]) }}
                    @if ($type === 'credit_note')
                        {{ __('Amounts are negative; remove or reduce the lines that are not credited.') }}
                    @endif
                </p>
            @endif

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-3">
                        <div>
                            <x-input-label for="invoice-customer" :value="__('Customer')" />
                            <select id="invoice-customer" wire:model.live="customerId" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                <option value="">{{ __('– without customer record –') }}</option>
                                @foreach ($customerOptions as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="invoice-recipient" :value="__('Recipient address')" />
                            <textarea id="invoice-recipient" wire:model="recipient" rows="4" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" placeholder="{{ __("Company\nStreet 1\n12345 City") }}"></textarea>
                            <x-input-error :messages="$errors->get('recipient')" class="mt-1" />
                        </div>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <x-input-label for="invoice-subject" :value="__('Subject (optional)')" />
                            <x-text-input id="invoice-subject" wire:model="subject" class="mt-1 block w-full" placeholder="{{ __('e.g. Website redesign') }}" />
                        </div>
                        <div>
                            <x-input-label for="invoice-period" :value="__('Service date / period')" />
                            <x-text-input id="invoice-period" wire:model="servicePeriod" class="mt-1 block w-full" placeholder="{{ __('e.g. September 2026 or 12.09.2026') }}" />
                            <x-input-error :messages="$errors->get('servicePeriod')" class="mt-1" />
                        </div>
                        @if ($type === 'invoice')
                            <div>
                                <x-input-label for="invoice-due" :value="__('Due date (optional)')" />
                                <x-text-input id="invoice-due" type="date" wire:model="dueOn" class="mt-1 block w-full" />
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Empty = :days days after the invoice date.', ['days' => $profile?->payment_days ?? 14]) }}</p>
                            </div>
                        @elseif ($type === 'quote')
                            <div>
                                <x-input-label for="invoice-valid" :value="__('Valid until')" />
                                <x-text-input id="invoice-valid" type="date" wire:model="validUntil" class="mt-1 block w-full" />
                            </div>
                        @endif
                    </div>
                </div>
                <div class="mt-4">
                    <x-input-label for="invoice-intro" :value="__('Introduction')" />
                    <textarea id="invoice-intro" wire:model="intro" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"></textarea>
                </div>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Items') }}</h3>
                <ol class="mt-3 space-y-3">
                    @foreach ($items as $index => $item)
                        <li wire:key="item-{{ $index }}" class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40">
                            <div class="flex items-start gap-2">
                                <span class="mt-2 w-6 shrink-0 text-center text-xs font-semibold text-gray-500">{{ $index + 1 }}</span>
                                <div class="grid min-w-0 flex-1 gap-2 sm:grid-cols-12">
                                    <div class="sm:col-span-12">
                                        <textarea wire:model.blur="items.{{ $index }}.description" rows="1" aria-label="{{ __('Description') }}" placeholder="{{ __('Description of the service or product') }}" class="block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"></textarea>
                                        <x-input-error :messages="$errors->get('items.'.$index.'.description')" class="mt-1" />
                                    </div>
                                    <label class="sm:col-span-2"><span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Quantity') }}</span>
                                        <input type="text" inputmode="decimal" wire:model.blur="items.{{ $index }}.quantity" class="mt-0.5 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                    </label>
                                    <label class="sm:col-span-2"><span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Unit') }}</span>
                                        <input type="text" wire:model.blur="items.{{ $index }}.unit" list="invoice-units" placeholder="{{ __('Hrs.') }}" class="mt-0.5 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                    </label>
                                    <label class="sm:col-span-3"><span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Unit price') }} (€)</span>
                                        <input type="text" inputmode="decimal" wire:model.blur="items.{{ $index }}.unit_price" placeholder="0,00" class="mt-0.5 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                    </label>
                                    <label class="sm:col-span-2"><span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Discount %') }}</span>
                                        <input type="text" inputmode="decimal" wire:model.blur="items.{{ $index }}.discount" placeholder="0" class="mt-0.5 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                    </label>
                                    @if ($profile && ! $profile->small_business)
                                        <label class="sm:col-span-3"><span class="text-xs text-gray-500 dark:text-gray-400">{{ __('VAT') }}</span>
                                            <select wire:model.live="items.{{ $index }}.vat_rate" class="mt-0.5 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                                <option value="19">19 %</option>
                                                <option value="7">7 %</option>
                                                <option value="0">0 %</option>
                                            </select>
                                        </label>
                                    @endif
                                    @foreach (['quantity', 'unit_price', 'discount'] as $field)
                                        <x-input-error :messages="$errors->get('items.'.$index.'.'.$field)" class="sm:col-span-12" />
                                    @endforeach
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-1">
                                    <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro(round($number($item['quantity']) * $number($item['unit_price']) * (1 - min(100, $number($item['discount'] ?? '')) / 100), 2)) }}</span>
                                    <div class="flex">
                                        <button type="button" wire:click="moveItem({{ $index }}, -1)" @disabled($index === 0) class="rounded-sm p-1 text-gray-400 hover:bg-gray-200 disabled:opacity-30 dark:hover:bg-gray-700" aria-label="{{ __('Move up') }}">↑</button>
                                        <button type="button" wire:click="moveItem({{ $index }}, 1)" @disabled($index === count($items) - 1) class="rounded-sm p-1 text-gray-400 hover:bg-gray-200 disabled:opacity-30 dark:hover:bg-gray-700" aria-label="{{ __('Move down') }}">↓</button>
                                        <button type="button" wire:click="removeItem({{ $index }})" class="rounded-sm p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-gray-700" aria-label="{{ __('Remove') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                                    </div>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>
                <datalist id="invoice-units">
                    @foreach ([__('Hrs.'), __('pcs.'), __('days'), __('flat rate'), 'km', __('months')] as $unit)
                        <option value="{{ $unit }}"></option>
                    @endforeach
                </datalist>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="addItem" class="rounded-lg px-3 py-2 text-sm text-indigo-600 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">＋ {{ __('Add item') }}</button>
                    @if ($products->isNotEmpty())
                        <select x-data x-on:change="if ($event.target.value) { $wire.addProduct(parseInt($event.target.value)); $event.target.value = '' }" aria-label="{{ __('Add from catalogue') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            <option value="">📦 {{ __('Add from catalogue …') }}</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }} – {{ $euro($product->unit_price) }}{{ $product->unit ? ' / '.$product->unit : '' }}</option>
                            @endforeach
                        </select>
                    @else
                        <a href="{{ route('business.products') }}" wire:navigate class="text-xs text-gray-500 hover:underline dark:text-gray-400">📦 {{ __('Set up a catalogue of your services') }}</a>
                    @endif
                </div>

                <dl class="ms-auto mt-5 max-w-xs space-y-1 text-sm">
                    @if ($profile && ! $profile->small_business)
                        <div class="flex justify-between text-gray-600 dark:text-gray-300"><dt>{{ __('Net') }}</dt><dd class="tabular-nums">{{ $euro($netTotal) }}</dd></div>
                        @foreach ($vatTotals as $rate => $amount)
                            <div class="flex justify-between text-gray-600 dark:text-gray-300"><dt>{{ __('VAT :rate %', ['rate' => $rate]) }}</dt><dd class="tabular-nums">{{ $euro($amount) }}</dd></div>
                        @endforeach
                    @endif
                    <div class="flex justify-between border-t border-gray-200 pt-1 text-base font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100"><dt>{{ __('Total') }}</dt><dd class="tabular-nums">{{ $euro($grossTotal) }}</dd></div>
                    @if ($profile?->small_business)
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('According to § 19 UStG no VAT is charged.') }}</p>
                    @endif
                </dl>
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <x-input-label for="invoice-closing" :value="__('Closing text')" />
                <textarea id="invoice-closing" wire:model="closing" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"></textarea>
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <x-primary-button type="button" wire:click="save(true)" wire:confirm="{{ __('Issue the document now? It gets the next number and can then no longer be changed.') }}">✓ {{ match ($type) { 'quote' => __('Issue quote'), 'credit_note' => __('Issue credit note'), default => __('Issue invoice') } }}</x-primary-button>
                <x-secondary-button type="submit">{{ __('Save draft') }}</x-secondary-button>
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Drafts have no number yet and can be changed any time.') }}</span>
            </div>
        </form>
    </div>
</div>
