<?php

use App\Models\CashflowEntry;
use App\Support\Addons;
use App\Support\Finance\CashflowStatement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Income statement and balance sheet after "Rich Dad Poor Dad": where the money comes from,
 * where it goes, which assets pay you and how far away financial freedom is. Lines from the
 * modules can be completed (or replaced) by the member's own income, expenses, assets and debts.
 */
new #[Layout('layouts.app')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    /**
     * @var array{kind: string, category: string, label: string, amount: string, value: string, productive: bool}
     */
    public array $entry = ['kind' => 'income', 'category' => 'earned', 'label' => '', 'amount' => '', 'value' => '', 'productive' => true];

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user->canUseAddon(Addons::FINANCE) || $user->canUseAddon(Addons::PORTFOLIO) || $user->canUseAddon(Addons::REAL_ESTATE), 403);
    }

    public function add(string $kind): void
    {
        abort_unless(isset(CashflowEntry::categories()[$kind]), 404);

        $this->resetValidation();
        $this->editingId = null;
        $this->entry = ['kind' => $kind, 'category' => array_key_first(CashflowEntry::categories()[$kind]), 'label' => '', 'amount' => '', 'value' => '', 'productive' => $kind !== CashflowEntry::LIABILITY];
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $entry = $this->findEntry($id);
        $this->resetValidation();
        $this->editingId = $entry->id;
        $this->entry = ['kind' => $entry->kind, 'category' => $entry->category, 'label' => $entry->label, 'amount' => $entry->amount ? (string) $entry->amount : '', 'value' => $entry->value ? (string) $entry->value : '', 'productive' => $entry->productive];
        $this->showForm = true;
    }

    private static function number(string $value): string
    {
        $value = trim(str_replace(['€', ' '], '', $value));

        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
            return str_replace('.', '', $value);
        }

        return str_contains($value, ',') ? str_replace(',', '.', str_replace('.', '', $value)) : $value;
    }

    public function save(): void
    {
        $this->entry['amount'] = self::number((string) $this->entry['amount']);
        $this->entry['value'] = self::number((string) $this->entry['value']);
        $kind = $this->entry['kind'];
        $isBalance = in_array($kind, [CashflowEntry::ASSET, CashflowEntry::LIABILITY], true);

        $data = $this->validate([
            'entry.kind' => ['required', Rule::in(array_keys(CashflowEntry::categories()))],
            'entry.category' => ['required', Rule::in(array_keys(CashflowEntry::categories()[$kind] ?? []))],
            'entry.label' => ['required', 'string', 'max:255'],
            'entry.amount' => [$isBalance ? 'nullable' : 'required', 'numeric', 'min:0', 'max:10000000'],
            'entry.value' => [$isBalance ? 'required' : 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'entry.productive' => ['boolean'],
        ], attributes: ['entry.label' => __('Name'), 'entry.amount' => __('Amount per month (€)'), 'entry.value' => __('Value (€)')])['entry'];

        $attributes = [
            'kind' => $data['kind'],
            'category' => $data['category'],
            'label' => $data['label'],
            'amount' => (float) ($data['amount'] ?: 0),
            'value' => $isBalance ? (float) $data['value'] : 0,
            'productive' => $isBalance ? (bool) $data['productive'] : true,
        ];

        if ($this->editingId) {
            $this->findEntry($this->editingId)->update($attributes);
        } else {
            CashflowEntry::create($attributes + ['user_id' => Auth::id(), 'position' => (int) CashflowEntry::where('user_id', Auth::id())->max('position') + 1]);
        }

        $this->showForm = false;
        $this->editingId = null;
        $this->dispatch('toast', message: __('Saved.'));
    }

    public function delete(int $id): void
    {
        $this->findEntry($id)->delete();
        $this->showForm = false;
        $this->editingId = null;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
    }

    public function toggleManualOnly(): void
    {
        $user = Auth::user();
        $user->update(['cashflow_manual_only' => ! $user->cashflow_manual_only]);
    }

    private function findEntry(int $id): CashflowEntry
    {
        return CashflowEntry::where('user_id', Auth::id())->find($id) ?? abort(404);
    }

    public function with(): array
    {
        return ['statement' => CashflowStatement::for(Auth::user()), 'history' => CashflowStatement::history(Auth::user()), 'categories' => CashflowEntry::categories()];
    }
}; ?>

@php
    $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
    $round = fn (float $value) => number_format($value, 0, ',', '.').' €';
    $freedomPercent = (int) round(min(1, $statement['freedom']) * 100);
    $patterns = [
        'poor' => ['icon' => '🔄', 'title' => __('Pattern “poor”: income goes straight into expenses'), 'text' => __('Salary comes in and is spent – nothing is left to buy assets. The first step is a surplus you invest every month.')],
        'middle' => ['icon' => '🏠', 'title' => __('Pattern “middle class”: income flows into liabilities'), 'text' => __('A large part of the money services loans for things that do not pay you. Assets that bring in money are still small compared with the debts.')],
        'building' => ['icon' => '🌱', 'title' => __('On the way: your assets already pay you'), 'text' => __('You own assets that generate income. Keep buying assets with every surplus until their income covers your expenses.')],
        'rich' => ['icon' => '🏆', 'title' => __('Pattern “rich”: your assets cover your expenses'), 'text' => __('The income from your assets pays for your living costs – work has become optional. Congratulations!')],
    ];
    $pattern = $patterns[$statement['pattern']];
    $kindLabels = ['earned' => __('earned'), 'self_employed' => __('self-employed'), 'business' => __('business'), 'portfolio' => __('portfolio'), 'passive' => __('passive'), 'other' => __('other')];
    $incomeGroups = ['earned' => __('Earned income'), 'self_employed' => __('Self-employed income'), 'business' => __('Business income'), 'portfolio' => __('Portfolio income'), 'passive' => __('Passive income'), 'other' => __('Other income')];
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $fromAssets = collect($statement['income']['rows'])->whereIn('kind', ['portfolio', 'passive', 'business'])->sum('value');
    $earnedFlow = collect($statement['income']['rows'])->whereIn('kind', ['earned', 'self_employed'])->sum('value');
    $otherFlow = collect($statement['income']['rows'])->where('kind', 'other')->sum('value');
    $fit = fn (float $value) => number_format($value, 0, ',', '.').' €';
    $steps = [];

    if ($statement['months'] < 3 || $statement['expenses']['total'] <= 0) {
        $steps[] = ['icon' => '🏦', 'text' => __('Import at least three months of bank transactions so income and expenses are reliable.'), 'url' => route('finance.index')];
    }
    if ($statement['cashflow'] > 0) {
        $steps[] = ['icon' => '💰', 'text' => __('Pay yourself first: move your surplus of :amount per month into assets right at the start of the month.', ['amount' => $round($statement['cashflow'])]), 'url' => route('knowledge.index', ['category' => 'wealth'])];
    } elseif ($statement['income']['total'] > 0) {
        $steps[] = ['icon' => '✂️', 'text' => __('You spend more than you earn. Look at the biggest expense categories and cancel what you do not need.'), 'url' => route('contracts.index')];
    }
    if ($statement['income']['portfolio'] <= 0) {
        $steps[] = ['icon' => '📈', 'text' => __('No dividends yet: a broadly diversified ETF savings plan is the simplest start for portfolio income.'), 'url' => route('knowledge.index', ['category' => 'stocks'])];
    }
    if ($statement['doodads']['total'] > 0) {
        $steps[] = ['icon' => '🏡', 'text' => __('Kiyosaki counts the home you live in as a liability: it costs you money every month. Worth having – but it does not make you free.'), 'url' => null];
    }
    if ($statement['liabilities']['total'] > $statement['assets']['total'] && $statement['liabilities']['total'] > 0) {
        $steps[] = ['icon' => '📉', 'text' => __('Your debts exceed your assets. Repay expensive consumer loans first, then build assets.'), 'url' => route('knowledge.index', ['category' => 'financing'])];
    }
    if ($statement['gap'] > 0) {
        $steps[] = ['icon' => '🎯', 'text' => __('At a 4 % yield, about :capital in assets would close the remaining gap of :gap per month.', ['capital' => $round($statement['gap'] * 12 / 0.04), 'gap' => $round($statement['gap'])]), 'url' => null];
    }
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🧭 {{ __('Cashflow analysis') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-6xl space-y-5 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="max-w-xl">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">💰 {{ __('My financial report after “Rich Dad Poor Dad”') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('“An asset puts money in your pocket. A liability takes money out of your pocket.” – all amounts per month, balance sheet values as of today. Only visible to you.') }}</p>
                    <a href="{{ route('knowledge.index', ['category' => 'richdad']) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">📚 {{ __('More from the Rich Dad books') }} →</a>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach (['income' => __('Income (entry)'), 'expense' => __('Expense'), 'asset' => __('Asset'), 'liability' => __('Liability')] as $kind => $label)
                        <button type="button" wire:click="add('{{ $kind }}')" class="rounded-full bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 pointer-coarse:min-h-11 dark:bg-indigo-500 dark:hover:bg-indigo-400">+ {{ $label }}</button>
                    @endforeach
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                <input type="checkbox" wire:click="toggleManualOnly" @checked($statement['manual_only']) class="rounded border-gray-300 text-indigo-600">
                {{ __('Only use my own entries (ignore bank transactions, portfolio and properties)') }}
            </label>

            {{-- Hamster wheel + flow diagram --}}
            <section class="grid gap-5 rounded-2xl bg-white p-5 shadow-sm lg:grid-cols-2 dark:bg-gray-800">
                <div class="flex flex-col justify-center border-l-4 border-indigo-500 pl-4">
                    @if ($statement['expenses']['total'] <= 0)
                        <h4 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('No expenses entered yet') }}</h4>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('Enter your monthly expenses (housing, living, insurance …) – only then can the cashflow pattern and your progress out of the rat race be calculated.') }}</p>
                    @else
                        <h4 class="font-semibold text-gray-900 dark:text-gray-100">{{ $pattern['icon'] }} {{ $pattern['title'] }}</h4>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $pattern['text'] }}</p>
                    @endif
                    <div class="mt-4 flex items-baseline justify-between text-sm">
                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ __('Out of the rat race') }}</span>
                        <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $statement['expenses']['total'] > 0 ? $freedomPercent.' %' : '–' }}</span>
                    </div>
                    <div class="mt-1 h-3 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $freedomPercent }}" aria-label="{{ __('Out of the rat race') }}">
                        <div class="h-full rounded-full bg-emerald-600 dark:bg-emerald-500" style="width: {{ $freedomPercent }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Passive + portfolio income: :amount per month.', ['amount' => $euro($statement['asset_income'])]) }}@if ($statement['gap'] > 0) {{ __('Still missing: :gap asset income per month.', ['gap' => $round($statement['gap'])]) }}@endif</p>
                </div>

                <figure class="text-gray-900 dark:text-gray-100" aria-label="{{ __('Cashflow diagram') }}">
                    <svg viewBox="0 0 440 300" class="h-auto w-full" role="img">
                        <title>{{ __('Cashflow diagram') }}</title>
                        <defs>
                            <marker id="cf-arrow-blue" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M0 0 10 5 0 10z" class="fill-indigo-600 dark:fill-indigo-400" /></marker>
                            <marker id="cf-arrow-green" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M0 0 10 5 0 10z" class="fill-emerald-600 dark:fill-emerald-400" /></marker>
                            <marker id="cf-arrow-red" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M0 0 10 5 0 10z" class="fill-rose-500 dark:fill-rose-400" /></marker>
                        </defs>
                        {{-- work into income --}}
                        <line x1="230" y1="4" x2="230" y2="44" stroke-width="6" class="stroke-indigo-600 dark:stroke-indigo-400" marker-end="url(#cf-arrow-blue)" />
                        <text x="214" y="16" text-anchor="end" class="fill-gray-500 text-[10px] dark:fill-gray-400">{{ __('Work') }}</text>
                        <text x="214" y="30" text-anchor="end" class="fill-indigo-700 text-[11px] font-semibold dark:fill-indigo-300">{{ $fit($earnedFlow) }}</text>
                        @if ($otherFlow > 0)
                            <text x="242" y="16" class="fill-gray-500 text-[10px] dark:fill-gray-400">{{ __('other') }}</text>
                            <text x="242" y="30" class="fill-emerald-700 text-[11px] font-semibold dark:fill-emerald-300">+{{ $fit($otherFlow) }}</text>
                        @endif
                        {{-- income statement --}}
                        <rect x="130" y="50" width="200" height="104" rx="8" class="fill-white stroke-gray-300 dark:fill-gray-900 dark:stroke-gray-600" />
                        <line x1="130" y1="102" x2="330" y2="102" class="stroke-gray-200 dark:stroke-gray-700" />
                        <text x="142" y="68" class="fill-gray-500 text-[9px] font-semibold tracking-wider dark:fill-gray-400">{{ mb_strtoupper(__('Income (statement)')) }}</text>
                        <text x="142" y="90" class="fill-gray-900 text-[17px] font-bold dark:fill-gray-100">{{ $fit($statement['income']['total']) }}</text>
                        <text x="142" y="120" class="fill-gray-500 text-[9px] font-semibold tracking-wider dark:fill-gray-400">{{ mb_strtoupper(__('Expenses')) }}</text>
                        <text x="142" y="142" class="fill-gray-900 text-[17px] font-bold dark:fill-gray-100">{{ $fit($statement['expenses']['total']) }}</text>
                        {{-- cash flow loop --}}
                        <path d="M332 76 C 380 80, 380 124, 336 128" fill="none" stroke-width="1.5" stroke-dasharray="4 3" class="stroke-gray-400 dark:stroke-gray-500" marker-end="url(#cf-arrow-red)" />
                        <text x="384" y="96" class="fill-gray-500 text-[10px] dark:fill-gray-400">{{ __('Cashflow') }}</text>
                        <text x="384" y="110" @class(['text-[11px] font-semibold', 'fill-emerald-700 dark:fill-emerald-300' => $statement['cashflow'] >= 0, 'fill-rose-700 dark:fill-rose-300' => $statement['cashflow'] < 0])>{{ $fit($statement['cashflow']) }}</text>
                        {{-- balance sheet --}}
                        <rect x="130" y="210" width="100" height="72" rx="8" class="fill-white stroke-gray-300 dark:fill-gray-900 dark:stroke-gray-600" />
                        <rect x="230" y="210" width="100" height="72" rx="8" class="fill-white stroke-gray-300 dark:fill-gray-900 dark:stroke-gray-600" />
                        <text x="140" y="230" class="fill-emerald-700 text-[8px] font-semibold tracking-wider dark:fill-emerald-400">{{ mb_strtoupper(__('Assets (balance sheet)')) }}</text>
                        <text x="140" y="256" class="fill-gray-900 text-[13px] font-bold dark:fill-gray-100">{{ $fit($statement['assets']['total']) }}</text>
                        <text x="240" y="230" class="fill-rose-700 text-[8px] font-semibold tracking-wider dark:fill-rose-400">{{ mb_strtoupper(__('Liabilities (balance sheet)')) }}</text>
                        <text x="240" y="256" class="fill-gray-900 text-[13px] font-bold dark:fill-gray-100">{{ $fit($statement['liabilities']['total']) }}</text>
                        {{-- assets pay into income --}}
                        <path d="M170 208 C 150 185, 70 175, 90 110 C 96 90, 110 80, 126 78" fill="none" stroke-width="2.5" class="stroke-emerald-600 dark:stroke-emerald-400" marker-end="url(#cf-arrow-green)" />
                        <text x="20" y="200" class="fill-gray-500 text-[10px] dark:fill-gray-400">{{ __('from assets') }}</text>
                        <text x="20" y="214" class="fill-emerald-700 text-[11px] font-semibold dark:fill-emerald-300">{{ $fit($fromAssets) }}</text>
                        {{-- liabilities cost expenses --}}
                        <line x1="300" y1="208" x2="300" y2="160" stroke-width="1.5" stroke-dasharray="4 3" class="stroke-rose-500 dark:stroke-rose-400" marker-end="url(#cf-arrow-red)" />
                        <text x="308" y="188" class="fill-gray-500 text-[10px] dark:fill-gray-400">{{ __('Instalments :amount', ['amount' => $fit($statement['payments'])]) }}</text>
                    </svg>
                </figure>
            </section>

            {{-- Key figures --}}
            <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <p @class(['text-2xl font-semibold tabular-nums', 'text-emerald-700 dark:text-emerald-400' => $statement['cashflow'] >= 0, 'text-rose-700 dark:text-rose-400' => $statement['cashflow'] < 0])>{{ $euro($statement['cashflow']) }}</p>
                    <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-200">💸 {{ __('Monthly cashflow') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Income – expenses') }}</p>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <p class="text-2xl font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">{{ $euro($statement['asset_income']) }}</p>
                    <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-200">🌱 {{ __('Passive income per month') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Money that works for you') }}</p>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <p @class(['text-2xl font-semibold tabular-nums', 'text-emerald-700 dark:text-emerald-400' => $statement['net_worth']['rich_dad'] >= 0, 'text-rose-700 dark:text-rose-400' => $statement['net_worth']['rich_dad'] < 0])>{{ $euro($statement['net_worth']['rich_dad']) }}</p>
                    <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-200">🏦 {{ __('Net worth after Rich Dad') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('without doodads · classic :amount', ['amount' => $euro($statement['net_worth']['classic'])]) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <p class="text-2xl font-semibold tabular-nums text-indigo-700 dark:text-indigo-300">{{ $statement['wealth_days'] !== null ? __(':days days', ['days' => number_format(min(999, $statement['wealth_days']), 1, ',', '.')]) : '–' }}</p>
                    <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-200">⏳ {{ __('Wealth per month') }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('So many days a month your asset income covers your expenses. Reserve: liquid funds (:liquid) last :months.', ['liquid' => $euro($statement['liquid']), 'months' => $statement['reserve_months'] !== null ? trans_choice(':count month|:count months', (int) floor($statement['reserve_months'])) : '–']) }}</p>
                </div>
            </section>

            @if (count($history) >= 2)
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">📈 {{ __('Development') }}</h3>
                    <div class="mt-3 grid gap-5 md:grid-cols-2">
                        <div>
                            <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Financial freedom per month') }}</p>
                            <x-line-chart :points="collect($history)->map(fn ($row) => ['label' => $row['label'], 'value' => $row['freedom']])->all()" :currency="false" suffix=" %" :value-label="__('Financial freedom')" height="170" />
                        </div>
                        <div>
                            <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Cashflow per month') }}</p>
                            <x-line-chart :points="collect($history)->map(fn ($row) => ['label' => $row['label'], 'value' => $row['cashflow']])->all()" :value-label="__('Cashflow')" height="170" />
                        </div>
                    </div>
                </section>
            @endif

            {{-- Pattern --}}
            <section class="rounded-2xl bg-indigo-50 p-5 ring-1 ring-indigo-100 dark:bg-indigo-900/20 dark:ring-indigo-800">
                <h3 class="font-semibold text-indigo-950 dark:text-indigo-100">🔄 {{ __('Cashflow patterns') }}</h3>
                <div class="mt-3 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4" aria-hidden="true">
                    @foreach (['poor' => __('Salary → expenses'), 'middle' => __('Salary → liabilities → expenses'), 'building' => __('Salary → assets → income'), 'rich' => __('Assets → income → expenses')] as $key => $flow)
                        <div @class(['rounded-lg px-2 py-2 text-center', 'bg-indigo-600 font-semibold text-white' => $statement['pattern'] === $key, 'bg-white/70 text-indigo-900 dark:bg-gray-800/60 dark:text-indigo-200' => $statement['pattern'] !== $key])>{{ $patterns[$key]['icon'] }} {{ $flow }}</div>
                    @endforeach
                </div>
            </section>

            {{-- Cashflow quadrant --}}
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">🔲 {{ __('Cashflow quadrant') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('From “Rich Dad’s Cashflow Quadrant”: on the left you trade time for money (E, S), on the right systems and investments work for you (B, I).') }}</p>
                <div class="mt-4 grid max-w-md grid-cols-2 gap-1.5">
                    @foreach (['E' => __('Employee'), 'B' => __('Business owner'), 'S' => __('Self-employed'), 'I' => __('Investor')] as $key => $label)
                        <div @class(['rounded-xl p-3', 'bg-indigo-600 text-white' => $statement['quadrant'][$key]['share'] >= 50, 'bg-indigo-50 text-indigo-950 dark:bg-indigo-900/30 dark:text-indigo-100' => $statement['quadrant'][$key]['share'] < 50])>
                            <p class="text-xl font-bold">{{ $key }} <span class="text-sm font-medium">{{ $statement['quadrant'][$key]['share'] }} %</span></p>
                            <p class="text-xs">{{ $label }} · {{ $fit($statement['quadrant'][$key]['amount']) }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- Income statement --}}
            <section class="rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                <h3 class="border-b border-gray-100 px-5 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">📄 {{ __('Income statement') }} <span class="text-sm font-normal text-gray-500 dark:text-gray-400">· {{ __('per month') }}</span></h3>
                <div class="grid gap-0 md:grid-cols-2 md:divide-x md:divide-gray-100 dark:md:divide-gray-700">
                    <div class="p-5">
                        <div class="flex items-baseline justify-between border-b border-gray-200 pb-2 dark:border-gray-700">
                            <h4 class="text-sm font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">{{ __('Income (statement)') }}</h4>
                            <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($statement['income']['total']) }}</span>
                        </div>
                        @forelse (collect($statement['income']['rows'])->groupBy('kind') as $kind => $rows)
                            <h5 class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $incomeGroups[$kind] ?? $kind }}</h5>
                            <ul class="mt-1 space-y-1.5 text-sm">
                                @foreach ($rows as $row)
                                    <li class="flex items-center justify-between gap-3">
                                        <span class="text-gray-700 dark:text-gray-200">{{ $row['label'] }}</span>
                                        <span class="flex items-center gap-2">
                                            <span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($row['value']) }}</span>
                                            @if ($row['id'])
                                                <button type="button" wire:click="edit({{ $row['id'] }})" class="text-gray-400 hover:text-indigo-600" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="h-4 w-4" /></button>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @empty
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">– {{ __('No income entered') }} –</p>
                        @endforelse
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Portfolio and passive income come from assets – that is the income that makes you free.') }}</p>
                    </div>
                    <div class="border-t border-gray-100 p-5 md:border-t-0 dark:border-gray-700">
                        <div class="flex items-baseline justify-between border-b border-gray-200 pb-2 dark:border-gray-700">
                            <h4 class="text-sm font-semibold uppercase tracking-wide text-rose-700 dark:text-rose-400">{{ __('Expenses') }}</h4>
                            <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($statement['expenses']['total']) }}</span>
                        </div>
                        <ul class="mt-3 space-y-1.5 text-sm">
                            @forelse ($statement['expenses']['rows'] as $row)
                                <li class="flex items-center justify-between gap-3">
                                    <span class="text-gray-700 dark:text-gray-200">{{ $row['icon'] }} {{ $row['label'] }}</span>
                                    <span class="flex items-center gap-2">
                                        <span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($row['value']) }}</span>
                                        @if ($row['id'] ?? null)
                                            <button type="button" wire:click="edit({{ $row['id'] }})" class="text-gray-400 hover:text-indigo-600" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="h-4 w-4" /></button>
                                        @endif
                                    </span>
                                </li>
                            @empty
                                <li class="text-gray-500 dark:text-gray-400">– {{ __('No expenses entered') }} –</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </section>

            {{-- Balance sheet --}}
            <section class="rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                <h3 class="border-b border-gray-100 px-5 py-3 font-semibold text-gray-900 dark:border-gray-700 dark:text-gray-100">⚖️ {{ __('Balance sheet') }}</h3>
                <div class="grid gap-0 md:grid-cols-2 md:divide-x md:divide-gray-100 dark:md:divide-gray-700">
                    @foreach (['assets' => [__('Assets (balance sheet)'), __('Put money in your pocket'), 'text-emerald-700 dark:text-emerald-400'], 'liabilities' => [__('Liabilities (balance sheet)'), __('Take money out of your pocket'), 'text-rose-700 dark:text-rose-400']] as $side => [$title, $subtitle, $color])
                        <div @class(['p-5', 'border-t border-gray-100 md:border-t-0 dark:border-gray-700' => $side === 'liabilities'])>
                            <div class="flex items-baseline justify-between">
                                <h4 class="text-sm font-semibold uppercase tracking-wide {{ $color }}">{{ $title }}</h4>
                                <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($statement[$side]['total']) }}</span>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
                            <ul class="mt-3 space-y-2 text-sm">
                                @forelse ($statement[$side]['rows'] as $row)
                                    <li class="flex items-center justify-between gap-3">
                                        <span class="min-w-0">
                                            @if ($row['url'])
                                                <a href="{{ $row['url'] }}" wire:navigate class="text-gray-700 hover:underline dark:text-gray-200">{{ $row['label'] }}</a>
                                            @else
                                                <span class="text-gray-700 dark:text-gray-200">{{ $row['label'] }}</span>
                                            @endif
                                            @if ($side === 'liabilities')
                                                <span @class(['ml-1 rounded-full px-1.5 py-0.5 text-[11px]', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' => $row['good'] ?? false, 'bg-rose-50 text-rose-800 dark:bg-rose-900/40 dark:text-rose-200' => ! ($row['good'] ?? false)])>{{ ($row['good'] ?? false) ? __('good debt') : __('bad debt') }}</span>
                                            @endif
                                        </span>
                                        <span class="flex items-center gap-2">
                                            <span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($row['value']) }}</span>
                                            @if ($row['id'] ?? null)
                                                <button type="button" wire:click="edit({{ $row['id'] }})" class="text-gray-400 hover:text-indigo-600" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="h-4 w-4" /></button>
                                            @endif
                                        </span>
                                    </li>
                                @empty
                                    <li class="text-gray-500 dark:text-gray-400">{{ __('Nothing entered yet.') }}</li>
                                @endforelse
                            </ul>
                            @if ($side === 'liabilities' && $statement['good_debt'] > 0)
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Good debt is paid by an asset (e.g. the rent of a flat); bad debt you pay yourself.') }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($statement['doodads']['rows'] !== [])
                    <div class="border-t border-gray-100 p-5 dark:border-gray-700">
                        <div class="flex items-baseline justify-between">
                            <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ __('Doodads (liability per Kiyosaki)') }}</h4>
                            <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($statement['doodads']['total']) }}</span>
                        </div>
                        <ul class="mt-3 space-y-2 text-sm">
                            @foreach ($statement['doodads']['rows'] as $row)
                                <li class="flex items-center justify-between gap-3">
                                    @if ($row['url'])
                                        <a href="{{ $row['url'] }}" wire:navigate class="text-gray-700 hover:underline dark:text-gray-200">{{ $row['label'] }}</a>
                                    @else
                                        <span class="text-gray-700 dark:text-gray-200">{{ $row['label'] }}</span>
                                    @endif
                                    <span class="flex items-center gap-2">
                                        <span class="tabular-nums text-gray-900 dark:text-gray-100">{{ $euro($row['value']) }}</span>
                                        @if ($row['id'] ?? null)
                                            <button type="button" wire:click="edit({{ $row['id'] }})" class="text-gray-400 hover:text-indigo-600" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="h-4 w-4" /></button>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Kept apart from the assets: it has a value, but it costs money every month instead of bringing some in.') }}</p>
                    </div>
                @endif
            </section>

            @if ($steps !== [])
                <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">👣 {{ __('Next steps') }}</h3>
                    <ul class="mt-3 space-y-3 text-sm">
                        @foreach ($steps as $step)
                            <li class="flex gap-3">
                                <span aria-hidden="true">{{ $step['icon'] }}</span>
                                <span class="text-gray-700 dark:text-gray-200">{{ $step['text'] }}@if ($step['url']) <a href="{{ $step['url'] }}" wire:navigate class="whitespace-nowrap font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('More') }} →</a>@endif</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Rent counts as net cold rent of the current tenants, dividends as monthly average, business profit as self-employed income; rent payments on the bank account are not counted twice. Transfers between own accounts and saving/investing are not expenses. Your own entries are added on top.') }}</p>
        </div>
    </div>

    @if ($showForm)
        @php
            $formTitles = ['income' => __('Income (entry)'), 'expense' => __('Expense'), 'asset' => __('Asset'), 'liability' => __('Liability')];
            $isBalance = in_array($entry['kind'], ['asset', 'liability'], true);
        @endphp
        <x-sheet close="closeForm" :label="$formTitles[$entry['kind']] ?? ''" size="md">
            <x-slot name="header"><h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $editingId ? __('Edit entry') : '+ '.($formTitles[$entry['kind']] ?? '') }}</h3></x-slot>
            <form wire:submit="save" id="cashflow-form" class="space-y-3 p-5">
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Type') }}
                    <select wire:model="entry.category" class="{{ $input }}">
                        @foreach ($categories[$entry['kind']] ?? [] as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Name') }}<input type="text" wire:model="entry.label" class="{{ $input }}" placeholder="{{ ['income' => __('e.g. Salary Max'), 'expense' => __('e.g. Rent'), 'asset' => __('e.g. ETF portfolio'), 'liability' => __('e.g. Car loan')][$entry['kind']] ?? '' }}"><x-input-error :messages="$errors->get('entry.label')" /></label>
                @if ($isBalance)
                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ __('Value (€)') }}<input type="text" inputmode="decimal" wire:model="entry.value" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.value')" /></label>
                @endif
                <label class="block text-sm text-gray-700 dark:text-gray-300">{{ ['income' => __('Amount per month (€)'), 'expense' => __('Amount per month (€)'), 'asset' => __('Income per month (€)'), 'liability' => __('Instalment per month (€)')][$entry['kind']] ?? '' }}<input type="text" inputmode="decimal" wire:model="entry.amount" class="{{ $input }}"><x-input-error :messages="$errors->get('entry.amount')" /></label>
                @if ($entry['kind'] === 'asset')
                    <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="entry.productive" class="mt-0.5 rounded border-gray-300 text-indigo-600"> {{ __('Real asset: it puts money in my pocket (otherwise it counts as a doodad, e.g. own car or home)') }}</label>
                @elseif ($entry['kind'] === 'liability')
                    <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model="entry.productive" class="mt-0.5 rounded border-gray-300 text-indigo-600"> {{ __('Good debt: it financed an asset that pays the instalment') }}</label>
                @endif
            </form>
            <x-slot name="footer">
                <div class="flex items-center gap-2">
                    <x-primary-button form="cashflow-form">{{ __('Save') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="closeForm">{{ __('Cancel') }}</x-secondary-button>
                    @if ($editingId)
                        <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="{{ __('Delete this entry?') }}" class="ms-auto text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}"><x-icon name="trash" class="h-5 w-5" /></button>
                    @endif
                </div>
            </x-slot>
        </x-sheet>
    @endif
</div>
