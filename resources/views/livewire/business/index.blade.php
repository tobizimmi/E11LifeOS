<?php

use App\Livewire\Concerns\InteractsWithBusiness;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Support\Business\EuerReport;
use App\Support\Finance\BankCsvImporter;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithBusiness;
    use InteractsWithHousehold;

    #[Url(except: Invoice::INVOICE)]
    public string $type = Invoice::INVOICE;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: 0)]
    public int $year = 0;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'document', except: 0)]
    public int $openDocumentId = 0;

    /**
     * @var array{paid_on: string, amount: string, method: string, note: string}
     */
    public array $payment = ['paid_on' => '', 'amount' => '', 'method' => 'transfer', 'note' => ''];

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->year = $this->year >= 2000 && $this->year <= 2100 ? $this->year : 0;
        $this->type = in_array($this->type, Invoice::TYPES, true) ? $this->type : Invoice::INVOICE;

        if ($this->openDocumentId) {
            $this->openDocument($this->openDocumentId);
        }
    }

    private function findInvoice(int $invoiceId): Invoice
    {
        return $this->invoices()->find($invoiceId) ?? abort(404);
    }

    public function updatedType(): void
    {
        $this->reset('status');
    }

    public function openDocument(int $invoiceId): void
    {
        $invoice = $this->findInvoice($invoiceId);
        $this->openDocumentId = $invoice->id;
        $this->resetValidation();
        $this->payment = ['paid_on' => today()->toDateString(), 'amount' => number_format($invoice->outstanding(), 2, ',', ''), 'method' => 'transfer', 'note' => ''];
    }

    public function closeDocument(): void
    {
        $this->openDocumentId = 0;
    }

    // ── Payments ─────────────────────────────────────────────────────────

    public function addPayment(): void
    {
        $invoice = $this->findInvoice($this->openDocumentId);
        abort_unless($invoice->isPayable(), 422);

        $amount = BankCsvImporter::parseAmount((string) $this->payment['amount']);
        $this->payment['amount'] = $amount === null ? '' : (string) $amount;
        $this->validate([
            'payment.paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment.amount' => ['required', 'numeric', 'not_in:0', 'min:-9999999', 'max:9999999'],
            'payment.method' => ['required', Rule::in(InvoicePayment::METHODS)],
            'payment.note' => ['nullable', 'string', 'max:255'],
        ], attributes: ['payment.amount' => __('Amount'), 'payment.paid_on' => __('Paid on')]);

        $invoice->recordPayment(Carbon::parse($this->payment['paid_on']), (float) $this->payment['amount'], $this->payment['method'], trim($this->payment['note']) ?: null);
        $this->openDocument($invoice->id);
        $this->dispatch('toast', message: $invoice->fresh()->status === Invoice::PAID ? __('Fully paid – thank you!') : __('Payment recorded.'));
    }

    public function deletePayment(int $paymentId): void
    {
        $invoice = $this->findInvoice($this->openDocumentId);
        ($invoice->payments()->find($paymentId) ?? abort(404))->delete();
        $invoice->refreshPaymentStatus();
        $this->openDocument($invoice->id);
    }

    // ── Document actions ─────────────────────────────────────────────────

    /**
     * Cancels an unpaid invoice with a cancellation invoice (Stornorechnung): both keep their
     * numbers and stay in the list.
     */
    public function cancel(int $invoiceId): void
    {
        $invoice = $this->findInvoice($invoiceId);
        abort_unless($invoice->type === Invoice::INVOICE && $invoice->status === Invoice::OPEN, 422);

        if (abs($invoice->paid_total) > 0.004) {
            $this->addError('cancel', __('This invoice already has payments – create a credit note instead.'));

            return;
        }

        $storno = $invoice->copyAs(Invoice::CREDIT_NOTE, negate: true);
        $storno->update(['subject' => __('Cancellation of invoice :number', ['number' => $invoice->number])]);
        $storno->issue($this->businessProfile() ?? abort(404));
        $storno->update(['status' => Invoice::CANCELLED]);
        $invoice->update(['status' => Invoice::CANCELLED]);

        $this->dispatch('toast', message: __('Invoice cancelled with :number.', ['number' => $storno->number]));
        $this->openDocument($invoice->id);
    }

    /**
     * Credit note for (a part of) an issued invoice, as draft to adjust.
     */
    public function createCreditNote(int $invoiceId): void
    {
        $invoice = $this->findInvoice($invoiceId);
        abort_unless($invoice->type === Invoice::INVOICE && in_array($invoice->status, [Invoice::OPEN, Invoice::PAID], true), 422);

        $credit = $invoice->copyAs(Invoice::CREDIT_NOTE, negate: true);
        $credit->update(['subject' => __('Credit note for invoice :number', ['number' => $invoice->number])]);
        $this->redirectRoute('business.invoices.edit', ['invoice' => $credit->id], navigate: true);
    }

    public function convertQuote(int $invoiceId): void
    {
        $quote = $this->findInvoice($invoiceId);
        abort_unless($quote->isQuote() && in_array($quote->status, [Invoice::OPEN, Invoice::ACCEPTED], true), 422);

        $invoice = $quote->copyAs(Invoice::INVOICE);
        $invoice->update([
            'small_business' => $this->businessProfile()?->small_business ?? $quote->small_business,
            'intro' => __('Thank you for your order. I hereby invoice the following services:'),
        ]);
        $invoice->recalculate();
        $quote->update(['status' => Invoice::INVOICED]);

        $this->redirectRoute('business.invoices.edit', ['invoice' => $invoice->id], navigate: true);
    }

    public function setQuoteStatus(int $invoiceId, string $status): void
    {
        $quote = $this->findInvoice($invoiceId);
        abort_unless($quote->isQuote() && ! $quote->isDraft() && in_array($status, [Invoice::OPEN, Invoice::ACCEPTED, Invoice::DECLINED], true), 422);
        $quote->update(['status' => $status]);
    }

    public function duplicate(int $invoiceId): void
    {
        $source = $this->findInvoice($invoiceId);
        $copy = $source->copyAs($source->type);
        $copy->update(['related_invoice_id' => $source->related_invoice_id, 'small_business' => $this->businessProfile()?->small_business ?? $source->small_business]);
        $copy->recalculate();

        $this->redirectRoute('business.invoices.edit', ['invoice' => $copy->id], navigate: true);
    }

    public function deleteDraft(int $invoiceId): void
    {
        $invoice = $this->findInvoice($invoiceId);
        abort_unless($invoice->isDraft(), 422);
        $invoice->delete();
        $this->openDocumentId = 0;
    }

    public function with(): array
    {
        $profile = $this->businessProfile();
        $kpiYear = $this->year ?: now()->year;
        $term = mb_strtolower(trim($this->search));

        $documents = $this->invoices()->where('type', $this->type)
            ->when($this->year, fn ($query) => $query->where(fn ($query) => $query->whereYear('issued_on', $this->year)->orWhere(fn ($query) => $query->whereNull('issued_on')->whereYear('created_at', $this->year))))
            ->when($this->status === 'overdue', fn ($query) => $query->where('status', Invoice::OPEN)->whereDate('due_on', '<', today()))
            ->when($this->status === 'partial', fn ($query) => $query->where('status', Invoice::OPEN)->where('paid_total', '!=', 0))
            ->when(! in_array($this->status, ['', 'overdue', 'partial'], true), fn ($query) => $query->where('status', $this->status))
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->whereRaw('LOWER(number) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(recipient) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(subject) LIKE ?', ['%'.$term.'%'])))
            ->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")
            ->orderByDesc('issued_on')->orderByDesc('id')
            ->get();

        $open = $this->invoices()->where('type', Invoice::INVOICE)->where('status', Invoice::OPEN)->get();
        $report = $profile ? EuerReport::build($this->currentUser(), (int) $this->householdId, $kpiYear, $profile) : null;
        $openDocument = $this->openDocumentId ? $this->invoices()->with(['payments', 'related', 'derived'])->find($this->openDocumentId) : null;

        return [
            'profile' => $profile,
            'documents' => $documents,
            'kpiYear' => $kpiYear,
            'report' => $report,
            'openTotal' => round((float) $open->sum(fn (Invoice $invoice) => $invoice->outstanding()), 2),
            'openCount' => $open->count(),
            'overdueCount' => $open->filter->isOverdue()->count(),
            'typeCounts' => $this->invoices()->selectRaw('type, count(*) as aggregate')->groupBy('type')->pluck('aggregate', 'type')->all(),
            'openQuotes' => $this->invoices()->where('type', Invoice::QUOTE)->where('status', Invoice::OPEN)->count(),
            'openDocument' => $openDocument,
            'years' => $this->invoices()->whereNotNull('issued_on')->pluck('issued_on')->map->year->push(now()->year)->unique()->sortDesc()->values(),
        ];
    }
}; ?>

@php
    $euro = fn ($value, $decimals = 2) => number_format((float) $value, $decimals, ',', '.').' €';
    $statusLabels = [
        'draft' => __('Draft'), 'open' => $type === 'quote' ? __('Sent') : __('Open'), 'partial' => __('Partially paid'), 'overdue' => __('Overdue'),
        'paid' => $type === 'credit_note' ? __('Refunded') : __('Paid'), 'cancelled' => __('Cancelled'), 'expired' => __('Expired'),
        'accepted' => __('Accepted'), 'declined' => __('Declined'), 'invoiced' => __('Invoiced'),
    ];
    $statusFilters = match ($type) {
        'quote' => ['draft', 'open', 'accepted', 'declined', 'invoiced'],
        'credit_note' => ['draft', 'open', 'paid', 'cancelled'],
        default => ['draft', 'open', 'partial', 'overdue', 'paid', 'cancelled'],
    };
    $statusClasses = [
        'draft' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        'open' => 'bg-sky-50 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        'partial' => 'bg-amber-50 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
        'overdue' => 'bg-red-50 text-red-800 dark:bg-red-900/40 dark:text-red-200',
        'expired' => 'bg-red-50 text-red-800 dark:bg-red-900/40 dark:text-red-200',
        'paid' => 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        'accepted' => 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        'invoiced' => 'bg-indigo-50 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200',
        'declined' => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
        'cancelled' => 'bg-gray-100 text-gray-500 line-through dark:bg-gray-700 dark:text-gray-400',
    ];
    $statusIcons = ['draft' => '✎', 'open' => '⏳', 'partial' => '◐', 'overdue' => '⚠', 'expired' => '⌛', 'paid' => '✓', 'accepted' => '✓', 'invoiced' => '🧾', 'declined' => '✕', 'cancelled' => '✕'];
    $typeLabels = ['invoice' => __('Invoice'), 'quote' => __('Sales quote'), 'credit_note' => __('Credit note')];
    $methodLabels = ['transfer' => __('Bank transfer'), 'cash' => __('Cash'), 'card' => __('Card'), 'paypal' => 'PayPal', 'other' => __('Other')];
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
            @include('business.partials.nav', ['active' => 'business.index'])

            @if (session('status'))
                <p class="rounded-xl bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-100" role="status">✓ {{ session('status') }}</p>
            @endif

            @if (! $profile)
                <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">💼</p>
                    <h3 class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Invoices and bookkeeping for your small business') }}</h3>
                    <p class="mx-auto mt-1 max-w-lg text-sm text-gray-600 dark:text-gray-400">{{ __('Write invoices with consecutive numbers as PDF, collect receipts and get the annual profit calculation (EÜR) for your tax return at the end of the year.') }}</p>
                    <a href="{{ route('business.settings') }}" wire:navigate class="mt-4 inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800">{{ __('Enter business details') }}</a>
                </div>
            @else
                {{-- Key figures of the year --}}
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @foreach ([
                        [__('Revenue :year', ['year' => $kpiYear]), $euro($report['revenue'], 0), __('payments received')],
                        [__('Outstanding'), $euro($openTotal, 0), trans_choice(':count open invoice|:count open invoices', $openCount).($overdueCount ? ' · '.trans_choice(':count overdue|:count overdue', $overdueCount) : '')],
                        [__('Expenses :year', ['year' => $kpiYear]), $euro($report['expenseTotal'], 0), __('deductible business expenses')],
                        [__('Profit :year', ['year' => $kpiYear]), $euro($report['profit'], 0), __('income minus expenses (EÜR)')],
                    ] as [$label, $value, $hint])
                        <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                        </div>
                    @endforeach
                </div>

                @if ($report['limitStatus'] === 'next_year' || $report['limitStatus'] === 'exceeded')
                    <p class="rounded-xl bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">
                        ⚠ {{ $report['limitStatus'] === 'exceeded'
                            ? __('Your revenue is above 100,000 € – the small business rule no longer applies from the invoice that crossed the limit. Please talk to your tax advisor.')
                            : __('Your revenue is above 25,000 € this year – from next year on you will probably have to charge VAT.') }}
                    </p>
                @endif

                {{-- Document types --}}
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 pb-2 dark:border-gray-700" role="tablist">
                    @foreach (['invoice' => '🧾 '.__('Invoices'), 'quote' => '📝 '.__('Quotes'), 'credit_note' => '↩ '.__('Credit notes')] as $key => $label)
                        <button type="button" role="tab" aria-selected="{{ $type === $key ? 'true' : 'false' }}" wire:click="$set('type', '{{ $key }}')" @class(['rounded-md px-3 py-1.5 text-sm', 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' => $type === $key, 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $type !== $key])>
                            {{ $label }} <span class="text-xs opacity-70">{{ $typeCounts[$key] ?? 0 }}</span>
                        </button>
                    @endforeach
                    @if ($openQuotes > 0 && $type !== 'quote')
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count quote awaiting an answer|:count quotes awaiting an answer', $openQuotes) }}</span>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search number, customer, subject …') }}" aria-label="{{ __('Search') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 sm:w-auto sm:min-w-0 sm:flex-1">
                    <select wire:model.live="status" aria-label="{{ __('Status') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach ($statusFilters as $key)
                            <option value="{{ $key }}">{{ $statusLabels[$key] }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="year" aria-label="{{ __('Year') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        <option value="0">{{ __('All years') }}</option>
                        @foreach ($years as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                    <a href="{{ route('business.invoices.create', ['type' => $type]) }}" wire:navigate class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800 dark:hover:bg-white">＋ {{ match ($type) { 'quote' => __('New quote'), 'credit_note' => __('New credit note'), default => __('New invoice') } }}</a>
                </div>

                @if ($documents->isEmpty())
                    <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                        <p class="text-4xl" aria-hidden="true">{{ match ($type) { 'quote' => '📝', 'credit_note' => '↩', default => '🧾' } }}</p>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            @if ($search !== '' || $status !== '')
                                {{ __('No documents found.') }}
                            @elseif ($type === 'quote')
                                {{ __('No quotes yet – an accepted quote becomes an invoice with one click.') }}
                            @elseif ($type === 'credit_note')
                                {{ __('No credit notes yet – create them from an invoice (cancel or credit).') }}
                            @else
                                {{ __('No invoices yet – create your first one.') }}
                            @endif
                        </p>
                    </div>
                @else
                    <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white shadow-sm dark:divide-gray-700 dark:bg-gray-800">
                        @foreach ($documents as $document)
                            @php
                                $displayStatus = $document->displayStatus();
                            @endphp
                            <li wire:key="document-{{ $document->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <button type="button" wire:click="openDocument({{ $document->id }})" class="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-2 text-start">
                                    <span class="w-28 shrink-0">
                                        <span class="block text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $document->number ?? '—' }}</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $document->issued_on?->format('d.m.Y') ?? __('not issued') }}</span>
                                    </span>
                                    <span class="min-w-[10rem] flex-1">
                                        <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ strtok($document->recipient, "\n") }}</span>
                                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                            {{ $document->subject ?: $document->service_period }}
                                            @if ($document->type === 'invoice' && $document->status === 'open' && $document->due_on) · {{ __('due :date', ['date' => $document->due_on->format('d.m.Y')]) }} @endif
                                            @if ($document->isQuote() && $document->status === 'open' && $document->valid_until) · {{ __('valid until :date', ['date' => $document->valid_until->format('d.m.Y')]) }} @endif
                                            @if ($displayStatus === 'partial') · {{ __(':amount open', ['amount' => $euro($document->outstanding())]) }} @endif
                                        </span>
                                    </span>
                                    <span class="inline-flex w-28 shrink-0 items-center justify-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses[$displayStatus] ?? $statusClasses['open'] }}"><span aria-hidden="true">{{ $statusIcons[$displayStatus] ?? '•' }}</span>{{ $statusLabels[$displayStatus] ?? $displayStatus }}</span>
                                    <span class="ms-auto w-24 shrink-0 text-end text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100 sm:ms-0">{{ $euro($document->gross_total) }}</span>
                                </button>
                                <a href="{{ route('business.invoices.pdf', $document) }}" target="_blank" class="shrink-0 rounded-lg px-2 py-1 text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">📄 PDF</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>
    </div>

    {{-- Document details: payments, related documents, actions --}}
    @if ($openDocument)
        @php
            $displayStatus = $openDocument->displayStatus();
        @endphp
        <x-sheet close="closeDocument" :label="$typeLabels[$openDocument->type].' '.$openDocument->number" wire:key="document-sheet-{{ $openDocument->id }}">
            <x-slot:header>
                <strong class="text-gray-800 dark:text-gray-100">{{ $typeLabels[$openDocument->type] }} {{ $openDocument->number ?? '('.__('Draft').')' }}</strong>
                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses[$displayStatus] ?? $statusClasses['open'] }}">{{ $statusLabels[$displayStatus] ?? $displayStatus }}</span>
            </x-slot:header>

            <div class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Recipient') }}</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-gray-900 dark:text-gray-100">{{ $openDocument->recipient }}</p>
                    </div>
                    <dl class="space-y-1 text-sm">
                        @if ($openDocument->subject)
                            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('Subject') }}</dt><dd class="text-end text-gray-900 dark:text-gray-100">{{ $openDocument->subject }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('Date') }}</dt><dd class="text-gray-900 dark:text-gray-100">{{ $openDocument->issued_on?->format('d.m.Y') ?? '—' }}</dd></div>
                        @if ($openDocument->due_on)
                            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('Due date') }}</dt><dd class="text-gray-900 dark:text-gray-100">{{ $openDocument->due_on->format('d.m.Y') }}</dd></div>
                        @endif
                        @if ($openDocument->valid_until)
                            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('Valid until') }}</dt><dd class="text-gray-900 dark:text-gray-100">{{ $openDocument->valid_until->format('d.m.Y') }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-3 border-t border-gray-100 pt-1 font-semibold dark:border-gray-700"><dt class="text-gray-900 dark:text-gray-100">{{ __('Total') }}</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($openDocument->gross_total) }}</dd></div>
                        @if (! $openDocument->isQuote() && ! $openDocument->isDraft())
                            <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ $openDocument->isCreditNote() ? __('Refunded') : __('Paid') }}</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($openDocument->paid_total) }}</dd></div>
                            @if ($openDocument->status === 'open')
                                <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('Remaining') }}</dt><dd class="tabular-nums font-medium text-gray-900 dark:text-gray-100">{{ $euro($openDocument->outstanding()) }}</dd></div>
                            @endif
                        @endif
                    </dl>
                </div>

                @if (! $openDocument->isQuote() && ! $openDocument->isDraft() && $openDocument->status !== 'cancelled')
                    <section>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">💶 {{ $openDocument->isCreditNote() ? __('Refunds') : __('Payments') }}</h3>
                        @if ($openDocument->payments->isNotEmpty())
                            <ul class="mt-2 divide-y divide-gray-100 rounded-xl bg-gray-50 dark:divide-gray-700 dark:bg-gray-900/40">
                                @foreach ($openDocument->payments as $paymentRow)
                                    <li wire:key="payment-{{ $paymentRow->id }}" class="flex items-center gap-3 px-3 py-2 text-sm">
                                        <span class="w-20 tabular-nums text-gray-500 dark:text-gray-400">{{ $paymentRow->paid_on->format('d.m.Y') }}</span>
                                        <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ $methodLabels[$paymentRow->method] ?? '' }}{{ $paymentRow->note ? ' · '.$paymentRow->note : '' }}</span>
                                        <span class="tabular-nums font-medium text-gray-900 dark:text-gray-100">{{ $euro($paymentRow->amount) }}</span>
                                        <button type="button" wire:click="deletePayment({{ $paymentRow->id }})" wire:confirm="{{ __('Delete this payment?') }}" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}">✕</button>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('No payments recorded yet.') }}</p>
                        @endif

                        @if ($openDocument->isPayable())
                            <form wire:submit="addPayment" class="mt-3 grid gap-2 sm:grid-cols-12">
                                <input type="date" wire:model="payment.paid_on" max="{{ today()->toDateString() }}" aria-label="{{ __('Paid on') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 sm:col-span-3">
                                <input type="text" inputmode="decimal" wire:model="payment.amount" aria-label="{{ __('Amount') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 sm:col-span-2">
                                <select wire:model="payment.method" aria-label="{{ __('Payment method') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 sm:col-span-3">
                                    @foreach ($methodLabels as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <input type="text" wire:model="payment.note" placeholder="{{ __('Note (optional)') }}" aria-label="{{ __('Note') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 sm:col-span-2">
                                <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 sm:col-span-2">{{ $openDocument->isCreditNote() ? __('Record refund') : __('Record payment') }}</button>
                            </form>
                            <x-input-error :messages="array_merge($errors->get('payment.amount'), $errors->get('payment.paid_on'))" class="mt-1" />
                        @endif
                    </section>
                @endif

                @if ($openDocument->related || $openDocument->derived->isNotEmpty())
                    <section>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">🔗 {{ __('Related documents') }}</h3>
                        <ul class="mt-2 flex flex-wrap gap-2">
                            @foreach (collect([$openDocument->related])->filter()->concat($openDocument->derived) as $relatedDocument)
                                <li wire:key="related-{{ $relatedDocument->id }}">
                                    <button type="button" wire:click="openDocument({{ $relatedDocument->id }})" class="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-800 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200">
                                        {{ $typeLabels[$relatedDocument->type] }} {{ $relatedDocument->number ?? __('Draft') }} · {{ $euro($relatedDocument->gross_total) }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <x-input-error :messages="$errors->get('cancel')" />
            </div>

            <x-slot:footer>
                @if ($openDocument->isDraft())
                    <a href="{{ route('business.invoices.edit', $openDocument) }}" wire:navigate class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800">✎ {{ __('Edit') }}</a>
                @endif
                <a href="{{ route('business.invoices.pdf', $openDocument) }}" target="_blank" class="rounded-lg px-3 py-2 text-sm text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700">📄 PDF</a>
                @if ($openDocument->isQuote() && in_array($openDocument->status, ['open', 'accepted'], true))
                    <button type="button" wire:click="convertQuote({{ $openDocument->id }})" class="rounded-lg px-3 py-2 text-sm font-medium text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50 dark:text-indigo-300 dark:ring-indigo-800 dark:hover:bg-gray-700">🧾 {{ __('Create invoice') }}</button>
                @endif
                @if ($openDocument->isQuote() && $openDocument->status === 'open')
                    <button type="button" wire:click="setQuoteStatus({{ $openDocument->id }}, 'accepted')" class="rounded-lg px-3 py-2 text-sm text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-gray-700">✓ {{ __('Accepted') }}</button>
                    <button type="button" wire:click="setQuoteStatus({{ $openDocument->id }}, 'declined')" class="rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">✕ {{ __('Declined') }}</button>
                @endif
                @if ($openDocument->type === 'invoice' && in_array($openDocument->status, ['open', 'paid'], true))
                    <button type="button" wire:click="createCreditNote({{ $openDocument->id }})" class="rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">↩ {{ __('Credit note') }}</button>
                @endif
                @if ($openDocument->type === 'invoice' && $openDocument->status === 'open' && abs($openDocument->paid_total) < 0.005)
                    <button type="button" wire:click="cancel({{ $openDocument->id }})" wire:confirm="{{ __('Cancel this invoice? A cancellation invoice with its own number is created; both stay in the list.') }}" class="rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">✕ {{ __('Cancel invoice') }}</button>
                @endif
                <button type="button" wire:click="duplicate({{ $openDocument->id }})" class="ms-auto rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">⧉ {{ __('Duplicate') }}</button>
                @if ($openDocument->isDraft())
                    <button type="button" wire:click="deleteDraft({{ $openDocument->id }})" wire:confirm="{{ __('Delete this draft?') }}" class="rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">🗑</button>
                @endif
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
