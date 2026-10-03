<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\PortfolioPosition;
use App\Models\TargetAllocation;
use App\Support\Portfolio\Analytics;
use App\Support\Portfolio\Valuation;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;

    /** @var array<string, string> encoded symbol (see key()) => target percent as typed */
    public array $targets = [];

    public string $additional = '';

    public bool $buyOnly = true;

    /** Filled by the security picker to add a target for a security not held yet. */
    public string $newSymbol = '';

    public string $newName = '';

    /**
     * Livewire paths use dots, symbols contain them (SAP.DE): "~" stands in for the dot.
     */
    public static function key(string $symbol): string
    {
        return str_replace('.', '~', $symbol);
    }

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->targets = TargetAllocation::where('user_id', $this->currentUser()->id)->get()
            ->mapWithKeys(fn (TargetAllocation $target) => [self::key($target->symbol) => rtrim(rtrim(number_format($target->target_percent, 2, ',', ''), '0'), ',')])->all();
    }

    public function updatedNewSymbol(): void
    {
        $symbol = strtoupper(trim($this->newSymbol));

        if ($symbol !== '' && ! array_key_exists(self::key($symbol), $this->targets)) {
            $this->targets[self::key($symbol)] = '0';
            TargetAllocation::updateOrCreate(['user_id' => $this->currentUser()->id, 'symbol' => $symbol], ['name' => $this->newName ?: $symbol, 'target_percent' => 0]);
        }

        $this->reset('newSymbol', 'newName');
    }

    public function saveTargets(): void
    {
        $this->resetErrorBag('targets');
        $clean = [];

        foreach ($this->targets as $key => $value) {
            $symbol = str_replace('~', '.', (string) $key);
            $number = $this->parseNumber($value);

            if ($number !== '' && (! is_numeric($number) || $number < 0 || $number > 100)) {
                $this->addError('targets', __('Target weights must be between 0 and 100 %.'));

                return;
            }

            $clean[$symbol] = (float) ($number ?: 0);
        }

        if (array_sum($clean) > 100.05) {
            $this->addError('targets', __('The target weights add up to :sum % – at most 100 % are possible.', ['sum' => number_format(array_sum($clean), 1, ',', '.')]));

            return;
        }

        $userId = $this->currentUser()->id;
        $names = PortfolioPosition::whereIn('portfolio_id', $this->portfolios()->select('id'))->pluck('name', 'symbol');

        foreach ($clean as $symbol => $percent) {
            if ($percent > 0) {
                TargetAllocation::updateOrCreate(['user_id' => $userId, 'symbol' => $symbol], ['name' => $names[$symbol] ?? TargetAllocation::where('user_id', $userId)->where('symbol', $symbol)->value('name') ?? $symbol, 'target_percent' => $percent]);
            } else {
                TargetAllocation::where('user_id', $userId)->where('symbol', $symbol)->delete();
            }
        }

        $this->dispatch('toast', message: __('Saved.'));
    }

    public function with(Valuation $valuation, Analytics $analytics): array
    {
        $rows = $valuation->rows(PortfolioPosition::whereIn('portfolio_id', $this->portfolios()->select('id'))->get());
        $current = collect($rows)->groupBy(fn (array $row) => $row['position']->symbol)
            ->map(fn ($group) => ['name' => $group->first()['position']->name ?: $group->first()['position']->symbol, 'value' => $group->sum('value')])->all();
        $savedTargets = TargetAllocation::where('user_id', $this->currentUser()->id)->get();
        $names = $savedTargets->pluck('name', 'symbol');

        foreach ($savedTargets as $target) {
            $current[$target->symbol] ??= ['name' => $target->name ?: $target->symbol, 'value' => 0.0];
        }

        $additional = max(0, (float) $this->parseNumber($this->additional));

        return [
            'plan' => $analytics->rebalance($current, $savedTargets->pluck('target_percent', 'symbol')->all(), $additional, $this->buyOnly && $additional > 0),
            'targetSum' => round((float) $savedTargets->sum('target_percent'), 1),
            'names' => $names,
            'hasTargets' => $savedTargets->where('target_percent', '>', 0)->isNotEmpty(),
        ];
    }
}; ?>

@php
    $euro = fn ($value) => number_format((float) $value, 2, ',', '.').' €';
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
            @include('portfolio.partials.nav', ['active' => 'portfolio.rebalancing'])

            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('Rebalancing') }}</h3>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Set a target weight for each security (across all portfolios). The table shows how much to buy or sell to get back to the targets – or how to split a new investment so that nothing has to be sold.') }}</p>
                <div class="mt-4 flex flex-wrap items-end gap-4">
                    <div>
                        <x-input-label for="rebalance-additional" :value="__('New money to invest (€)')" />
                        <x-text-input id="rebalance-additional" wire:model.live.debounce.500ms="additional" inputmode="decimal" class="mt-1 block w-40" placeholder="0" />
                    </div>
                    <label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" wire:model.live="buyOnly" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ __('Only buy, do not sell') }}</label>
                    <div class="w-full sm:ms-auto sm:w-72">
                        <x-input-label for="rebalance-add" :value="__('Add a security that you do not hold yet')" />
                        <div class="mt-1">@include('portfolio.partials.security-picker', ['id' => 'rebalance-add', 'model' => 'newSymbol', 'nameModel' => 'newName'])</div>
                    </div>
                </div>
            </section>

            <form wire:submit="saveTargets" class="overflow-hidden rounded-2xl bg-white shadow-xs dark:bg-gray-800">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead class="bg-gray-50 text-left text-xs text-gray-500 dark:bg-gray-900/40 dark:text-gray-400">
                            <tr>
                                <th class="px-4 py-2 font-medium">{{ __('Security') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ __('Value') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ __('Current') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ __('Target %') }}</th>
                                <th class="px-3 py-2 text-end font-medium">{{ __('Target value') }}</th>
                                <th class="px-4 py-2 text-end font-medium">{{ __('Buy / sell') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @forelse ($plan as $row)
                                <tr wire:key="rebalance-{{ $row['symbol'] }}">
                                    <td class="max-w-[16rem] px-4 py-2"><span class="block truncate text-gray-900 dark:text-gray-100">{{ $row['name'] }}</span><span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['symbol'] }}</span></td>
                                    <td class="px-3 py-2 text-end tabular-nums">{{ $euro($row['value']) }}</td>
                                    <td class="px-3 py-2 text-end tabular-nums text-gray-600 dark:text-gray-300">{{ number_format($row['percent'], 1, ',', '.') }} %</td>
                                    <td class="px-3 py-2 text-end"><input type="text" inputmode="decimal" wire:model="targets.{{ str_replace('.', '~', $row['symbol']) }}" aria-label="{{ __('Target %') }} {{ $row['symbol'] }}" class="w-20 rounded-lg border-gray-300 text-end text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" placeholder="0"></td>
                                    <td class="px-3 py-2 text-end tabular-nums text-gray-600 dark:text-gray-300">{{ $row['target'] > 0 ? $euro($row['target_value']) : '—' }}</td>
                                    <td class="px-4 py-2 text-end font-medium tabular-nums {{ ! $hasTargets || abs($row['trade']) < 1 ? 'text-gray-400' : ($row['trade'] > 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-orange-700 dark:text-orange-400') }}">
                                        {{ ! $hasTargets || abs($row['trade']) < 1 ? '—' : ($row['trade'] > 0 ? __('buy :amount', ['amount' => $euro($row['trade'])]) : __('sell :amount', ['amount' => $euro(abs($row['trade']))])) }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">{{ __('No positions yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 px-4 py-3 dark:border-gray-700">
                    <x-primary-button>{{ __('Save target weights') }}</x-primary-button>
                    <span @class(['text-sm tabular-nums', 'text-gray-600 dark:text-gray-300' => $targetSum <= 100, 'text-red-600' => $targetSum > 100])>{{ __('Sum of targets: :sum %', ['sum' => number_format($targetSum, 1, ',', '.')]) }}</span>
                    <x-input-error :messages="$errors->get('targets')" />
                </div>
            </form>
        </div>
    </div>
</div>
