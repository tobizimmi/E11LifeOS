<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\PortfolioTransaction;
use App\Support\Portfolio\Positions;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;
    use WithPagination;

    #[Url(as: 'depot', except: 0)]
    public int $portfolioId = 0;

    #[Url(except: '')]
    public string $type = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array<string, string>
     */
    public array $form = [];

    public function mount(): void
    {
        $this->resolveHousehold();

        if (request()->boolean('new') && $this->portfolios()->exists()) {
            $symbol = strtoupper(mb_substr(trim((string) request()->query('symbol')), 0, 32));
            $this->newTransaction($symbol ?: null);

            if ($symbol !== '') {
                $this->form['name'] = (string) \App\Models\Security::where('symbol', $symbol)->value('name');
            }
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['portfolioId', 'type', 'search'], true)) {
            $this->resetPage();
        }
    }

    private function transactions()
    {
        return PortfolioTransaction::whereIn('portfolio_id', $this->portfolios()->select('id'));
    }

    public function newTransaction(?string $symbol = null): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->form = [
            'portfolio_id' => (string) ($this->portfolioId ?: $this->portfolioSettings()->default_portfolio_id ?: $this->portfolios()->value('id')),
            'type' => 'buy',
            'symbol' => (string) $symbol,
            'name' => '',
            'isin' => '',
            'executed_on' => today()->toDateString(),
            'quantity' => '',
            'price' => '',
            'fees' => '',
            'taxes' => '',
            'currency' => 'EUR',
            'exchange_rate' => '1',
            'notes' => '',
        ];
        $this->showForm = true;
    }

    public function editTransaction(int $transactionId): void
    {
        $transaction = $this->transactions()->find($transactionId) ?? abort(404);
        $number = fn (float $value) => $value ? rtrim(rtrim(number_format($value, 6, ',', ''), '0'), ',') : '';
        $this->resetValidation();
        $this->editingId = $transaction->id;
        $this->form = [
            'portfolio_id' => (string) $transaction->portfolio_id,
            'type' => $transaction->type,
            'symbol' => $transaction->symbol,
            'name' => $transaction->name,
            'isin' => (string) $transaction->isin,
            'executed_on' => $transaction->executed_on->toDateString(),
            'quantity' => $number($transaction->quantity),
            'price' => $number($transaction->price),
            'fees' => $number($transaction->fees),
            'taxes' => $number($transaction->taxes),
            'currency' => $transaction->currency,
            'exchange_rate' => $number($transaction->exchange_rate) ?: '1',
            'notes' => (string) $transaction->notes,
        ];
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function saveTransaction(): void
    {
        foreach (['quantity', 'price', 'fees', 'taxes', 'exchange_rate'] as $field) {
            $this->form[$field] = str_replace(',', '.', str_replace(' ', '', trim((string) $this->form[$field])));
        }

        $this->form['symbol'] = strtoupper(trim((string) $this->form['symbol']));
        $this->form['currency'] = strtoupper(trim((string) $this->form['currency']));
        $needsQuantity = in_array($this->form['type'], ['buy', 'sell', 'transfer_in', 'transfer_out'], true);

        $data = $this->validate([
            'form.portfolio_id' => ['required', 'integer'],
            'form.type' => ['required', 'in:'.implode(',', PortfolioTransaction::TYPES)],
            'form.symbol' => ['required', 'string', 'max:32'],
            'form.name' => ['nullable', 'string', 'max:255'],
            'form.isin' => ['nullable', 'string', 'size:12'],
            'form.executed_on' => ['required', 'date'],
            'form.quantity' => [$needsQuantity ? 'required' : 'nullable', 'numeric', $needsQuantity ? 'gt:0' : 'min:0'],
            'form.price' => [$this->form['type'] === 'fee' ? 'nullable' : 'required', 'numeric', 'min:0'],
            'form.fees' => ['nullable', 'numeric', 'min:0'],
            'form.taxes' => ['nullable', 'numeric', 'min:0'],
            'form.currency' => ['required', 'string', 'size:3'],
            'form.exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'form.notes' => ['nullable', 'string', 'max:2000'],
        ], attributes: [
            'form.symbol' => __('Security'),
            'form.quantity' => __('Shares'),
            'form.price' => $this->form['type'] === 'split' ? __('Split ratio') : __('Price'),
            'form.executed_on' => __('Date'),
        ])['form'];

        $portfolio = $this->findPortfolio((int) $data['portfolio_id']);
        $attributes = [
            'portfolio_id' => $portfolio->id,
            'type' => $data['type'],
            'symbol' => $data['symbol'],
            'name' => $data['name'] ?: ($portfolio->positions()->where('symbol', $data['symbol'])->value('name') ?? $data['symbol']),
            'isin' => $data['isin'] ? strtoupper($data['isin']) : null,
            'executed_on' => $data['executed_on'],
            'quantity' => (float) ($data['quantity'] ?: 0),
            'price' => (float) ($data['price'] ?: 0),
            'fees' => (float) ($data['fees'] ?: 0),
            'taxes' => (float) ($data['taxes'] ?: 0),
            'currency' => $data['currency'],
            'exchange_rate' => (float) ($data['exchange_rate'] ?: 1),
            'notes' => $data['notes'] ?: null,
        ];

        if ($data['type'] === 'sell') {
            $held = (float) $portfolio->positions()->where('symbol', $data['symbol'])->value('quantity');
            $previous = $this->editingId ? $this->transactions()->find($this->editingId) : null;
            $available = $held + ($previous?->type === 'sell' && $previous->symbol === $data['symbol'] ? $previous->quantity : 0);

            if ($attributes['quantity'] > $available + 0.000001) {
                $this->addError('form.quantity', __('You only hold :quantity of this security.', ['quantity' => rtrim(rtrim(number_format($available, 6, ',', '.'), '0'), ',')]));

                return;
            }
        }

        if ($this->editingId) {
            $transaction = $this->transactions()->find($this->editingId) ?? abort(404);
            $oldPortfolio = $transaction->portfolio;
            $transaction->update($attributes);

            if ($oldPortfolio->isNot($portfolio)) {
                Positions::recalculate($oldPortfolio);
            }
        } else {
            $portfolio->transactions()->create($attributes);
        }

        Positions::recalculate($portfolio);
        $this->showForm = false;
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function deleteTransaction(int $transactionId): void
    {
        $transaction = $this->transactions()->find($transactionId) ?? abort(404);
        $portfolio = $transaction->portfolio;
        $transaction->delete();
        Positions::recalculate($portfolio);
        $this->showForm = false;
    }

    public function with(): array
    {
        $term = mb_strtolower(trim($this->search));
        $query = $this->transactions()
            ->when($this->portfolioId, fn ($query) => $query->where('portfolio_id', $this->portfolioId))
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query->whereRaw('LOWER(symbol) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(name) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(isin) LIKE ?', ['%'.$term.'%'])));

        return [
            'portfolioList' => $this->portfolios()->orderBy('id')->get(),
            'transactionList' => (clone $query)->with('portfolio')->orderByDesc('executed_on')->orderByDesc('id')->paginate(50),
            'totals' => [
                'invested' => (float) (clone $query)->where('type', 'buy')->sum(\Illuminate\Support\Facades\DB::raw('quantity * price + fees')),
                'dividends' => (clone $query)->where('type', 'dividend')->get()->sum(fn (PortfolioTransaction $transaction) => $transaction->cashFlow()),
            ],
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 6, ',', '.'), '0'), ',');
    $typeLabels = [
        'buy' => __('Purchase'), 'sell' => __('Sale'), 'dividend' => __('Dividend'), 'split' => __('Split'),
        'fee' => __('Fee'), 'transfer_in' => __('Transfer in'), 'transfer_out' => __('Transfer out'),
    ];
    $typeClasses = [
        'buy' => 'bg-sky-50 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
        'sell' => 'bg-violet-50 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200',
        'dividend' => 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
    ];
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">📈 {{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => 'portfolio.transactions'])

            @if ($portfolioList->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center text-sm text-gray-600 shadow-sm dark:bg-gray-800 dark:text-gray-400">
                    {{ __('Create a portfolio first.') }} <a href="{{ route('portfolio.index') }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-300">{{ __('Overview') }}</a>
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2">
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search security …') }}" aria-label="{{ __('Search') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 sm:w-56">
                    <select wire:model.live="portfolioId" aria-label="{{ __('Portfolio') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        <option value="0">{{ __('All portfolios') }}</option>
                        @foreach ($portfolioList as $portfolio)
                            <option value="{{ $portfolio->id }}">{{ $portfolio->name }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="type" aria-label="{{ __('Type') }}" class="rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">{{ __('All types') }}</option>
                        @foreach ($typeLabels as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <x-primary-button type="button" wire:click="newTransaction" class="ms-auto">＋ {{ __('Transaction') }}</x-primary-button>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Purchases incl. fees') }}</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($totals['invested']) }}</p>
                    </div>
                    <div class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Dividends (net)') }}</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($totals['dividends']) }}</p>
                    </div>
                </div>

                @if ($transactionList->isEmpty())
                    <div class="rounded-2xl bg-white p-8 text-center text-sm text-gray-600 shadow-sm dark:bg-gray-800 dark:text-gray-400">{{ __('No transactions yet.') }}</div>
                @else
                    <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white shadow-sm dark:divide-gray-700 dark:bg-gray-800">
                        @foreach ($transactionList as $transaction)
                            <li wire:key="transaction-{{ $transaction->id }}">
                                <button type="button" wire:click="editTransaction({{ $transaction->id }})" class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <span class="w-20 shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $transaction->executed_on->format('d.m.Y') }}</span>
                                    <span class="w-24 shrink-0"><span class="rounded-full px-2 py-0.5 text-xs {{ $typeClasses[$transaction->type] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }}">{{ $typeLabels[$transaction->type] ?? $transaction->type }}</span></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $transaction->name ?: $transaction->symbol }}</span>
                                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">
                                            {{ $transaction->symbol }}
                                            @if (! $portfolioId) · {{ $transaction->portfolio->name }} @endif
                                            @if ($transaction->type === 'split')
                                                · {{ __('Ratio :ratio : 1', ['ratio' => $quantity($transaction->price)]) }}
                                            @elseif ($transaction->quantity > 0)
                                                · {{ $quantity($transaction->quantity) }} × {{ number_format($transaction->price, 2, ',', '.') }} {{ $transaction->currency }}
                                            @endif
                                        </span>
                                    </span>
                                    @if ($transaction->type !== 'split')
                                        <span class="shrink-0 text-end text-sm tabular-nums {{ $transaction->cashFlow() >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-gray-900 dark:text-gray-100' }}">{{ number_format($transaction->cashFlow(), 2, ',', '.') }} {{ $transaction->currency }}</span>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    {{ $transactionList->links() }}
                @endif
            @endif
        </div>
    </div>

    @if ($showForm)
        <x-sheet close="closeForm" :label="$editingId ? __('Edit transaction') : __('New transaction')" wire:key="transaction-form-{{ $editingId ?? 'new' }}">
            <x-slot:header><strong class="text-gray-800 dark:text-gray-100">🧾 {{ $editingId ? __('Edit transaction') : __('New transaction') }}</strong></x-slot:header>

            <form id="transaction-form" wire:submit="saveTransaction" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="{{ __('Type') }}">
                        @foreach ($typeLabels as $key => $label)
                            <label @class(['cursor-pointer rounded-full px-3 py-1 text-sm ring-1', 'bg-indigo-600 text-white ring-indigo-600' => $form['type'] === $key, 'text-gray-700 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-700' => $form['type'] !== $key])>
                                <input type="radio" wire:model.live="form.type" value="{{ $key }}" class="sr-only">{{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <x-input-label for="transaction-portfolio" :value="__('Portfolio')" />
                    <select id="transaction-portfolio" wire:model="form.portfolio_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        @foreach ($portfolioList as $portfolio)
                            <option value="{{ $portfolio->id }}">{{ $portfolio->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="transaction-date" :value="__('Date')" />
                    <x-text-input id="transaction-date" type="date" wire:model="form.executed_on" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('form.executed_on')" class="mt-1" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="transaction-search" :value="__('Security')" />
                    @if ($form['symbol'])
                        <p class="mt-1 flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900/40"><strong>{{ $form['symbol'] }}</strong> <span class="truncate text-gray-600 dark:text-gray-300">{{ $form['name'] }}</span> <button type="button" wire:click="$set('form.symbol', '')" class="ms-auto text-gray-400 hover:text-gray-700" aria-label="{{ __('Change') }}">✕</button></p>
                    @else
                        <div class="mt-1">@include('portfolio.partials.security-picker', ['id' => 'transaction-search', 'model' => 'form.symbol', 'nameModel' => 'form.name'])</div>
                    @endif
                    <x-input-error :messages="$errors->get('form.symbol')" class="mt-1" />
                </div>
                @if ($form['type'] === 'split')
                    <div class="sm:col-span-2">
                        <x-input-label for="transaction-price" :value="__('Split ratio (new shares per old share)')" />
                        <x-text-input id="transaction-price" wire:model="form.price" inputmode="decimal" class="mt-1 block w-full" placeholder="4" />
                        <x-input-error :messages="$errors->get('form.price')" class="mt-1" />
                    </div>
                @else
                    <div>
                        <x-input-label for="transaction-quantity" :value="$form['type'] === 'dividend' ? __('Shares (optional)') : __('Shares')" />
                        <x-text-input id="transaction-quantity" wire:model="form.quantity" inputmode="decimal" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('form.quantity')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="transaction-price" :value="match ($form['type']) { 'dividend' => __('Dividend per share or total'), 'fee' => __('Amount'), default => __('Price per share') }" />
                        <x-text-input id="transaction-price" wire:model="form.price" inputmode="decimal" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('form.price')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="transaction-fees" :value="__('Fees')" />
                        <x-text-input id="transaction-fees" wire:model="form.fees" inputmode="decimal" class="mt-1 block w-full" placeholder="0" />
                    </div>
                    <div>
                        <x-input-label for="transaction-taxes" :value="__('Taxes')" />
                        <x-text-input id="transaction-taxes" wire:model="form.taxes" inputmode="decimal" class="mt-1 block w-full" placeholder="0" />
                    </div>
                    <div>
                        <x-input-label for="transaction-currency" :value="__('Currency')" />
                        <x-text-input id="transaction-currency" wire:model.live.blur="form.currency" maxlength="3" class="mt-1 block w-full uppercase" />
                        <x-input-error :messages="$errors->get('form.currency')" class="mt-1" />
                    </div>
                    @if (strtoupper($form['currency']) !== 'EUR')
                        <div>
                            <x-input-label for="transaction-rate" :value="__('Exchange rate (:currency per €)', ['currency' => strtoupper($form['currency'])])" />
                            <x-text-input id="transaction-rate" wire:model="form.exchange_rate" inputmode="decimal" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('form.exchange_rate')" class="mt-1" />
                        </div>
                    @endif
                @endif
                <div class="sm:col-span-2">
                    <x-input-label for="transaction-notes" :value="__('Notes')" />
                    <textarea id="transaction-notes" wire:model="form.notes" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"></textarea>
                </div>
            </form>

            <x-slot:footer>
                <x-primary-button form="transaction-form">{{ __('Save') }}</x-primary-button>
                @if ($editingId)
                    <button type="button" wire:click="deleteTransaction({{ $editingId }})" wire:confirm="{{ __('Delete this transaction? The positions are recalculated.') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                @endif
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
