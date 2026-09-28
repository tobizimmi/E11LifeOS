<?php

use App\Livewire\Concerns\InteractsWithBusiness;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\BookkeepingEntry;
use App\Support\AiException;
use App\Support\Business\BookkeepingCategories;
use App\Support\Business\ReceiptAi;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithBusiness;
    use InteractsWithHousehold;
    use WithFileUploads;

    #[Url(except: 0)]
    public int $year = 0;

    #[Url(except: '')]
    public string $kind = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array<string, mixed>
     */
    public array $form = [];

    public $receipt = null;

    public bool $removeReceipt = false;

    public ?string $aiMessage = null;

    public function mount(): void
    {
        $this->year = $this->year >= 2000 && $this->year <= 2100 ? $this->year : now()->year;
    }

    private function emptyForm(string $kind = BookkeepingEntry::EXPENSE): array
    {
        return ['kind' => $kind, 'booked_on' => today()->toDateString(), 'description' => '', 'counterparty' => '', 'category' => $kind === BookkeepingEntry::INCOME ? 'sales' : 'other_expense', 'amount' => '', 'vat_amount' => ''];
    }

    public function newEntry(string $kind = BookkeepingEntry::EXPENSE): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'receipt', 'removeReceipt', 'aiMessage');
        $this->form = $this->emptyForm($kind === BookkeepingEntry::INCOME ? $kind : BookkeepingEntry::EXPENSE);
        $this->showForm = true;
    }

    public function editEntry(int $entryId): void
    {
        $entry = $this->bookkeepingEntries()->find($entryId) ?? abort(404);
        $this->resetValidation();
        $this->reset('receipt', 'removeReceipt', 'aiMessage');
        $this->editingId = $entry->id;
        $this->form = [
            'kind' => $entry->kind,
            'booked_on' => $entry->booked_on->toDateString(),
            'description' => $entry->description,
            'counterparty' => (string) $entry->counterparty,
            'category' => $entry->category,
            'amount' => number_format($entry->amount, 2, ',', ''),
            'vat_amount' => $entry->vat_amount > 0 ? number_format($entry->vat_amount, 2, ',', '') : '',
        ];
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function updatedFormKind(string $kind): void
    {
        if (! BookkeepingCategories::exists((string) $this->form['category'], $kind)) {
            $this->form['category'] = $kind === BookkeepingEntry::INCOME ? 'sales' : 'other_expense';
        }
    }

    /**
     * Fills in the VAT contained in the gross amount for the given rate.
     */
    public function applyVatRate(int $rate): void
    {
        $gross = (float) str_replace(',', '.', (string) $this->form['amount']);
        $this->form['vat_amount'] = $gross > 0 ? number_format(round($gross - $gross / (1 + $rate / 100), 2), 2, ',', '') : '';
    }

    public function readReceipt(ReceiptAi $ai): void
    {
        $this->aiMessage = null;

        if (! $this->receipt || ! in_array($this->receipt->getMimeType(), ReceiptAi::MEDIA_TYPES, true)) {
            $this->aiMessage = __('Add a photo or PDF of the receipt first.');

            return;
        }

        try {
            $result = $ai->read((string) file_get_contents($this->receipt->getRealPath()), $this->receipt->getMimeType(), $this->form['kind']);
        } catch (AiException $exception) {
            $this->aiMessage = $exception->getMessage();

            return;
        }

        $filled = 0;

        foreach (['booked_on', 'counterparty', 'description', 'category'] as $field) {
            if (filled($result[$field])) {
                $this->form[$field] = $result[$field];
                $filled++;
            }
        }

        foreach (['amount', 'vat_amount'] as $field) {
            if ($result[$field] !== null) {
                $this->form[$field] = number_format($result[$field], 2, ',', '');
                $filled++;
            }
        }

        $this->aiMessage = $filled > 0 ? __('Filled in from the receipt – please check.') : __('Nothing could be read from the receipt.');
    }

    public function saveEntry(): void
    {
        $profile = $this->businessProfile();
        $this->form['amount'] = str_replace(',', '.', trim((string) $this->form['amount']));
        $this->form['vat_amount'] = str_replace(',', '.', trim((string) $this->form['vat_amount']));

        $data = $this->validate([
            'form.kind' => ['required', Rule::in([BookkeepingEntry::EXPENSE, BookkeepingEntry::INCOME])],
            'form.booked_on' => ['required', 'date'],
            'form.description' => ['required', 'string', 'max:255'],
            'form.counterparty' => ['nullable', 'string', 'max:255'],
            'form.category' => ['required', 'string', fn ($attribute, $value, $fail) => BookkeepingCategories::exists((string) $value, (string) $this->form['kind']) ? null : $fail(__('Please choose a category.'))],
            'form.amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'form.vat_amount' => ['nullable', 'numeric', 'min:0', 'lt:form.amount'],
            'receipt' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,heic'],
        ], attributes: [
            'form.description' => __('Description'),
            'form.amount' => __('Amount (gross, €)'),
            'form.vat_amount' => __('VAT'),
            'receipt' => __('Receipt'),
        ])['form'];

        $data['vat_amount'] = ($profile?->small_business ?? true) ? 0 : (float) ($data['vat_amount'] ?: 0);
        $data['counterparty'] = $data['counterparty'] ?: null;

        $entry = $this->editingId
            ? ($this->bookkeepingEntries()->find($this->editingId) ?? abort(404))
            : new BookkeepingEntry(['user_id' => $this->currentUser()->id, 'household_id' => $this->householdId]);

        if (($this->removeReceipt || $this->receipt) && $entry->receipt_path) {
            Storage::disk(BookkeepingEntry::RECEIPT_DISK)->delete($entry->receipt_path);
            $data += ['receipt_path' => null, 'receipt_name' => null, 'receipt_mime' => null];
        }

        if ($this->receipt) {
            $extension = strtolower($this->receipt->guessExtension() ?: $this->receipt->getClientOriginalExtension());
            $data['receipt_path'] = $this->receipt->storeAs('business-receipts/'.$this->currentUser()->id, Str::uuid().'.'.$extension, BookkeepingEntry::RECEIPT_DISK);
            $data['receipt_name'] = mb_substr($this->receipt->getClientOriginalName(), 0, 255);
            $data['receipt_mime'] = $this->receipt->getMimeType();
        }

        $entry->fill($data)->save();
        $this->showForm = false;
        $this->year = $entry->booked_on->year;
        $this->reset('receipt', 'removeReceipt');
    }

    public function deleteEntry(int $entryId): void
    {
        $entry = $this->bookkeepingEntries()->find($entryId) ?? abort(404);

        if ($entry->receipt_path) {
            Storage::disk(BookkeepingEntry::RECEIPT_DISK)->delete($entry->receipt_path);
        }

        $entry->delete();
        $this->showForm = false;
    }

    public function with(): array
    {
        $term = mb_strtolower(trim($this->search));
        $entries = $this->bookkeepingEntries()
            ->whereYear('booked_on', $this->year)
            ->when(in_array($this->kind, [BookkeepingEntry::EXPENSE, BookkeepingEntry::INCOME], true), fn ($query) => $query->where('kind', $this->kind))
            ->when($this->kind === 'missing', fn ($query) => $query->where('kind', BookkeepingEntry::EXPENSE)->whereNull('receipt_path'))
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->whereRaw('LOWER(description) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(counterparty) LIKE ?', ['%'.$term.'%'])))
            ->orderByDesc('booked_on')->orderByDesc('id')->get();

        $profile = $this->businessProfile();

        return [
            'profile' => $profile,
            'smallBusiness' => $profile?->small_business ?? true,
            'entries' => $entries,
            'expenseSum' => round((float) $entries->where('kind', BookkeepingEntry::EXPENSE)->sum('amount'), 2),
            'incomeSum' => round((float) $entries->where('kind', BookkeepingEntry::INCOME)->sum('amount'), 2),
            'years' => $this->bookkeepingEntries()->pluck('booked_on')->map->year->push(now()->year, $this->year)->unique()->sortDesc()->values(),
            'categoryOptions' => BookkeepingCategories::forKind($this->form['kind'] ?? BookkeepingEntry::EXPENSE),
            'aiAvailable' => ReceiptAi::isConfigured(),
            'editingEntry' => $this->editingId ? $this->bookkeepingEntries()->find($this->editingId) : null,
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">💼 {{ $profile?->company_name ?: __('Small business') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your business data.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('business.partials.nav', ['active' => 'business.bookings'])

            <div class="flex flex-wrap items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search receipts …') }}" aria-label="{{ __('Search') }}" class="w-full rounded-lg sm:w-auto sm:min-w-0 sm:flex-1 border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                <select wire:model.live="kind" aria-label="{{ __('Type') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    <option value="">{{ __('Income & expenses') }}</option>
                    <option value="expense">{{ __('Expenses') }}</option>
                    <option value="income">{{ __('Income') }}</option>
                    <option value="missing">{{ __('Expenses without receipt') }}</option>
                </select>
                <select wire:model.live="year" aria-label="{{ __('Year') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    @foreach ($years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
                <x-primary-button type="button" wire:click="newEntry('expense')">＋ {{ __('Expense') }}</x-primary-button>
                <x-secondary-button type="button" wire:click="newEntry('income')">＋ {{ __('Income') }}</x-secondary-button>
            </div>

            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Income from paid invoices is counted automatically – enter only income without an invoice here (e.g. cash sales).') }}</p>

            @if ($entries->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">📥</p>
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('No receipts in :year yet. Add expenses such as material, software or travel costs – with a photo of the receipt.', ['year' => $year]) }}</p>
                </div>
            @else
                <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                    <p class="flex flex-wrap gap-x-4 border-b border-gray-100 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <span>{{ trans_choice(':count receipt|:count receipts', $entries->count()) }}</span>
                        <span>{{ __('Expenses') }}: <strong class="text-gray-800 dark:text-gray-200">{{ $euro($expenseSum) }}</strong></span>
                        @if ($incomeSum > 0)
                            <span>{{ __('Income') }}: <strong class="text-gray-800 dark:text-gray-200">{{ $euro($incomeSum) }}</strong></span>
                        @endif
                    </p>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($entries as $entry)
                            <li wire:key="entry-{{ $entry->id }}">
                                <button type="button" wire:click="editEntry({{ $entry->id }})" class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <span class="text-xl" aria-hidden="true">{{ \App\Support\Business\BookkeepingCategories::icon($entry->category) }}</span>
                                    <span class="w-14 shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $entry->booked_on->format('d.m.') }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $entry->description }}</span>
                                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ collect([$entry->counterparty, \App\Support\Business\BookkeepingCategories::label($entry->category)])->filter()->implode(' · ') }}</span>
                                    </span>
                                    @if ($entry->isExpense() && ! $entry->receipt_path)
                                        <span class="hidden shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 sm:inline">{{ __('no receipt') }}</span>
                                    @elseif ($entry->receipt_path)
                                        <span class="shrink-0 text-sm" title="{{ __('Receipt attached') }}" aria-label="{{ __('Receipt attached') }}">📎</span>
                                    @endif
                                    <span @class(['w-24 shrink-0 text-end text-sm font-semibold tabular-nums', 'text-gray-900 dark:text-gray-100' => $entry->isExpense(), 'text-emerald-700 dark:text-emerald-400' => ! $entry->isExpense()])>{{ $entry->isExpense() ? '−' : '+' }}{{ $euro($entry->amount) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>

    @if ($showForm)
        <x-sheet close="closeForm" :label="$editingId ? __('Edit receipt') : __('New receipt')" wire:key="entry-form-{{ $editingId ?? 'new' }}">
            <x-slot:header><strong class="text-gray-800 dark:text-gray-100">📥 {{ $editingId ? __('Edit receipt') : __('New receipt') }}</strong></x-slot:header>

            <x-drop-zone model="receipt" :label="__('Drop the receipt here')">
            <form id="entry-form" wire:submit="saveEntry" class="space-y-4">
                <div class="inline-flex rounded-lg bg-gray-100 p-1 dark:bg-gray-900" role="radiogroup" aria-label="{{ __('Type') }}">
                    @foreach (['expense' => __('Expense'), 'income' => __('Income')] as $value => $label)
                        <label @class(['cursor-pointer rounded-md px-3 py-1.5 text-sm', 'bg-white shadow-sm text-gray-900 dark:bg-gray-700 dark:text-gray-100' => $form['kind'] === $value, 'text-gray-600 dark:text-gray-400' => $form['kind'] !== $value])>
                            <input type="radio" class="sr-only" wire:model.live="form.kind" value="{{ $value }}">{{ $label }}
                        </label>
                    @endforeach
                </div>

                <div class="rounded-xl border border-dashed border-gray-300 p-3 dark:border-gray-600">
                    <x-input-label for="entry-receipt" :value="__('Receipt (photo or PDF)')" />
                    @if ($editingEntry?->receipt_path && ! $removeReceipt && ! $receipt)
                        <p class="mt-1 flex items-center gap-2 text-sm">
                            <a href="{{ route('business.receipts.show', $editingEntry) }}" target="_blank" class="truncate text-indigo-600 hover:underline dark:text-indigo-300">📎 {{ $editingEntry->receipt_name }}</a>
                            <button type="button" wire:click="$set('removeReceipt', true)" class="text-xs text-red-600 hover:underline dark:text-red-400">{{ __('Remove') }}</button>
                        </p>
                    @endif
                    <input id="entry-receipt" type="file" wire:model="receipt" accept="image/*,application/pdf" capture="environment"
                           class="mt-1 block w-full text-sm text-gray-700 file:me-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-3 file:py-2 file:text-indigo-700 dark:text-gray-300 dark:file:bg-gray-700 dark:file:text-gray-200">
                    <div wire:loading wire:target="receipt" class="mt-1 text-xs text-gray-500">{{ __('Uploading …') }}</div>
                    <x-input-error :messages="$errors->get('receipt')" class="mt-1" />
                    @if ($aiAvailable)
                        <button type="button" wire:click="readReceipt" wire:loading.attr="disabled" wire:target="readReceipt,receipt" class="mt-2 rounded-lg px-3 py-1.5 text-sm text-indigo-600 ring-1 ring-indigo-200 hover:bg-indigo-50 disabled:opacity-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">
                            <span wire:loading.remove wire:target="readReceipt">✨ {{ __('Read receipt with AI') }}</span>
                            <span wire:loading wire:target="readReceipt">{{ __('Reading …') }}</span>
                        </button>
                    @endif
                    @if ($aiMessage)
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-300" role="status">{{ $aiMessage }}</p>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-input-label for="entry-description" :value="__('Description')" />
                        <x-text-input id="entry-description" wire:model="form.description" class="mt-1 block w-full" placeholder="{{ $form['kind'] === 'income' ? __('e.g. Sales at the Christmas market') : __('e.g. Printer paper and toner') }}" />
                        <x-input-error :messages="$errors->get('form.description')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="entry-date" :value="__('Date (paid on)')" />
                        <x-text-input id="entry-date" type="date" wire:model="form.booked_on" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('form.booked_on')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="entry-counterparty" :value="$form['kind'] === 'income' ? __('Customer / source') : __('Vendor')" />
                        <x-text-input id="entry-counterparty" wire:model="form.counterparty" class="mt-1 block w-full" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="entry-category" :value="__('Category')" />
                        <select id="entry-category" wire:model.live="form.category" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            @foreach ($categoryOptions as $key => $category)
                                <option value="{{ $key }}">{{ $category['icon'] }} {{ $category['label'] }}</option>
                            @endforeach
                        </select>
                        @if (! empty($categoryOptions[$form['category']]['hint']))
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">💡 {{ $categoryOptions[$form['category']]['hint'] }}</p>
                        @endif
                        <x-input-error :messages="$errors->get('form.category')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="entry-amount" :value="__('Amount (gross, €)')" />
                        <x-text-input id="entry-amount" wire:model="form.amount" inputmode="decimal" class="mt-1 block w-full" placeholder="0,00" />
                        <x-input-error :messages="$errors->get('form.amount')" class="mt-1" />
                    </div>
                    @if (! $smallBusiness)
                        <div>
                            <x-input-label for="entry-vat" :value="__('Included VAT (€)')" />
                            <div class="mt-1 flex gap-1">
                                <x-text-input id="entry-vat" wire:model="form.vat_amount" inputmode="decimal" class="block w-full" placeholder="0,00" />
                                <button type="button" wire:click="applyVatRate(19)" class="rounded-lg px-2 text-xs text-gray-600 ring-1 ring-gray-200 hover:bg-gray-100 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700">19 %</button>
                                <button type="button" wire:click="applyVatRate(7)" class="rounded-lg px-2 text-xs text-gray-600 ring-1 ring-gray-200 hover:bg-gray-100 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700">7 %</button>
                            </div>
                            <x-input-error :messages="$errors->get('form.vat_amount')" class="mt-1" />
                        </div>
                    @else
                        <p class="self-end text-xs text-gray-500 dark:text-gray-400">{{ __('As a small business you book the gross amount – VAT cannot be reclaimed.') }}</p>
                    @endif
                </div>
            </form>
            </x-drop-zone>

            @if ($editingId)
                <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-700">
                    <livewire:documents-panel type="bookkeeping_entry" :id="$editingId" :wire:key="'documents-booking-'.$editingId" />
                </div>
            @endif

            <x-slot:footer>
                <x-primary-button form="entry-form" wire:loading.attr="disabled" wire:target="receipt,saveEntry">{{ __('Save') }}</x-primary-button>
                @if ($editingId)
                    <button type="button" wire:click="deleteEntry({{ $editingId }})" wire:confirm="{{ __('Delete this receipt?') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                @endif
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
