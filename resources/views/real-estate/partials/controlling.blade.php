{{-- Financing controlling: funds drawn vs planned, trade utilisation, running costs (from real-estate/show). --}}
@php
    $drawLabels = \App\Models\RealEstateDraw::sourceLabels();
    $coverage = $controlling['coverage_gap'];
    $renoMax = collect($controlling['renovation_by_month'])->max('amount') ?: 1;
@endphp

<div class="space-y-5">
    {{-- KPI cards --}}
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @php
            $kpis = [
                [__('Total costs'), $euro($controlling['cost']), __('Purchase + expected trades'), null],
                [__('Planned funding'), $euro($controlling['planned_total']), $coverage > 0.5 ? __('Still :amount to cover', ['amount' => $euro($coverage)]) : ($coverage < -0.5 ? __(':amount above costs', ['amount' => $euro(-$coverage)]) : __('Fully covered')), $coverage <= 0.5],
                [__('Drawn so far'), $euro($controlling['actual_total']), __(':amount still to draw', ['amount' => $euro(max(0, $controlling['remaining']))]), null],
                [__('Renovation so far'), $euro($summary['renovation']['invoiced']), __('of :planned planned', ['planned' => $euro($summary['renovation']['planned'])]), null],
            ];
        @endphp
        @foreach ($kpis as [$label, $value, $hint, $good])
            <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 font-semibold text-lg tabular-nums text-gray-900 dark:text-gray-100">{{ $value }}</p>
                <p @class(['mt-0.5 text-xs', 'text-emerald-600 dark:text-emerald-400' => $good === true, 'text-amber-600 dark:text-amber-400' => $good === false, 'text-gray-500 dark:text-gray-400' => $good === null])>{{ $hint }}</p>
            </div>
        @endforeach
    </section>

    {{-- Draw form --}}
    <form wire:submit="saveDraw" class="grid gap-3 rounded-2xl bg-white p-5 shadow-xs sm:grid-cols-2 lg:grid-cols-4 dark:bg-gray-800">
        <h3 class="font-semibold text-gray-900 sm:col-span-2 lg:col-span-4 dark:text-gray-100">🏦 {{ $editingDrawId ? __('Edit draw') : __('New equity contribution / loan draw') }}</h3>
        <p class="-mt-1 text-xs text-gray-500 sm:col-span-2 lg:col-span-4 dark:text-gray-400">{{ __('Track what you put in from equity and which loan tranches you call off – planned (“requested”) against what the bank actually paid out.') }}</p>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Source') }}
            <select wire:model.live="draw.source" class="{{ $input }}">
                @foreach ($drawLabels as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        @if ($draw['source'] === 'loan' && $model->loans->isNotEmpty())
            <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Loan') }}
                <select wire:model="draw.loan_id" class="{{ $input }}">
                    <option value="">{{ __('— not assigned —') }}</option>
                    @foreach ($model->loans as $loan)
                        <option value="{{ $loan->id }}">{{ $loan->lender }} ({{ $euro($loan->amount) }})</option>
                    @endforeach
                </select>
            </label>
        @else
            <div class="hidden sm:block"></div>
        @endif
        <label class="text-sm text-gray-700 lg:col-span-2 dark:text-gray-300">{{ __('Purpose') }}
            <input type="text" wire:model="draw.label" placeholder="{{ __('e.g. 1st draw after completion of shell') }}" class="{{ $input }}">
            <x-input-error :messages="$errors->get('draw.label')" />
        </label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Planned / requested (€)') }}<input type="number" step="100" min="0" wire:model="draw.planned_amount" class="{{ $input }}"></label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Requested on') }}<input type="date" wire:model="draw.planned_on" class="{{ $input }}"></label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Actually disbursed (€)') }}<input type="number" step="100" min="0" wire:model="draw.actual_amount" class="{{ $input }}"></label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Disbursed on') }}<input type="date" wire:model="draw.actual_on" class="{{ $input }}"></label>
        <label class="text-sm text-gray-700 lg:col-span-4 dark:text-gray-300">{{ __('Note') }}<input type="text" wire:model="draw.notes" class="{{ $input }}"></label>
        <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
            <x-primary-button>{{ $editingDrawId ? __('Save') : '+ '.__('Add') }}</x-primary-button>
            @if ($editingDrawId)
                <x-secondary-button type="button" wire:click="cancelDraw">{{ __('Cancel') }}</x-secondary-button>
                <button type="button" wire:click="deleteDraw({{ $editingDrawId }})" wire:confirm="{{ __('Delete this draw?') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
            @endif
        </div>
    </form>

    {{-- Draws per source --}}
    @foreach (\App\Models\RealEstateDraw::SOURCES as $source)
        @php
            $group = $controlling['sources'][$source];
        @endphp
        @continue($group['rows']->isEmpty())
        <section class="overflow-hidden rounded-2xl bg-white shadow-xs dark:bg-gray-800" aria-label="{{ $drawLabels[$source] }}">
            <div class="flex items-baseline justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $source === 'equity' ? '💶' : '🏦' }} {{ $drawLabels[$source] }}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __(':actual of :planned drawn', ['actual' => $euro($group['actual']), 'planned' => $euro($group['planned'])]) }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-5 py-2 text-start font-medium">{{ __('Purpose') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('Planned') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('Disbursed') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ __('Progress') }}</th>
                            <th class="px-3 py-2"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($group['rows'] as $row)
                            @php $util = $row->utilisation(); @endphp
                            <tr wire:key="draw-{{ $row->id }}">
                                <td class="px-5 py-2">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $row->label }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        @if ($row->loan)📄 {{ $row->loan->lender }} · @endif
                                        @if ($row->planned_on){{ __('requested :date', ['date' => $row->planned_on->translatedFormat('j. M Y')]) }}@endif
                                        @if ($row->actual_on) · {{ __('paid :date', ['date' => $row->actual_on->translatedFormat('j. M Y')]) }}@endif
                                    </p>
                                    @if ($row->notes)<p class="text-xs text-gray-400 dark:text-gray-500">{{ $row->notes }}</p>@endif
                                </td>
                                <td class="px-3 py-2 text-end tabular-nums text-gray-700 dark:text-gray-300">{{ $euro($row->planned_amount) }}</td>
                                <td class="px-3 py-2 text-end tabular-nums text-gray-700 dark:text-gray-300">{{ $euro($row->actual_amount) }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2 w-24 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-900">
                                            <span @class(['block h-full rounded-full', 'bg-emerald-500' => $util >= 100, 'bg-indigo-500' => $util < 100]) style="width: {{ $util }}%"></span>
                                        </span>
                                        <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $util }} %</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-end">
                                    <button type="button" wire:click="markDrawDone({{ $row->id }})" class="text-gray-400 hover:text-emerald-600" title="{{ __('Mark as fully disbursed') }}" aria-label="{{ __('Mark as fully disbursed') }}">{{ $util >= 100 ? '↩︎' : '✓' }}</button>
                                    <button type="button" wire:click="editDraw({{ $row->id }})" class="ms-1 text-gray-400 hover:text-indigo-600" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}"><x-icon name="pencil" class="h-5 w-5" /></button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    {{-- Trade utilisation: planned vs invoiced vs paid --}}
    @if ($summary['items']->isNotEmpty())
        <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-gray-100">🧱 {{ __('Trade utilisation') }}</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('How much of each trade’s budget is already invoiced and paid.') }}</p>
            <ul class="mt-4 space-y-3">
                @foreach ($summary['items'] as $row)
                    @php
                        $planned = max($row['planned'], $row['invoiced']) ?: 1;
                        $invPct = (int) min(100, round($row['invoiced'] / $planned * 100));
                        $paidPct = (int) min(100, round($row['paid'] / $planned * 100));
                    @endphp
                    <li wire:key="util-{{ $row['item']->id }}" class="text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="min-w-0 truncate text-gray-700 dark:text-gray-300">{{ $row['item']->title }} @if ($row['over'])<span class="text-red-600 dark:text-red-400">⚠</span>@endif</span>
                            <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $euro($row['invoiced']) }} / {{ $euro($row['planned']) }}</span>
                        </div>
                        <span class="relative mt-1 block h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-900">
                            <span @class(['absolute inset-y-0 left-0 rounded-full', 'bg-red-400' => $row['over'], 'bg-indigo-300 dark:bg-indigo-500/60' => ! $row['over']]) style="width: {{ $invPct }}%"></span>
                            <span class="absolute inset-y-0 left-0 rounded-full bg-emerald-500" style="width: {{ $paidPct }}%"></span>
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 flex flex-wrap gap-4 text-xs text-gray-500 dark:text-gray-400">
                <span class="flex items-center gap-1"><span class="h-2 w-3 rounded-full bg-emerald-500"></span>{{ __('Paid') }}</span>
                <span class="flex items-center gap-1"><span class="h-2 w-3 rounded-full bg-indigo-300 dark:bg-indigo-500/60"></span>{{ __('Invoiced') }}</span>
                <span class="flex items-center gap-1"><span class="h-2 w-3 rounded-full bg-gray-200 dark:bg-gray-700"></span>{{ __('Planned') }}</span>
            </p>
        </section>
    @endif

    {{-- Running renovation costs by month --}}
    @if ($controlling['renovation_by_month'] !== [])
        <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-gray-100">📅 {{ __('Running costs by month') }}</h3>
            <div class="mt-4 flex items-end gap-2 overflow-x-auto pb-1" style="height: 140px">
                @foreach ($controlling['renovation_by_month'] as $bar)
                    <div class="flex h-full min-w-10 flex-1 flex-col items-center justify-end gap-1" wire:key="reno-month-{{ $bar['month'] }}">
                        <span class="text-[10px] tabular-nums text-gray-500 dark:text-gray-400">{{ $euro($bar['amount']) }}</span>
                        <span class="w-full rounded-t bg-indigo-500" style="height: {{ max(2, round($bar['amount'] / $renoMax * 100)) }}%" title="{{ $bar['label'] }}: {{ $euro($bar['amount']) }}"></span>
                        <span class="whitespace-nowrap text-[10px] text-gray-500 dark:text-gray-400">{{ $bar['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($controlling['sources']['equity']['rows']->isEmpty() && $controlling['sources']['loan']['rows']->isEmpty() && $summary['items']->isEmpty())
        <p class="rounded-2xl bg-white p-6 text-center text-sm text-gray-500 shadow-xs dark:bg-gray-800 dark:text-gray-400">{{ __('Add budget lines and record your equity contributions and loan draws to watch the financing come together.') }}</p>
    @endif
</div>
