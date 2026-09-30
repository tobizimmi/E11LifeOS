<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\QuoteComparison;
use App\Models\RealEstate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * All quote comparisons of the household: open ones first, with the cheapest and the chosen offer.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Url(as: 'real_estate')]
    public ?int $realEstateId = null;

    #[Url(as: 'budget_item')]
    public ?int $budgetItemId = null;

    public bool $showForm = false;

    /**
     * @var array{title: string, icon: string, decide_by: string, private: bool}
     */
    public array $form = ['title' => '', 'icon' => '🧾', 'decide_by' => '', 'private' => false];

    public function mount(): void
    {
        $this->resolveHousehold();

        // Coming from a trade of a property: open the form for that trade, or its existing comparison.
        if ($this->budgetItemId) {
            $item = $this->budgetItem($this->budgetItemId);
            $existing = QuoteComparison::query()->visibleTo($this->currentUser(), (int) $this->householdId)->where('budget_item_id', $item->id)->first();

            if ($existing) {
                $this->redirectRoute('quotes.show', $existing, navigate: true);

                return;
            }

            $this->realEstateId = $item->real_estate_id;
            $this->form['title'] = $item->title.' · '.$item->realEstate->name;
            $this->form['icon'] = '🏠';
            $this->showForm = true;
        }
    }

    private function budgetItem(int $id): \App\Models\RealEstateBudgetItem
    {
        return \App\Models\RealEstateBudgetItem::query()
            ->whereIn('real_estate_id', RealEstate::query()->visibleTo($this->currentUser(), (int) $this->householdId)->select('id'))
            ->with('realEstate')->find($id) ?? abort(404);
    }

    public function create(): void
    {
        $data = $this->validate([
            'form.title' => ['required', 'string', 'max:255'],
            'form.icon' => ['nullable', 'string', 'max:16'],
            'form.decide_by' => ['nullable', 'date'],
            'form.private' => ['boolean'],
            'realEstateId' => ['nullable', 'integer', Rule::in(RealEstate::query()->visibleTo($this->currentUser(), (int) $this->householdId)->pluck('id')->all())],
            'budgetItemId' => ['nullable', 'integer'],
        ], attributes: ['form.title' => __('Title')]);

        $budgetItem = $this->budgetItemId ? $this->budgetItem($this->budgetItemId) : null;
        $comparison = QuoteComparison::create([
            'household_id' => $this->householdId,
            'created_by' => $this->currentUser()->id,
            'visibility' => $data['form']['private'] ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'title' => trim($data['form']['title']),
            'icon' => $data['form']['icon'] ?: '🧾',
            'decide_by' => $data['form']['decide_by'] ?: null,
            'real_estate_id' => $budgetItem?->real_estate_id ?? $this->realEstateId,
            'budget_item_id' => $budgetItem?->id,
        ]);

        if ($budgetItem && $budgetItem->status === 'planned') {
            $budgetItem->update(['status' => 'quoted']);
        }

        $this->redirectRoute('quotes.show', $comparison, navigate: true);
    }

    public function with(): array
    {
        $comparisons = QuoteComparison::query()->visibleTo($this->currentUser(), (int) $this->householdId)
            ->with(['quotes', 'chosenQuote', 'realEstate', 'budgetItem'])->latest()->get()
            ->sortBy(fn (QuoteComparison $comparison) => $comparison->isDecided() ? 1 : 0)->values();

        return [
            'comparisons' => $comparisons,
            'realEstates' => RealEstate::query()->visibleTo($this->currentUser(), (int) $this->householdId)->orderBy('name')->get(['id', 'name']),
            'linkedItem' => $this->budgetItemId ? $this->budgetItem($this->budgetItemId) : null,
        ];
    }
}; ?>

@php
    $euro = fn (?float $value) => $value === null ? '–' : number_format($value, 2, ',', '.').' €';
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
@endphp

<div x-on:page-primary.window="$wire.set('showForm', true)">
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Quote comparisons') }}</h2>
            <x-primary-button type="button" x-on:click="$dispatch('page-primary')" class="shrink-0">+ {{ __('New comparison') }}</x-primary-button>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Collect several quotes for a purchase, compare them side by side and pick the best one.') }}</p>

            @if ($showForm)
                <form wire:submit="create" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-1 dark:text-gray-300">{{ __('Icon') }}<input type="text" wire:model="form.icon" maxlength="16" class="{{ $input }}"></label>
                    <label class="text-sm text-gray-700 sm:col-span-5 dark:text-gray-300">{{ __('What should be bought?') }}<input type="text" wire:model="form.title" class="{{ $input }}" placeholder="{{ __('e.g. New kitchen, family car, roof renovation') }}" autofocus><x-input-error :messages="$errors->get('form.title')" /></label>
                    @if ($linkedItem)
                        <p class="rounded-lg bg-indigo-50 px-3 py-2 text-sm text-indigo-800 sm:col-span-6 dark:bg-indigo-900/30 dark:text-indigo-200">🏠 {{ __('Linked to the trade “:trade” of :property – the chosen quote is taken over there.', ['trade' => $linkedItem->title, 'property' => $linkedItem->realEstate->name]) }}</p>
                    @elseif ($realEstates->isNotEmpty())
                        <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Property (optional)') }}
                            <select wire:model="realEstateId" class="{{ $input }}">
                                <option value="">–</option>
                                @foreach ($realEstates as $realEstate)
                                    <option value="{{ $realEstate->id }}">{{ $realEstate->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Decide by') }}<input type="date" wire:model="form.decide_by" class="{{ $input }}"></label>
                    <label class="flex items-end gap-2 pb-2 text-sm text-gray-700 sm:col-span-1 dark:text-gray-300"><input type="checkbox" wire:model="form.private" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900"> 🔒 {{ __('Private') }}</label>
                    <div class="flex gap-2 sm:col-span-6">
                        <x-primary-button>{{ __('Create') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="$set('showForm', false)">{{ __('Cancel') }}</x-secondary-button>
                    </div>
                </form>
            @endif

            @if ($comparisons->isEmpty())
                <section class="rounded-2xl bg-white p-6 text-center text-sm text-gray-600 shadow-sm dark:bg-gray-800 dark:text-gray-300">{{ __('No comparisons yet. Start one for your next bigger purchase.') }}</section>
            @else
                <ul class="grid gap-3 sm:grid-cols-2">
                    @foreach ($comparisons as $comparison)
                        @php
                            $cheapest = $comparison->cheapestQuote();
                        @endphp
                        <li wire:key="comparison-{{ $comparison->id }}">
                            <a href="{{ route('quotes.show', $comparison) }}" wire:navigate class="block h-full rounded-2xl bg-white p-4 shadow-sm ring-1 ring-transparent hover:ring-indigo-300 dark:bg-gray-800">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $comparison->icon }} {{ $comparison->title }}</p>
                                    @if ($comparison->isDecided())
                                        <span class="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200">✓ {{ __('Decided') }}</span>
                                    @elseif ($comparison->decide_by)
                                        <span @class(['shrink-0 rounded-full px-2 py-0.5 text-xs', 'bg-red-50 text-red-700 dark:bg-red-900/40 dark:text-red-200' => $comparison->decide_by->lt(today()), 'bg-amber-50 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200' => ! $comparison->decide_by->lt(today())])>{{ __('by :date', ['date' => $comparison->decide_by->format('d.m.Y')]) }}</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                    {{ trans_choice(':count quote|:count quotes', $comparison->quotes->count()) }}
                                    @if ($comparison->chosenQuote) · {{ $comparison->chosenQuote->vendor }} ({{ $euro($comparison->chosenQuote->amount) }})
                                    @elseif ($cheapest) · {{ __('from :amount', ['amount' => $euro($cheapest->amount)]) }}@endif
                                </p>
                                @if ($comparison->realEstate)
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">🏠 {{ $comparison->realEstate->name }}@if ($comparison->budgetItem) · {{ $comparison->budgetItem->title }}@endif</p>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
