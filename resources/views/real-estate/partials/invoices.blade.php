{{-- Invoices of the property (variables from real-estate/show). --}}
<div class="space-y-5">
    <form wire:submit="saveInvoice" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-4 dark:bg-gray-800">
        <h3 class="font-semibold text-gray-900 sm:col-span-2 lg:col-span-4 dark:text-gray-100">{{ $editingInvoiceId ? __('Edit invoice') : __('New invoice') }}</h3>

        <div class="flex flex-wrap items-center gap-2 rounded-xl bg-indigo-50/60 p-3 sm:col-span-2 lg:col-span-4 dark:bg-indigo-900/20">
            <label class="cursor-pointer rounded-lg bg-white px-3 py-1.5 text-sm ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:ring-gray-600">
                📎 {{ $receipt ? $receipt->getClientOriginalName() : __('Attach invoice (photo or PDF)') }}
                <input type="file" wire:model="receipt" accept="image/*,application/pdf" class="sr-only">
            </label>
            @if (\App\Support\Finance\FinanceReceiptAi::isConfigured())
                <button type="button" wire:click="readInvoiceReceipt" wire:loading.attr="disabled" wire:target="readInvoiceReceipt,receipt" @disabled(! $receipt) class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm text-white hover:bg-indigo-500 disabled:opacity-50">
                    <span wire:loading.remove wire:target="readInvoiceReceipt">✨ {{ __('Read with AI') }}</span>
                    <span wire:loading wire:target="readInvoiceReceipt">{{ __('Reading …') }}</span>
                </button>
            @endif
            <span wire:loading wire:target="receipt" class="text-xs text-gray-500">{{ __('Uploading …') }}</span>
            @if ($receiptMessage)
                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $receiptMessage }}</span>
            @endif
            <x-input-error :messages="$errors->get('receipt')" />
        </div>

        <label class="text-sm text-gray-700 lg:col-span-2 dark:text-gray-300">{{ __('Vendor') }}<input type="text" wire:model="invoice.vendor" class="{{ $input }}"><x-input-error :messages="$errors->get('invoice.vendor')" /></label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Invoice number') }}<input type="text" wire:model="invoice.number" class="{{ $input }}"></label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Budget line') }}
            <select wire:model="invoice.budget_item_id" class="{{ $input }}">
                <option value="">{{ __('– none –') }}</option>
                @foreach ($model->budgetItems as $budgetItem)
                    <option value="{{ $budgetItem->id }}">{{ $budgetItem->title }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Invoice date') }}<input type="date" wire:model="invoice.invoice_date" class="{{ $input }}"><x-input-error :messages="$errors->get('invoice.invoice_date')" /></label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Due on') }}<input type="date" wire:model="invoice.due_on" class="{{ $input }}"></label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Amount (gross, €)') }}<input type="number" step="0.01" wire:model="invoice.amount" class="{{ $input }}"><x-input-error :messages="$errors->get('invoice.amount')" /></label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Labour costs (€)') }}<input type="number" step="0.01" min="0" wire:model="invoice.labor_amount" class="{{ $input }}" title="{{ __('Labour, machine and travel costs incl. VAT – shown separately on the invoice; relevant for § 35a.') }}"><x-input-error :messages="$errors->get('invoice.labor_amount')" /></label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Paid on') }}<input type="date" wire:model="invoice.paid_on" class="{{ $input }}"></label>
        <label class="text-sm text-gray-700 lg:col-span-2 dark:text-gray-300">{{ __('Tax treatment') }}
            <select wire:model="invoice.tax_treatment" class="{{ $input }}">
                @foreach (\App\Models\RealEstateInvoice::taxTreatmentLabels() as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm text-gray-700 lg:col-span-2 dark:text-gray-300">{{ __('Notes') }}<input type="text" wire:model="invoice.notes" class="{{ $input }}"></label>
        <p class="text-xs text-gray-500 sm:col-span-2 lg:col-span-4 dark:text-gray-400">{{ __('Pay craftsman invoices by bank transfer – cash payments are not recognised for § 35a and § 35c.') }}</p>
        <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
            <x-primary-button>{{ $editingInvoiceId ? __('Save') : '+ '.__('Add invoice') }}</x-primary-button>
            @if ($editingInvoiceId)
                <x-secondary-button type="button" wire:click="cancelInvoice">{{ __('Cancel') }}</x-secondary-button>
                <button type="button" wire:click="deleteInvoice({{ $editingInvoiceId }})" wire:confirm="{{ __('Delete this invoice?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
            @endif
        </div>
    </form>

    <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800" aria-label="{{ __('Invoices') }}">
        <p class="border-b border-gray-100 px-5 py-3 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
            {{ trans_choice(':count invoice|:count invoices', $model->invoices->count()) }} · {{ __(':paid paid, :open open', ['paid' => $euro($summary['paid_total'], 2), 'open' => $euro($summary['invoiced_total'] - $summary['paid_total'], 2)]) }}
        </p>
        @if ($model->invoices->isEmpty())
            <p class="p-5 text-sm text-gray-500 dark:text-gray-400">{{ __('No invoices yet.') }}</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($model->invoices as $row)
                    <li wire:key="invoice-{{ $row->id }}" class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $row->vendor }}@if ($row->number) <span class="text-sm font-normal text-gray-500">· {{ $row->number }}</span>@endif</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $row->invoice_date->format('d.m.Y') }}
                                @if ($row->budgetItem) · {{ $row->budgetItem->title }}@endif
                                · {{ \App\Models\RealEstateInvoice::taxTreatmentLabels()[\App\Support\RealEstate\RealEstateTax::treatment($row)] }}
                                @if ($row->labor_amount > 0) · {{ __('labour :amount', ['amount' => $euro($row->labor_amount, 2)]) }}@endif
                            </p>
                            @if (isset($transactionMatches[$row->id]))
                                <button type="button" wire:click="linkTransaction({{ $row->id }}, {{ $transactionMatches[$row->id]->id }})" class="mt-1 rounded bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800 hover:bg-emerald-100 dark:bg-emerald-900/40 dark:text-emerald-200">
                                    🔗 {{ __('Paid on :date from your account (:payee)? Link booking', ['date' => $transactionMatches[$row->id]->booked_on->format('d.m.Y'), 'payee' => $transactionMatches[$row->id]->counterparty]) }}
                                </button>
                            @elseif ($row->transaction)
                                <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">🔗 {{ __('Linked to bank booking of :date', ['date' => $row->transaction->booked_on->format('d.m.Y')]) }}</p>
                            @endif
                        </div>
                        <div class="text-end">
                            <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $euro($row->amount, 2) }}</p>
                            @if ($row->isPaid())
                                <button type="button" wire:click="markInvoicePaid({{ $row->id }})" class="text-xs text-green-700 dark:text-green-400">✓ {{ __('paid :date', ['date' => $row->paid_on->format('d.m.Y')]) }}</button>
                            @else
                                <button type="button" wire:click="markInvoicePaid({{ $row->id }})" @class(['text-xs underline', 'text-red-700 dark:text-red-400' => $row->isOverdue(), 'text-indigo-600 dark:text-indigo-400' => ! $row->isOverdue()])>{{ $row->isOverdue() ? '⚠ '.__('overdue since :date', ['date' => $row->due_on->format('d.m.Y')]) : __('Mark as paid') }}</button>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($row->receipt_path)
                                <a href="{{ route('real-estate.receipt', $row) }}" target="_blank" class="text-gray-400 hover:text-indigo-600" title="{{ __('Open receipt') }}" aria-label="{{ __('Open receipt') }}">📎</a>
                            @endif
                            <button type="button" wire:click="editInvoice({{ $row->id }})" class="text-gray-400 hover:text-indigo-600" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">✎</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
