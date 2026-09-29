<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\ExternalLink;
use App\Models\Quote;
use App\Models\QuoteComparison;
use App\Models\RealEstate;
use App\Models\RealEstateBudgetItem;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * One quote comparison: the quotes side by side, their documents, the choice and the link to a
 * trade of a property.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public int $comparisonId;

    public bool $showQuoteForm = false;

    public ?int $editingQuoteId = null;

    public ?int $documentsFor = null;

    /**
     * @var array<string, mixed>
     */
    public array $quote = [];

    public ?int $linkRealEstateId = null;

    public ?int $linkBudgetItemId = null;

    public string $notes = '';

    public function mount(QuoteComparison $comparison): void
    {
        $this->resolveHousehold();
        $this->comparisonId = $this->findComparison($comparison->id)->id;
        $this->resetQuote();
        $this->linkRealEstateId = $comparison->real_estate_id;
        $this->linkBudgetItemId = $comparison->budget_item_id;
        $this->notes = (string) $comparison->notes;
    }

    private function findComparison(?int $id = null): QuoteComparison
    {
        return QuoteComparison::query()->visibleTo($this->currentUser(), (int) $this->householdId)->find($id ?? $this->comparisonId) ?? abort(404);
    }

    private function findQuote(int $id): Quote
    {
        return $this->findComparison()->quotes()->find($id) ?? abort(404);
    }

    private function resetQuote(): void
    {
        $this->quote = ['vendor' => '', 'amount' => '', 'quoted_on' => '', 'valid_until' => '', 'delivery' => '', 'rating' => '', 'pros' => '', 'cons' => '', 'notes' => '', 'url' => ''];
        $this->editingQuoteId = null;
        $this->resetValidation();
    }

    public function addQuote(): void
    {
        $this->resetQuote();
        $this->showQuoteForm = true;
    }

    public function editQuote(int $id): void
    {
        $quote = $this->findQuote($id);
        $this->resetQuote();
        $this->editingQuoteId = $quote->id;
        $this->quote = [
            'vendor' => $quote->vendor,
            'amount' => $quote->amount !== null ? (string) $quote->amount : '',
            'quoted_on' => (string) $quote->quoted_on?->toDateString(),
            'valid_until' => (string) $quote->valid_until?->toDateString(),
            'delivery' => (string) $quote->delivery,
            'rating' => (string) $quote->rating,
            'pros' => (string) $quote->pros,
            'cons' => (string) $quote->cons,
            'notes' => (string) $quote->notes,
            'url' => (string) $quote->url,
        ];
        $this->showQuoteForm = true;
    }

    public function saveQuote(): void
    {
        $this->quote['amount'] = self::parseAmount((string) $this->quote['amount']);

        $data = $this->validate([
            'quote.vendor' => ['required', 'string', 'max:255'],
            'quote.amount' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'quote.quoted_on' => ['nullable', 'date'],
            'quote.valid_until' => ['nullable', 'date'],
            'quote.delivery' => ['nullable', 'string', 'max:255'],
            'quote.rating' => ['nullable', 'integer', 'between:1,5'],
            'quote.pros' => ['nullable', 'string', 'max:3000'],
            'quote.cons' => ['nullable', 'string', 'max:3000'],
            'quote.notes' => ['nullable', 'string', 'max:3000'],
            'quote.url' => ['nullable', 'url:http,https', 'max:2000'],
        ], attributes: ['quote.vendor' => __('Vendor'), 'quote.amount' => __('Price (€)')])['quote'];

        $attributes = collect($data)->map(fn ($value) => $value === '' ? null : $value)->all();
        $comparison = $this->findComparison();

        if ($this->editingQuoteId) {
            $this->findQuote($this->editingQuoteId)->update($attributes);
        } else {
            $quote = $comparison->quotes()->create($attributes + ['position' => (int) $comparison->quotes()->max('position') + 1]);
            $this->documentsFor = $quote->id;
        }

        $this->resetQuote();
        $this->showQuoteForm = false;
    }

    /**
     * Accepts German ("12.500,50") and plain ("12500.50") amounts.
     */
    private static function parseAmount(string $amount): string
    {
        $amount = trim(str_replace(['€', ' '], '', $amount));

        if (str_contains($amount, ',') || preg_match('/^\d{1,3}(\.\d{3})+$/', $amount)) {
            $amount = str_replace(['.', ','], ['', '.'], $amount);
        }

        return $amount;
    }

    public function deleteQuote(int $id): void
    {
        $quote = $this->findQuote($id);
        $comparison = $this->findComparison();

        if ($comparison->chosen_quote_id === $quote->id) {
            $comparison->update(['chosen_quote_id' => null]);
        }

        ExternalLink::where('linkable_type', 'quote')->where('linkable_id', $quote->id)->get()->each->delete();
        $quote->delete();
    }

    public function choose(?int $id): void
    {
        $comparison = $this->findComparison();
        $comparison->choose($id ? $this->findQuote($id) : null);

        if ($id && $comparison->budgetItem) {
            $this->dispatch('toast', message: __('Taken over into the trade “:trade”.', ['trade' => $comparison->budgetItem->title]));
        }
    }

    public function toggleDocuments(int $id): void
    {
        $this->documentsFor = $this->documentsFor === $id ? null : $this->findQuote($id)->id;
    }

    public function saveNotes(): void
    {
        $this->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $this->findComparison()->update(['notes' => trim($this->notes) ?: null]);
    }

    public function updatedLinkRealEstateId(): void
    {
        $this->linkBudgetItemId = null;
    }

    /**
     * Links the comparison to a property and one of its trades (or removes the link).
     */
    public function saveLink(): void
    {
        $realEstate = $this->linkRealEstateId ? RealEstate::query()->visibleTo($this->currentUser(), (int) $this->householdId)->find($this->linkRealEstateId) ?? abort(404) : null;
        $budgetItem = $realEstate && $this->linkBudgetItemId ? $realEstate->budgetItems()->find($this->linkBudgetItemId) ?? abort(404) : null;
        $comparison = $this->findComparison();
        $comparison->update(['real_estate_id' => $realEstate?->id, 'budget_item_id' => $budgetItem?->id]);

        if ($budgetItem && $comparison->chosenQuote) {
            $comparison->refresh()->choose($comparison->chosenQuote);
        } elseif ($budgetItem && $budgetItem->status === 'planned') {
            $budgetItem->update(['status' => 'quoted']);
        }

        $this->dispatch('toast', message: $realEstate ? __('Link saved.') : __('Link removed.'));
    }

    public function deleteComparison(): void
    {
        $comparison = $this->findComparison();
        abort_unless($comparison->created_by === $this->currentUser()->id || $comparison->visibility === 'shared', 403);

        foreach ($comparison->quotes as $quote) {
            ExternalLink::where('linkable_type', 'quote')->where('linkable_id', $quote->id)->get()->each->delete();
        }

        $comparison->delete();
        $this->redirectRoute('quotes.index', navigate: true);
    }

    public function with(): array
    {
        $comparison = $this->findComparison()->load(['quotes', 'realEstate', 'budgetItem']);
        $realEstates = RealEstate::query()->visibleTo($this->currentUser(), (int) $this->householdId)->orderBy('name')->get(['id', 'name']);

        return [
            'comparison' => $comparison,
            'cheapest' => $comparison->cheapestQuote(),
            'documentCounts' => ExternalLink::where('linkable_type', 'quote')->whereIn('linkable_id', $comparison->quotes->pluck('id'))->selectRaw('linkable_id, count(*) as total')->groupBy('linkable_id')->pluck('total', 'linkable_id'),
            'realEstates' => $realEstates,
            'budgetItems' => $this->linkRealEstateId && $realEstates->contains('id', $this->linkRealEstateId)
                ? RealEstateBudgetItem::where('real_estate_id', $this->linkRealEstateId)->orderBy('position')->get(['id', 'title', 'status'])
                : collect(),
        ];
    }
}; ?>

@php
    $euro = fn (?float $value) => $value === null ? '–' : number_format($value, 2, ',', '.').' €';
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $rows = [
        'amount' => __('Quoted price'),
        'quoted_on' => __('Quote of'),
        'valid_until' => __('Valid until'),
        'delivery' => __('Delivery / lead time'),
        'rating' => __('Rating'),
        'pros' => __('Pros'),
        'cons' => __('Cons'),
        'notes' => __('Notes'),
    ];
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $comparison->icon }} {{ $comparison->title }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <a href="{{ route('quotes.index') }}" wire:navigate class="text-indigo-600 hover:underline dark:text-indigo-400">← {{ __('All comparisons') }}</a>
                @if ($comparison->decide_by)
                    <span class="text-gray-600 dark:text-gray-400">{{ __('Decide by :date', ['date' => $comparison->decide_by->format('d.m.Y')]) }}</span>
                @endif
                @if ($comparison->realEstate)
                    <a href="{{ route('real-estate.show', ['realEstate' => $comparison->real_estate_id, 'tab' => 'budget']) }}" wire:navigate class="rounded-full bg-indigo-50 px-2.5 py-0.5 text-indigo-700 hover:underline dark:bg-indigo-900/40 dark:text-indigo-200">🏠 {{ $comparison->realEstate->name }}@if ($comparison->budgetItem) · {{ $comparison->budgetItem->title }}@endif</a>
                @endif
                <x-primary-button type="button" wire:click="addQuote" class="ms-auto">+ {{ __('Add quote') }}</x-primary-button>
            </div>

            @if ($showQuoteForm)
                <form wire:submit="saveQuote" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Vendor') }}<input type="text" wire:model="quote.vendor" class="{{ $input }}" autofocus><x-input-error :messages="$errors->get('quote.vendor')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Price (€)') }}<input type="text" inputmode="decimal" wire:model="quote.amount" class="{{ $input }}" placeholder="12.500,00"><x-input-error :messages="$errors->get('quote.amount')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Quote of') }}<input type="date" wire:model="quote.quoted_on" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Valid until') }}<input type="date" wire:model="quote.valid_until" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Rating') }}
                        <select wire:model="quote.rating" class="{{ $input }}">
                            <option value="">–</option>
                            @foreach (range(5, 1) as $stars)
                                <option value="{{ $stars }}">{{ str_repeat('★', $stars) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Delivery / lead time') }}<input type="text" wire:model="quote.delivery" class="{{ $input }}" placeholder="{{ __('e.g. 6 weeks, start in May') }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Link (optional)') }}<input type="url" wire:model="quote.url" class="{{ $input }}" placeholder="https://"><x-input-error :messages="$errors->get('quote.url')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Pros') }}<textarea wire:model="quote.pros" rows="3" class="{{ $input }}"></textarea></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Cons') }}<textarea wire:model="quote.cons" rows="3" class="{{ $input }}"></textarea></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Notes') }}<textarea wire:model="quote.notes" rows="3" class="{{ $input }}"></textarea></label>
                    <p class="text-xs text-gray-500 sm:col-span-6 dark:text-gray-400">📄 {{ __('After saving you can attach the quote PDF and other documents.') }}</p>
                    <div class="flex gap-2 sm:col-span-6">
                        <x-primary-button>{{ $editingQuoteId ? __('Save') : __('Add quote') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('showQuoteForm', false)">{{ __('Cancel') }}</x-secondary-button>
                    </div>
                </form>
            @endif

            @if ($comparison->quotes->isEmpty())
                <section class="rounded-2xl bg-white p-6 text-center text-sm text-gray-600 shadow-sm dark:bg-gray-800 dark:text-gray-300">{{ __('No quotes yet. Add the first one – price, validity and the PDF.') }}</section>
            @else
                <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800" aria-label="{{ __('Comparison') }}">
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr>
                                    <th class="sticky left-0 w-40 bg-white px-4 py-3 dark:bg-gray-800"><span class="sr-only">{{ __('Criterion') }}</span></th>
                                    @foreach ($comparison->quotes as $offer)
                                        <th wire:key="quote-head-{{ $offer->id }}" @class(['min-w-[12rem] px-4 py-3 text-start align-top', 'bg-emerald-50 dark:bg-emerald-900/20' => $comparison->chosen_quote_id === $offer->id])>
                                            <div class="flex items-start justify-between gap-2">
                                                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $offer->vendor }}</span>
                                                <span class="flex shrink-0 gap-1 text-gray-400">
                                                    <button type="button" wire:click="editQuote({{ $offer->id }})" class="hover:text-indigo-600" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="h-5 w-5" /></button>
                                                    <button type="button" wire:click="deleteQuote({{ $offer->id }})" wire:confirm="{{ __('Delete this quote and its documents?') }}" class="hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                                                </span>
                                            </div>
                                            @if ($comparison->chosen_quote_id === $offer->id)
                                                <span class="mt-1 inline-block rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-medium text-white">✓ {{ __('Chosen') }}</span>
                                            @elseif ($cheapest && $cheapest->id === $offer->id && $comparison->quotes->count() > 1)
                                                <span class="mt-1 inline-block rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-200">{{ __('Cheapest') }}</span>
                                            @endif
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($rows as $key => $label)
                                    @continue(! $comparison->quotes->contains(fn ($offer) => filled($offer->{$key})))
                                    <tr>
                                        <th scope="row" class="sticky left-0 bg-white px-4 py-2 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ $label }}</th>
                                        @foreach ($comparison->quotes as $offer)
                                            <td wire:key="quote-{{ $key }}-{{ $offer->id }}" @class(['px-4 py-2 align-top text-gray-800 dark:text-gray-200', 'bg-emerald-50 dark:bg-emerald-900/20' => $comparison->chosen_quote_id === $offer->id])>
                                                @switch($key)
                                                    @case('amount')
                                                        <span class="text-base font-semibold tabular-nums">{{ $euro($offer->amount) }}</span>
                                                        @if ($cheapest && $offer->amount !== null && $offer->id !== $cheapest->id)
                                                            <span class="block text-xs text-red-600 dark:text-red-400">+{{ $euro($offer->amount - $cheapest->amount) }} ({{ $cheapest->amount > 0 ? '+'.number_format(($offer->amount - $cheapest->amount) / $cheapest->amount * 100, 0, ',', '.').' %' : '' }})</span>
                                                        @endif
                                                        @break
                                                    @case('quoted_on')
                                                        {{ $offer->quoted_on?->format('d.m.Y') ?? '–' }}
                                                        @break
                                                    @case('valid_until')
                                                        @if ($offer->valid_until)
                                                            <span @class(['text-red-600 dark:text-red-400' => $offer->isExpired()])>{{ $offer->valid_until->format('d.m.Y') }}@if ($offer->isExpired()) · {{ __('expired') }}@endif</span>
                                                        @else
                                                            –
                                                        @endif
                                                        @break
                                                    @case('rating')
                                                        <span class="text-amber-400" aria-label="{{ trans_choice(':count star|:count stars', (int) $offer->rating) }}">{{ $offer->rating ? str_repeat('★', $offer->rating) : '–' }}</span>
                                                        @break
                                                    @default
                                                        <span class="whitespace-pre-line">{{ $offer->{$key} ?: '–' }}</span>
                                                @endswitch
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                                <tr>
                                    <th scope="row" class="sticky left-0 bg-white px-4 py-2 text-start text-xs font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ __('Documents') }}</th>
                                    @foreach ($comparison->quotes as $offer)
                                        <td wire:key="quote-docs-{{ $offer->id }}" @class(['px-4 py-2 align-top', 'bg-emerald-50 dark:bg-emerald-900/20' => $comparison->chosen_quote_id === $offer->id])>
                                            <button type="button" wire:click="toggleDocuments({{ $offer->id }})" class="text-indigo-600 hover:underline dark:text-indigo-400">📄 {{ $documentCounts[$offer->id] ?? 0 }} · {{ $documentsFor === $offer->id ? __('Hide') : __('Show / add') }}</button>
                                            @if ($offer->url)
                                                <a href="{{ $offer->url }}" target="_blank" rel="noopener" class="mt-1 block truncate text-xs text-gray-500 hover:underline dark:text-gray-400">🔗 {{ parse_url($offer->url, PHP_URL_HOST) }}</a>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                                <tr>
                                    <th scope="row" class="sticky left-0 bg-white px-4 py-3 dark:bg-gray-800"><span class="sr-only">{{ __('Choice') }}</span></th>
                                    @foreach ($comparison->quotes as $offer)
                                        <td wire:key="quote-choose-{{ $offer->id }}" @class(['px-4 py-3 align-top', 'bg-emerald-50 dark:bg-emerald-900/20' => $comparison->chosen_quote_id === $offer->id])>
                                            @if ($comparison->chosen_quote_id === $offer->id)
                                                <button type="button" wire:click="choose(null)" class="text-xs text-gray-500 underline">{{ __('Undo choice') }}</button>
                                            @else
                                                <x-secondary-button type="button" wire:click="choose({{ $offer->id }})" :wire:confirm="$comparison->budgetItem ? __('Choose this quote? Vendor and price are taken over into the trade “:trade”.', ['trade' => $comparison->budgetItem->title]) : null">{{ __('Choose') }}</x-secondary-button>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                @if ($documentsFor && ($documentQuote = $comparison->quotes->firstWhere('id', $documentsFor)))
                    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                        <p class="mb-2 text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Documents for :vendor', ['vendor' => $documentQuote->vendor]) }}</p>
                        <livewire:documents-panel type="quote" :id="$documentQuote->id" :wire:key="'documents-quote-'.$documentQuote->id" />
                    </section>
                @endif
            @endif

            <div class="grid gap-5 lg:grid-cols-2">
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🏠 {{ __('Link to a property') }}</h3>
                    @if ($realEstates->isEmpty())
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('No properties yet.') }}</p>
                    @else
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Linked to a trade, the chosen quote becomes its contractor and planned amount.') }}</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Property (real estate)') }}
                                <select wire:model.live="linkRealEstateId" class="{{ $input }}">
                                    <option value="">–</option>
                                    @foreach ($realEstates as $realEstate)
                                        <option value="{{ $realEstate->id }}">{{ $realEstate->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Trade') }}
                                <select wire:model="linkBudgetItemId" class="{{ $input }}" @disabled($budgetItems->isEmpty())>
                                    <option value="">–</option>
                                    @foreach ($budgetItems as $budgetItem)
                                        <option value="{{ $budgetItem->id }}">{{ $budgetItem->title }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <x-secondary-button type="button" wire:click="saveLink" class="mt-3">{{ __('Save link') }}</x-secondary-button>
                    @endif
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">📝 {{ __('Notes') }}</h3>
                    <textarea wire:model="notes" rows="4" class="{{ $input }}" placeholder="{{ __('Requirements, questions to the vendors …') }}"></textarea>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <x-secondary-button type="button" wire:click="saveNotes">{{ __('Save') }}</x-secondary-button>
                        <button type="button" wire:click="deleteComparison" wire:confirm="{{ __('Delete this comparison with all quotes and documents?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete comparison') }}</button>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
