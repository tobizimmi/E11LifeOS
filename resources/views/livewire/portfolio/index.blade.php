<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Support\Portfolio\Positions;
use App\Support\Portfolio\Valuation;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    /** 0 = all portfolios. */
    #[Url(as: 'depot', except: 0)]
    public int $portfolioId = 0;

    #[Url(except: '3m')]
    public string $range = '3m';

    public bool $showPortfolioForm = false;

    public ?int $editingPortfolioId = null;

    /**
     * @var array{name: string, broker: string, description: string, color: string}
     */
    public array $portfolioForm = [];

    public bool $showPositionForm = false;

    public ?int $editingPositionId = null;

    /**
     * @var array{portfolio_id: string, symbol: string, name: string, quantity: string, avg_buy_price: string, labels: string, notes: string, stop_loss_price: string, take_profit_price: string}
     */
    public array $positionForm = [];

    public function mount(): void
    {
        $this->resolveHousehold();

        if ($this->portfolioId && ! $this->portfolios()->whereKey($this->portfolioId)->exists()) {
            $this->portfolioId = 0;
        }
    }

    public function refreshPrices(Valuation $valuation): void
    {
        $portfolios = $this->selectedPortfolios();
        $valuation->rows(PortfolioPosition::whereIn('portfolio_id', $portfolios->modelKeys())->get(), refresh: true);

        foreach ($portfolios as $portfolio) {
            $valuation->snapshot($portfolio, throttleMinutes: 1);
        }

        $this->dispatch('toast', message: __('Prices updated.'));
    }

    // ── Portfolios ───────────────────────────────────────────────────────

    public function newPortfolio(): void
    {
        $this->resetValidation();
        $this->editingPortfolioId = null;
        $this->portfolioForm = ['name' => '', 'broker' => '', 'description' => '', 'color' => '#4f46e5'];
        $this->showPortfolioForm = true;
    }

    public function editPortfolio(int $portfolioId): void
    {
        $portfolio = $this->findPortfolio($portfolioId);
        $this->resetValidation();
        $this->editingPortfolioId = $portfolio->id;
        $this->portfolioForm = ['name' => $portfolio->name, 'broker' => (string) $portfolio->broker, 'description' => (string) $portfolio->description, 'color' => $portfolio->color];
        $this->showPortfolioForm = true;
    }

    public function savePortfolio(): void
    {
        $data = $this->validate([
            'portfolioForm.name' => ['required', 'string', 'max:100'],
            'portfolioForm.broker' => ['nullable', 'string', 'max:50'],
            'portfolioForm.description' => ['nullable', 'string', 'max:1000'],
            'portfolioForm.color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], attributes: ['portfolioForm.name' => __('Name')])['portfolioForm'];

        $data = ['broker' => $data['broker'] ?: null, 'description' => $data['description'] ?: null] + $data;

        if ($this->editingPortfolioId) {
            $this->findPortfolio($this->editingPortfolioId)->update($data);
        } else {
            $this->portfolioId = Portfolio::create(['user_id' => $this->currentUser()->id] + $data)->id;
        }

        $this->showPortfolioForm = false;
    }

    public function deletePortfolio(int $portfolioId): void
    {
        $this->findPortfolio($portfolioId)->delete();
        $this->portfolioId = 0;
        $this->showPortfolioForm = false;
    }

    public function closeForms(): void
    {
        $this->showPortfolioForm = false;
        $this->showPositionForm = false;
    }

    // ── Positions ────────────────────────────────────────────────────────

    public function newPosition(): void
    {
        $this->resetValidation();
        $this->editingPositionId = null;
        $this->positionForm = ['portfolio_id' => (string) ($this->portfolioId ?: $this->portfolios()->value('id')), 'symbol' => '', 'name' => '', 'quantity' => '', 'avg_buy_price' => '', 'fees' => '', 'labels' => '', 'notes' => '', 'stop_loss_price' => '', 'take_profit_price' => ''];
        $this->showPositionForm = true;
    }

    public function editPosition(int $positionId): void
    {
        $position = $this->findPosition($positionId);
        $number = fn (?float $value) => $value !== null ? rtrim(rtrim(number_format($value, 4, ',', ''), '0'), ',') : '';
        $this->resetValidation();
        $this->editingPositionId = $position->id;
        $this->positionForm = [
            'portfolio_id' => (string) $position->portfolio_id,
            'symbol' => $position->symbol,
            'name' => $position->name,
            'quantity' => $number($position->quantity),
            'avg_buy_price' => $number($position->cost_basis_override ?? $position->avg_buy_price),
            'fees' => '',
            'labels' => implode(', ', $position->labels ?? []),
            'notes' => (string) $position->notes,
            'stop_loss_price' => $number($position->stop_loss_price),
            'take_profit_price' => $number($position->take_profit_price),
        ];
        $this->showPositionForm = true;
    }

    public function savePosition(): void
    {
        foreach (['quantity', 'avg_buy_price', 'fees', 'stop_loss_price', 'take_profit_price'] as $field) {
            $this->positionForm[$field] = $this->parseNumber($this->positionForm[$field] ?? '');
        }

        $this->positionForm['symbol'] = strtoupper(trim((string) $this->positionForm['symbol']));
        $position = $this->editingPositionId ? $this->findPosition($this->editingPositionId) : null;
        $data = $this->validate([
            'positionForm.portfolio_id' => ['required', 'integer'],
            'positionForm.symbol' => ['required', 'string', 'max:32'],
            'positionForm.name' => ['nullable', 'string', 'max:255'],
            'positionForm.quantity' => [$position?->manual === false ? 'nullable' : 'required', 'numeric', 'gt:0'],
            'positionForm.avg_buy_price' => [$position?->manual === false ? 'nullable' : 'required', 'numeric', 'min:0'],
            'positionForm.fees' => ['nullable', 'numeric', 'min:0'],
            'positionForm.labels' => ['nullable', 'string', 'max:255'],
            'positionForm.notes' => ['nullable', 'string', 'max:2000'],
            'positionForm.stop_loss_price' => ['nullable', 'numeric', 'min:0'],
            'positionForm.take_profit_price' => ['nullable', 'numeric', 'min:0'],
        ], attributes: ['positionForm.symbol' => __('Security'), 'positionForm.quantity' => __('Shares'), 'positionForm.avg_buy_price' => __('Average purchase price'), 'positionForm.fees' => __('Purchase fees')])['positionForm'];

        $portfolio = $this->findPortfolio((int) $data['portfolio_id']);
        $attributes = [
            'labels' => array_values(array_filter(array_map('trim', explode(',', (string) $data['labels'])))) ?: null,
            'notes' => $data['notes'] ?: null,
            'stop_loss_price' => $data['stop_loss_price'] !== '' ? (float) $data['stop_loss_price'] : null,
            'take_profit_price' => $data['take_profit_price'] !== '' ? (float) $data['take_profit_price'] : null,
        ];

        // Positions from transactions only take the extras; quantity follows the transactions. The
        // purchase price, however, can be corrected by hand (a persistent override), because an
        // import often delivers no reliable one – the override survives the next recalculation.
        if ($position && ! $position->manual) {
            $position->update($attributes + [
                'cost_basis_override' => $data['avg_buy_price'] !== '' && (float) $position->quantity > 0
                    ? round((float) $data['avg_buy_price'] + (float) ($data['fees'] ?: 0) / (float) $position->quantity, 4)
                    : null,
                // The member has just reviewed the purchase price, so clear the "please check" flag.
                'cost_basis_override_stale' => false,
            ]);
        } else {
            if (! $position && $portfolio->positions()->where('symbol', $data['symbol'])->exists()) {
                $this->addError('positionForm.symbol', __('This security is already in the portfolio – add a transaction instead.'));

                return;
            }

            ($position ?? new PortfolioPosition(['manual' => true]))->fill([
                'portfolio_id' => $portfolio->id,
                'symbol' => $data['symbol'],
                'name' => $data['name'] ?: $data['symbol'],
                'quantity' => (float) $data['quantity'],
                // Fees are part of the purchase price, as with transactions.
                'avg_buy_price' => round((float) $data['avg_buy_price'] + (float) ($data['fees'] ?: 0) / (float) $data['quantity'], 4),
            ] + $attributes)->save();
        }

        $this->showPositionForm = false;
    }

    public function setupGainAlerts(int $positionId): void
    {
        $created = $this->createGainAlerts($this->findPosition($positionId));

        $this->dispatch('toast', message: $created > 0
            ? trans_choice(':count profit alert set up.|:count profit alerts set up.', $created, ['count' => $created])
            : __('Profit alerts are already set up for this security.'));
    }

    public function deletePosition(int $positionId): void
    {
        $position = $this->findPosition($positionId);
        abort_unless($position->manual, 422);
        $position->delete();
        $this->showPositionForm = false;
    }

    private function findPosition(int $positionId): PortfolioPosition
    {
        return PortfolioPosition::whereIn('portfolio_id', $this->portfolios()->select('id'))->find($positionId) ?? abort(404);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Portfolio>
     */
    private function selectedPortfolios()
    {
        return $this->portfolios()->when($this->portfolioId, fn ($query) => $query->whereKey($this->portfolioId))->get();
    }

    public function with(Valuation $valuation): array
    {
        $portfolios = $this->portfolios()->orderBy('id')->get();
        $selected = $this->portfolioId ? $portfolios->where('id', $this->portfolioId) : $portfolios;
        $rows = $valuation->rows(PortfolioPosition::whereIn('portfolio_id', $selected->modelKeys())->with('portfolio')->get());
        $from = match ($this->range) {
            '1w' => now()->subWeek(),
            '1m' => now()->subMonth(),
            '1y' => now()->subYear(),
            'max' => now()->subYears(50),
            default => now()->subMonths(3),
        };
        $chart = collect($valuation->chart($selected->modelKeys(), $from, intraday: $this->range === '1w'))
            ->map(fn (array $point) => [
                'label' => $this->range === '1w' ? \Illuminate\Support\Carbon::parse($point['at'])->format('d.m. H:i') : \Illuminate\Support\Carbon::parse($point['at'])->format('d.m.y'),
                'value' => $point['value'],
                'compare' => $point['cost'],
            ])->all();

        return [
            'portfolioList' => $portfolios,
            'rows' => $rows,
            'summary' => $valuation->summary($rows),
            'chart' => $chart,
            'perPortfolio' => $this->portfolioId ? [] : collect($rows)->groupBy(fn (array $row) => $row['position']->portfolio_id)->map(fn ($group) => $valuation->summary($group->all()))->all(),
        ];
    }
}; ?>

@php
    $euro = fn ($value, $decimals = 2) => number_format((float) $value, $decimals, ',', '.').' €';
    $signed = fn ($value, $suffix = ' €') => ($value > 0 ? '+' : ($value < 0 ? '−' : '±')).number_format(abs((float) $value), 2, ',', '.').$suffix;
    $tone = fn ($value) => $value > 0 ? 'text-emerald-700 dark:text-emerald-400' : ($value < 0 ? 'text-orange-700 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400');
    $quantity = fn ($value) => rtrim(rtrim(number_format((float) $value, 6, ',', '.'), '0'), ',');
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => 'portfolio.index'])

            @if (session('portfolioReconciled'))
                <div class="flex items-start gap-2 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100" role="status">
                    <span aria-hidden="true">✅</span>
                    <span>{{ session('portfolioReconciled') }}</span>
                </div>
            @endif

            @if ($portfolioList->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center shadow-xs dark:bg-gray-800">
                    <p class="text-4xl" aria-hidden="true">📈</p>
                    <h3 class="mt-2 font-semibold text-gray-900 dark:text-gray-100">{{ __('Your portfolio manager') }}</h3>
                    <p class="mx-auto mt-1 max-w-lg text-sm text-gray-600 dark:text-gray-400">{{ __('Create a portfolio for each securities account, then import the transactions from your broker or enter them by hand. Prices update automatically.') }}</p>
                    <x-primary-button type="button" wire:click="newPortfolio" class="mt-4">＋ {{ __('Create portfolio') }}</x-primary-button>
                </div>
            @else
                {{-- Portfolio switcher --}}
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex flex-wrap gap-1.5" role="tablist">
                        <button type="button" wire:click="$set('portfolioId', 0)" @class(['rounded-full px-3 py-1 text-sm', 'bg-indigo-600 text-white dark:bg-indigo-500' => ! $portfolioId, 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $portfolioId])>{{ __('All portfolios') }}</button>
                        @foreach ($portfolioList as $portfolio)
                            <button type="button" wire:click="$set('portfolioId', {{ $portfolio->id }})" wire:key="depot-{{ $portfolio->id }}" @class(['flex items-center gap-1.5 rounded-full px-3 py-1 text-sm', 'bg-indigo-600 text-white dark:bg-indigo-500' => $portfolioId === $portfolio->id, 'bg-white text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700' => $portfolioId !== $portfolio->id])>
                                <span class="h-2 w-2 rounded-full" style="background: {{ $portfolio->color }}" aria-hidden="true"></span>{{ $portfolio->name }}
                            </button>
                        @endforeach
                        <button type="button" wire:click="newPortfolio" class="rounded-full px-3 py-1 text-sm text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-gray-800">＋ {{ __('Portfolio') }}</button>
                    </div>
                    <div class="ms-auto flex gap-2">
                        @if ($portfolioId)
                            <button type="button" wire:click="editPortfolio({{ $portfolioId }})" class="rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-white dark:text-gray-300 dark:hover:bg-gray-800">✎ {{ __('Edit') }}</button>
                        @endif
                        <button type="button" wire:click="refreshPrices" wire:loading.attr="disabled" class="rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-white dark:text-gray-300 dark:hover:bg-gray-800"><span wire:loading.remove wire:target="refreshPrices">↻</span><span wire:loading wire:target="refreshPrices">…</span> {{ __('Update prices') }}</button>
                    </div>
                </div>

                {{-- KPIs --}}
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Portfolio value') }}</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums whitespace-nowrap sm:text-2xl text-gray-900 dark:text-gray-100">{{ $euro($summary['value']) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count position|:count positions', $summary['count']) }}</p>
                    </div>
                    <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Today') }}</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums whitespace-nowrap sm:text-2xl {{ $tone($summary['day_change']) }}">{{ $signed($summary['day_change']) }}</p>
                        <p class="text-xs tabular-nums {{ $tone($summary['day_change']) }}">{{ $summary['day_change_percent'] !== null ? $signed($summary['day_change_percent'], ' %') : '—' }}</p>
                    </div>
                    <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Gain / loss') }}</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums whitespace-nowrap sm:text-2xl {{ $tone($summary['gain']) }}">{{ $signed($summary['gain']) }}</p>
                        <p class="text-xs tabular-nums {{ $tone($summary['gain']) }}">{{ $summary['gain_percent'] !== null ? $signed($summary['gain_percent'], ' %') : '—' }}</p>
                    </div>
                    <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('Invested') }}</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums whitespace-nowrap sm:text-2xl text-gray-900 dark:text-gray-100">{{ $euro($summary['cost']) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Purchase value incl. fees') }}</p>
                    </div>
                </div>

                {{-- Value chart --}}
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Value over time') }}</h3>
                        <div class="inline-flex rounded-lg bg-gray-100 p-0.5 text-xs dark:bg-gray-900">
                            @foreach (['1w' => __('1W'), '1m' => __('1M'), '3m' => __('3M'), '1y' => __('1Y'), 'max' => __('Max')] as $key => $label)
                                <button type="button" wire:click="$set('range', '{{ $key }}')" @class(['rounded-md px-2.5 py-1', 'bg-white font-medium text-gray-900 shadow-xs dark:bg-gray-700 dark:text-gray-100' => $range === $key, 'text-gray-600 dark:text-gray-400' => $range !== $key])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <x-line-chart :points="$chart" :value-label="__('Value')" :compare-label="__('Invested')" wire:key="chart-{{ $portfolioId }}-{{ $range }}" />
                    @if (count($chart) < 2)
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('The value is recorded several times a day from now on.') }}</p>
                    @endif
                </section>

                @if ($perPortfolio && $portfolioList->count() > 1)
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($portfolioList as $portfolio)
                            @php
                                $depot = $perPortfolio[$portfolio->id] ?? null;
                            @endphp
                            <button type="button" wire:click="$set('portfolioId', {{ $portfolio->id }})" wire:key="depot-card-{{ $portfolio->id }}" class="rounded-2xl bg-white p-4 text-start shadow-xs hover:ring-1 hover:ring-indigo-200 dark:bg-gray-800 dark:hover:ring-indigo-800">
                                <p class="flex items-center gap-1.5 text-sm font-medium text-gray-900 dark:text-gray-100"><span class="h-2.5 w-2.5 rounded-full" style="background: {{ $portfolio->color }}" aria-hidden="true"></span>{{ $portfolio->name }}</p>
                                <p class="mt-1 text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($depot['value'] ?? 0) }}</p>
                                <p class="text-xs tabular-nums {{ $tone($depot['gain'] ?? 0) }}">{{ $signed($depot['gain'] ?? 0) }}{{ isset($depot['gain_percent']) ? ' ('.$signed($depot['gain_percent'], ' %').')' : '' }}</p>
                            </button>
                        @endforeach
                    </div>
                @endif

                {{-- Positions --}}
                <section class="overflow-hidden rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                    <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Positions') }}</h3>
                        <div class="flex gap-2">
                            <button type="button" wire:click="newPosition" class="rounded-lg px-3 py-1.5 text-sm text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700">＋ {{ __('Position') }}</button>
                            <a href="{{ route('portfolio.transactions', ['new' => 1, 'depot' => $portfolioId ?: null]) }}" wire:navigate class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">＋ {{ __('Transaction') }}</a>
                        </div>
                    </div>
                    @if ($rows === [])
                        <p class="border-t border-gray-100 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ __('No positions yet. Add a transaction or import your broker export.') }} <a href="{{ route('portfolio.import') }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-300">{{ __('Import') }}</a></p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[44rem] text-sm">
                                <thead class="border-y border-gray-100 bg-gray-50 text-left text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-400">
                                    <tr>
                                        <th class="px-4 py-2 font-medium">{{ __('Security') }}</th>
                                        <th class="px-3 py-2 text-end font-medium">{{ __('Shares') }}</th>
                                        <th class="px-3 py-2 text-end font-medium">{{ __('Price') }}</th>
                                        <th class="px-3 py-2 text-end font-medium">{{ __('Today') }}</th>
                                        <th class="px-3 py-2 text-end font-medium">{{ __('Value') }}</th>
                                        <th class="px-3 py-2 text-end font-medium">{{ __('Gain / loss') }}</th>
                                        <th class="px-3 py-2 text-end font-medium">{{ __('Weight') }}</th>
                                        <th class="w-8"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @foreach ($rows as $row)
                                        @php
                                            $position = $row['position'];
                                        @endphp
                                        <tr wire:key="position-{{ $position->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                            <td class="max-w-[16rem] px-4 py-2.5">
                                                <a href="{{ route('portfolio.security', ['symbol' => $position->symbol]) }}" wire:navigate class="block">
                                                    <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $position->name ?: $position->symbol }}</span>
                                                    <span class="flex flex-wrap items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                        {{ $position->symbol }}
                                                        @if (! $portfolioId)
                                                            · <span class="inline-block h-1.5 w-1.5 rounded-full" style="background: {{ $position->portfolio->color }}" aria-hidden="true"></span>{{ $position->portfolio->name }}
                                                        @endif
                                                        @foreach ($position->labels ?? [] as $label)
                                                            <span class="rounded-sm bg-gray-100 px-1 dark:bg-gray-700">{{ $label }}</span>
                                                        @endforeach
                                                        @if ($row['price'] !== null && $position->stop_loss_price && $row['price'] <= $position->stop_loss_price)
                                                            <span class="rounded-sm bg-orange-100 px-1 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200">⚠ {{ __('Stop loss') }}</span>
                                                        @elseif ($row['price'] !== null && $position->take_profit_price && $row['price'] >= $position->take_profit_price)
                                                            <span class="rounded-sm bg-emerald-100 px-1 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">🎯 {{ __('Target reached') }}</span>
                                                        @endif
                                                        @if ($position->overrideNeedsReview())
                                                            <span class="rounded-sm bg-amber-100 px-1 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200" title="{{ __('The quantity changed on the last import – check the purchase price.') }}">⚠ {{ __('Check purchase price') }}</span>
                                                        @elseif ($position->needsManualCost())
                                                            <span class="rounded-sm bg-amber-100 px-1 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200" title="{{ __('No reliable purchase price from the import – tap the pencil to enter it.') }}">⚠ {{ __('Set purchase price') }}</span>
                                                        @elseif ($position->cost_basis_override !== null)
                                                            <span class="rounded-sm bg-gray-100 px-1 text-gray-600 dark:bg-gray-700 dark:text-gray-300" title="{{ __('Purchase price entered by hand') }}">✎ {{ __('man. cost') }}</span>
                                                        @endif
                                                    </span>
                                                </a>
                                            </td>
                                            <td class="px-3 py-2.5 text-end tabular-nums text-gray-700 dark:text-gray-300">{{ $quantity($position->quantity) }}</td>
                                            <td class="px-3 py-2.5 text-end tabular-nums text-gray-700 dark:text-gray-300">{{ $row['price'] !== null ? $euro($row['price']) : '—' }}</td>
                                            <td class="px-3 py-2.5 text-end tabular-nums {{ $tone($row['day_change_percent'] ?? 0) }}">{{ $row['day_change_percent'] !== null ? $signed($row['day_change_percent'], ' %') : '—' }}</td>
                                            <td class="px-3 py-2.5 text-end font-medium tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($row['value']) }}</td>
                                            <td class="px-3 py-2.5 text-end tabular-nums {{ $tone($row['gain']) }}">
                                                {{ $signed($row['gain']) }}
                                                <span class="block text-xs">{{ $row['gain_percent'] !== null ? $signed($row['gain_percent'], ' %') : '' }}</span>
                                            </td>
                                            <td class="px-3 py-2.5 text-end tabular-nums text-gray-500 dark:text-gray-400">{{ number_format($row['weight'], 1, ',', '.') }} %</td>
                                            <td class="pe-2">
                                                <button type="button" wire:click="editPosition({{ $position->id }})" class="rounded-sm p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="h-5 w-5" /></button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endif
        </div>
    </div>

    @if ($showPortfolioForm)
        <x-sheet close="closeForms" :label="$editingPortfolioId ? __('Edit portfolio') : __('New portfolio')" wire:key="portfolio-form-{{ $editingPortfolioId ?? 'new' }}">
            <x-slot:header><strong class="text-gray-800 dark:text-gray-100">📁 {{ $editingPortfolioId ? __('Edit portfolio') : __('New portfolio') }}</strong></x-slot:header>

            <form id="portfolio-form" wire:submit="savePortfolio" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="portfolio-name" :value="__('Name')" />
                    <x-text-input id="portfolio-name" wire:model="portfolioForm.name" class="mt-1 block w-full" placeholder="{{ __('e.g. Trade Republic') }}" />
                    <x-input-error :messages="$errors->get('portfolioForm.name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="portfolio-broker" :value="__('Broker (optional)')" />
                    <x-text-input id="portfolio-broker" wire:model="portfolioForm.broker" class="mt-1 block w-full" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="portfolio-description" :value="__('Description (optional)')" />
                    <textarea id="portfolio-description" wire:model="portfolioForm.description" rows="2" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"></textarea>
                </div>
                <div>
                    <x-input-label for="portfolio-color" :value="__('Colour')" />
                    <input id="portfolio-color" type="color" wire:model="portfolioForm.color" class="mt-1 h-10 w-20 rounded-sm border-gray-300 dark:border-gray-600">
                </div>
            </form>

            <x-slot:footer>
                <x-primary-button form="portfolio-form">{{ __('Save') }}</x-primary-button>
                @if ($editingPortfolioId)
                    <button type="button" wire:click="deletePortfolio({{ $editingPortfolioId }})" wire:confirm="{{ __('Delete this portfolio with all positions and transactions?') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                @endif
            </x-slot:footer>
        </x-sheet>
    @endif

    @if ($showPositionForm)
        @php
            $editingPosition = $editingPositionId ? \App\Models\PortfolioPosition::find($editingPositionId) : null;
        @endphp
        <x-sheet close="closeForms" :label="$editingPositionId ? __('Edit position') : __('New position')" wire:key="position-form-{{ $editingPositionId ?? 'new' }}">
            <x-slot:header><strong class="text-gray-800 dark:text-gray-100">📌 {{ $editingPositionId ? ($positionForm['name'] ?: $positionForm['symbol']) : __('New position') }}</strong></x-slot:header>

            <form id="position-form" wire:submit="savePosition" class="grid gap-4 sm:grid-cols-2">
                @if ($editingPosition && ! $editingPosition->manual)
                    @if ($editingPosition->overrideNeedsReview())
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 sm:col-span-2 dark:bg-amber-900/30 dark:text-amber-100">⚠️ {{ __('The quantity changed on the last import, so your purchase price may no longer fit. Please check it and re-enter it if needed.') }}</p>
                    @elseif ($editingPosition->needsManualCost())
                        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 sm:col-span-2 dark:bg-amber-900/30 dark:text-amber-100">⚠️ {{ __('The bank delivered no reliable purchase price for this holding, so the current price is used and no gain is shown. Enter your real purchase price below.') }}</p>
                    @else
                        <p class="rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-900 sm:col-span-2 dark:bg-sky-900/30 dark:text-sky-100">{{ __('Quantity comes from the transactions. You can correct the purchase price by hand here (it is kept on the next sync) and add labels, notes and price marks.') }}</p>
                    @endif
                    <div class="sm:col-span-2">
                        <x-input-label for="position-cost-override" :value="__('Purchase price per share (€)')" />
                        <x-text-input id="position-cost-override" wire:model="positionForm.avg_buy_price" inputmode="decimal" class="mt-1 block w-full" placeholder="{{ __('e.g. 479,16') }}" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Leave empty to keep the value from the import. Order fees are added on top.') }}</p>
                        <x-input-error :messages="$errors->get('positionForm.avg_buy_price')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="position-cost-fees" :value="__('Purchase fees (€)')" />
                        <x-text-input id="position-cost-fees" wire:model="positionForm.fees" inputmode="decimal" class="mt-1 block w-full" placeholder="0" />
                        <x-input-error :messages="$errors->get('positionForm.fees')" class="mt-1" />
                    </div>
                @else
                    <p class="text-sm text-gray-600 sm:col-span-2 dark:text-gray-400">{{ __('For holdings without transaction history. Better: add a purchase as transaction – then gains, dividends and taxes can be calculated.') }}</p>
                    <div>
                        <x-input-label for="position-portfolio" :value="__('Portfolio')" />
                        <select id="position-portfolio" wire:model="positionForm.portfolio_id" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            @foreach ($portfolioList as $portfolio)
                                <option value="{{ $portfolio->id }}">{{ $portfolio->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="position-search" :value="__('Security')" />
                        @if ($positionForm['symbol'])
                            <p class="mt-1 flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-gray-900/40"><strong>{{ $positionForm['symbol'] }}</strong> <span class="truncate text-gray-600 dark:text-gray-300">{{ $positionForm['name'] }}</span> <button type="button" wire:click="$set('positionForm.symbol', '')" class="ms-auto text-gray-400 hover:text-gray-700" aria-label="{{ __('Change') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button></p>
                        @else
                            <div class="mt-1">@include('portfolio.partials.security-picker', ['id' => 'position-search', 'model' => 'positionForm.symbol', 'nameModel' => 'positionForm.name'])</div>
                        @endif
                        <x-input-error :messages="$errors->get('positionForm.symbol')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="position-quantity" :value="__('Shares')" />
                        <x-text-input id="position-quantity" wire:model="positionForm.quantity" inputmode="decimal" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('positionForm.quantity')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="position-price" :value="__('Average purchase price (€)')" />
                        <x-text-input id="position-price" wire:model="positionForm.avg_buy_price" inputmode="decimal" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('positionForm.avg_buy_price')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="position-fees" :value="$editingPositionId ? __('Further purchase fees (€)') : __('Purchase fees (€)')" />
                        <x-text-input id="position-fees" wire:model="positionForm.fees" inputmode="decimal" class="mt-1 block w-full" placeholder="0" />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Order fees and commissions are added to the average purchase price.') }}</p>
                        <x-input-error :messages="$errors->get('positionForm.fees')" class="mt-1" />
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <x-input-label for="position-labels" :value="__('Labels (comma separated)')" />
                    <x-text-input id="position-labels" wire:model="positionForm.labels" class="mt-1 block w-full" placeholder="{{ __('e.g. Dividend, Core') }}" />
                </div>
                <div>
                    <x-input-label for="position-stop" :value="__('Stop loss (€)')" />
                    <x-text-input id="position-stop" wire:model="positionForm.stop_loss_price" inputmode="decimal" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('positionForm.stop_loss_price')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="position-target" :value="__('Take profit (€)')" />
                    <x-text-input id="position-target" wire:model="positionForm.take_profit_price" inputmode="decimal" class="mt-1 block w-full" />
                    <x-input-error :messages="$errors->get('positionForm.take_profit_price')" class="mt-1" />
                </div>
                @if ($editingPosition && $editingPosition->avg_buy_price > 0)
                    @php
                        $gainCurrency = $editingPosition->currency ?: 'EUR';
                    @endphp
                    <div class="sm:col-span-2 rounded-xl bg-emerald-50/60 p-3 dark:bg-emerald-900/20">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('Profit alerts') }}</p>
                        <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-300">{{ __('Get notified when this security rises :steps above your average buy price of :price.', ['steps' => '+5 / 10 / 20 / 30 %', 'price' => number_format($editingPosition->avg_buy_price, 2, ',', '.').' '.$gainCurrency]) }}</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach (\App\Models\PriceAlert::GAIN_STEPS as $step)
                                <span class="rounded-full bg-white px-2 py-0.5 text-xs tabular-nums text-emerald-800 ring-1 ring-emerald-200 dark:bg-gray-800 dark:text-emerald-200 dark:ring-emerald-800">+{{ $step }} % · {{ number_format(round($editingPosition->avg_buy_price * (1 + $step / 100), 2), 2, ',', '.') }} {{ $gainCurrency }}</span>
                            @endforeach
                        </div>
                        <button type="button" wire:click="setupGainAlerts({{ $editingPositionId }})" wire:loading.attr="disabled" wire:target="setupGainAlerts" class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-emerald-700 active:scale-[0.98] disabled:opacity-60 dark:bg-emerald-500 dark:hover:bg-emerald-600">
                            📈 {{ __('Set up profit alerts') }}
                        </button>
                        <a href="{{ route('portfolio.alerts') }}" wire:navigate class="ms-2 text-xs text-indigo-600 hover:underline dark:text-indigo-300">{{ __('Manage alerts') }}</a>
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <x-input-label for="position-notes" :value="__('Notes')" />
                    <textarea id="position-notes" wire:model="positionForm.notes" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"></textarea>
                </div>
            </form>

            <x-slot:footer>
                <x-primary-button form="position-form">{{ __('Save') }}</x-primary-button>
                @if ($editingPosition && $editingPosition->manual)
                    <button type="button" wire:click="deletePosition({{ $editingPositionId }})" wire:confirm="{{ __('Delete this position?') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-gray-700">{{ __('Delete') }}</button>
                @endif
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
