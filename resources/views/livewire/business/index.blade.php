<?php

use App\Livewire\Concerns\InteractsWithBusiness;
use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Invoice;
use App\Support\Business\EuerReport;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithBusiness;
    use InteractsWithHousehold;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: 0)]
    public int $year = 0;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->year = $this->year >= 2000 && $this->year <= 2100 ? $this->year : 0;
    }

    private function findInvoice(int $invoiceId): Invoice
    {
        return $this->invoices()->find($invoiceId) ?? abort(404);
    }

    public function markPaid(int $invoiceId, ?string $paidOn = null): void
    {
        $invoice = $this->findInvoice($invoiceId);
        abort_unless($invoice->status === Invoice::OPEN, 422);

        $date = rescue(fn () => $paidOn ? Carbon::parse($paidOn) : today(), today(), false);
        $invoice->update(['status' => Invoice::PAID, 'paid_on' => $date->min(today())]);
    }

    public function markUnpaid(int $invoiceId): void
    {
        $invoice = $this->findInvoice($invoiceId);
        abort_unless($invoice->status === Invoice::PAID, 422);
        $invoice->update(['status' => Invoice::OPEN, 'paid_on' => null]);
    }

    public function cancel(int $invoiceId): void
    {
        $invoice = $this->findInvoice($invoiceId);
        abort_unless($invoice->status === Invoice::OPEN, 422);
        $invoice->update(['status' => Invoice::CANCELLED]);
    }

    public function duplicate(int $invoiceId): void
    {
        $source = $this->findInvoice($invoiceId);
        $copy = $source->replicate(['number', 'status', 'issued_on', 'due_on', 'paid_on']);
        $copy->status = Invoice::DRAFT;
        $copy->small_business = $this->businessProfile()?->small_business ?? $source->small_business;
        $copy->save();

        foreach ($source->items as $item) {
            $copy->items()->create($item->only(['position', 'description', 'quantity', 'unit', 'unit_price', 'vat_rate']));
        }

        $copy->recalculate();
        $this->redirectRoute('business.invoices.edit', ['invoice' => $copy->id], navigate: true);
    }

    public function deleteDraft(int $invoiceId): void
    {
        $invoice = $this->findInvoice($invoiceId);
        abort_unless($invoice->isDraft(), 422);
        $invoice->delete();
    }

    public function with(): array
    {
        $profile = $this->businessProfile();
        $kpiYear = $this->year ?: now()->year;
        $term = mb_strtolower(trim($this->search));

        $invoiceList = $this->invoices()
            ->when($this->year, fn ($query) => $query->where(fn ($query) => $query->whereYear('issued_on', $this->year)->orWhere(fn ($query) => $query->whereNull('issued_on')->whereYear('created_at', $this->year))))
            ->when($this->status === 'overdue', fn ($query) => $query->where('status', Invoice::OPEN)->whereDate('due_on', '<', today()))
            ->when(in_array($this->status, [Invoice::DRAFT, Invoice::OPEN, Invoice::PAID, Invoice::CANCELLED], true), fn ($query) => $query->where('status', $this->status))
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->whereRaw('LOWER(number) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(recipient) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(subject) LIKE ?', ['%'.$term.'%'])))
            ->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")
            ->orderByDesc('issued_on')->orderByDesc('id')
            ->get();

        $open = $this->invoices()->where('status', Invoice::OPEN)->get();
        $report = $profile ? EuerReport::build($this->currentUser(), (int) $this->householdId, $kpiYear, $profile) : null;

        return [
            'profile' => $profile,
            'invoiceList' => $invoiceList,
            'kpiYear' => $kpiYear,
            'report' => $report,
            'openTotal' => round((float) $open->sum('gross_total'), 2),
            'openCount' => $open->count(),
            'overdueCount' => $open->filter->isOverdue()->count(),
            'years' => $this->invoices()->whereNotNull('issued_on')->pluck('issued_on')->map->year->push(now()->year)->unique()->sortDesc()->values(),
        ];
    }
}; ?>

@php
    $euro = fn ($value, $decimals = 2) => number_format((float) $value, $decimals, ',', '.').' €';
    $statusLabels = ['draft' => __('Draft'), 'open' => __('Open'), 'overdue' => __('Overdue'), 'paid' => __('Paid'), 'cancelled' => __('Cancelled')];
    $statusClasses = [
        'draft' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
        'open' => 'bg-sky-50 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        'overdue' => 'bg-red-50 text-red-800 dark:bg-red-900/40 dark:text-red-200',
        'paid' => 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
        'cancelled' => 'bg-gray-100 text-gray-500 line-through dark:bg-gray-700 dark:text-gray-400',
    ];
    $statusIcons = ['draft' => '✎', 'open' => '⏳', 'overdue' => '⚠', 'paid' => '✓', 'cancelled' => '✕'];
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
                        [__('Revenue :year', ['year' => $kpiYear]), $euro($report['revenue'], 0), __('paid invoices and sales')],
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

                <div class="flex flex-wrap items-center gap-3">
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search number, customer, subject …') }}" aria-label="{{ __('Search') }}" class="w-full rounded-lg sm:w-auto sm:min-w-0 sm:flex-1 border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                    <select wire:model.live="status" aria-label="{{ __('Status') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach ($statusLabels as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="year" aria-label="{{ __('Year') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        <option value="0">{{ __('All years') }}</option>
                        @foreach ($years as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                    <a href="{{ route('business.invoices.create') }}" wire:navigate class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 dark:bg-gray-200 dark:text-gray-800 dark:hover:bg-white">＋ {{ __('New invoice') }}</a>
                </div>

                @if ($invoiceList->isEmpty())
                    <div class="rounded-2xl bg-white p-8 text-center shadow-sm dark:bg-gray-800">
                        <p class="text-4xl" aria-hidden="true">🧾</p>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $search !== '' || $status !== '' ? __('No invoices found.') : __('No invoices yet – create your first one.') }}</p>
                    </div>
                @else
                    <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white shadow-sm dark:divide-gray-700 dark:bg-gray-800">
                        @foreach ($invoiceList as $invoice)
                            @php
                                $displayStatus = $invoice->displayStatus();
                            @endphp
                            <li wire:key="invoice-{{ $invoice->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3" x-data="{ paying: false, paidOn: '{{ today()->toDateString() }}' }">
                                <div class="w-28 shrink-0">
                                    <p class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $invoice->number ?? '—' }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $invoice->issued_on?->format('d.m.Y') ?? __('not issued') }}</p>
                                </div>
                                <div class="min-w-[10rem] flex-1">
                                    <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ strtok($invoice->recipient, "\n") }}</p>
                                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                        {{ $invoice->subject ?: $invoice->service_period }}
                                        @if ($invoice->status === 'open' && $invoice->due_on) · {{ __('due :date', ['date' => $invoice->due_on->format('d.m.Y')]) }} @endif
                                        @if ($invoice->status === 'paid' && $invoice->paid_on) · {{ __('paid on :date', ['date' => $invoice->paid_on->format('d.m.Y')]) }} @endif
                                    </p>
                                </div>
                                <span class="inline-flex w-24 shrink-0 items-center justify-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClasses[$displayStatus] }}"><span aria-hidden="true">{{ $statusIcons[$displayStatus] }}</span>{{ $statusLabels[$displayStatus] }}</span>
                                <span class="ms-auto w-24 shrink-0 text-end text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100 sm:ms-0">{{ $euro($invoice->gross_total) }}</span>
                                <div class="flex w-full shrink-0 flex-wrap items-center justify-end gap-1 sm:w-72">
                                    <template x-if="paying">
                                        <form class="flex items-center gap-1" x-on:submit.prevent="$wire.markPaid({{ $invoice->id }}, paidOn); paying = false">
                                            <input type="date" x-model="paidOn" max="{{ today()->toDateString() }}" aria-label="{{ __('Paid on') }}" class="rounded-lg border-gray-300 py-1 text-xs dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                                            <button type="submit" class="rounded-lg bg-emerald-600 px-2 py-1 text-xs font-medium text-white hover:bg-emerald-700">{{ __('Save') }}</button>
                                            <button type="button" x-on:click="paying = false" class="rounded-lg px-2 py-1 text-xs text-gray-500">{{ __('Cancel') }}</button>
                                        </form>
                                    </template>
                                    <div class="flex flex-wrap items-center gap-1" x-show="! paying">
                                        @if ($invoice->isDraft())
                                            <a href="{{ route('business.invoices.edit', $invoice) }}" wire:navigate class="rounded-lg px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-gray-700">✎ {{ __('Edit') }}</a>
                                        @endif
                                        @if ($invoice->status === 'open')
                                            <button type="button" x-on:click="paying = true" class="rounded-lg px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-gray-700">💶 {{ __('Record payment') }}</button>
                                        @endif
                                        <a href="{{ route('business.invoices.pdf', $invoice) }}" class="rounded-lg px-2 py-1 text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">📄 PDF</a>
                                        <x-dropdown align="right" width="48">
                                            <x-slot name="trigger">
                                                <button type="button" class="rounded-lg px-2 py-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('More actions') }}">⋯</button>
                                            </x-slot>
                                            <x-slot name="content">
                                                <button type="button" wire:click="duplicate({{ $invoice->id }})" class="block w-full px-4 py-2 text-start text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">⧉ {{ __('Duplicate as new draft') }}</button>
                                                @if ($invoice->status === 'paid')
                                                    <button type="button" wire:click="markUnpaid({{ $invoice->id }})" class="block w-full px-4 py-2 text-start text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">↺ {{ __('Mark as unpaid') }}</button>
                                                @endif
                                                @if ($invoice->status === 'open')
                                                    <button type="button" wire:click="cancel({{ $invoice->id }})" wire:confirm="{{ __('Cancel this invoice? The number stays reserved and the invoice remains in the list.') }}" class="block w-full px-4 py-2 text-start text-sm text-red-600 hover:bg-gray-100 dark:text-red-400 dark:hover:bg-gray-800">✕ {{ __('Cancel invoice') }}</button>
                                                @endif
                                                @if ($invoice->isDraft())
                                                    <button type="button" wire:click="deleteDraft({{ $invoice->id }})" wire:confirm="{{ __('Delete this draft?') }}" class="block w-full px-4 py-2 text-start text-sm text-red-600 hover:bg-gray-100 dark:text-red-400 dark:hover:bg-gray-800">🗑 {{ __('Delete draft') }}</button>
                                                @endif
                                            </x-slot>
                                        </x-dropdown>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>
    </div>
</div>
