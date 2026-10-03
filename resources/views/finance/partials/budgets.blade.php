{{-- Monthly budgets per category (current month). --}}
<section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🎯 {{ __('Budgets for :month', ['month' => now()->translatedFormat('F Y')]) }}</h3>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ __('Set a monthly limit for the categories you want to keep an eye on. At 80 % and at 100 % you get a push notification (once per month).') }}</p>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">🫙 {{ __('Pot: what is left at the end of the month carries over – good for things you do not pay every month (clothes, gifts, car repairs).') }}</p>

    <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-700">
        @foreach ($budgetCategories as $key => $category)
            @php
                $row = $budgetStatus[$key] ?? null;
                $spent = $row['spent'] ?? -(float) ($spentThisMonth[$key] ?? 0);
                $percent = $row['percent'] ?? null;
                $amounts = $euro($spent).($row ? ' / '.$euro($row['available']) : '');
            @endphp
            <li wire:key="budget-{{ $key }}" class="py-3">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="min-w-40 flex-1 text-sm text-gray-900 dark:text-gray-100">{{ $category['icon'] }} {{ $category['label'] }}</span>
                    <span class="text-sm tabular-nums text-gray-600 dark:text-gray-400">{{ $amounts }}</span>
                    <label class="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Limit') }}
                        <input type="text" inputmode="decimal" value="{{ $row ? number_format($row['limit'], 2, ',', '') : '' }}" x-on:change="$wire.saveBudget(@js($key), $event.target.value)" placeholder="–" class="w-24 rounded-md border-gray-300 py-1 text-end text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200" aria-label="{{ __('Monthly budget for :category', ['category' => $category['label']]) }}" />
                        €
                    </label>
                    @if ($row)
                        <button type="button" wire:click="toggleRollover(@js($key))" aria-pressed="{{ $row['budget']->rollover_from ? 'true' : 'false' }}" title="{{ __('Pot: left-over money carries over') }}" @class(['rounded-full px-2 py-0.5 text-xs', 'bg-indigo-600 text-white' => $row['budget']->rollover_from, 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => ! $row['budget']->rollover_from])>🫙 {{ __('Pot') }}</button>
                    @endif
                </div>
                @if ($row)
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ (int) $percent }}" aria-valuemin="0" aria-valuemax="100">
                        <div @class(['h-full rounded-full', 'bg-emerald-500' => $percent < 80, 'bg-amber-500' => $percent >= 80 && $percent < 100, 'bg-red-500' => $percent >= 100]) style="width: {{ min(100, $percent) }}%"></div>
                    </div>
                    <p @class(['mt-1 text-xs', 'text-red-600 dark:text-red-400' => $row['remaining'] < 0, 'text-gray-500 dark:text-gray-400' => $row['remaining'] >= 0])>
                        {{ $row['remaining'] >= 0 ? __(':amount left (:percent %)', ['amount' => $euro($row['remaining']), 'percent' => number_format($percent, 0, ',', '.')]) : __(':amount over budget', ['amount' => $euro(-$row['remaining'])]) }}
                        @if ($row['carry'] != 0)
                            · {{ $row['carry'] > 0 ? __('incl. :amount from earlier months', ['amount' => $euro($row['carry'])]) : __('minus :amount overspent earlier', ['amount' => $euro(-$row['carry'])]) }}
                        @endif
                    </p>
                @endif
            </li>
        @endforeach
    </ul>
</section>
