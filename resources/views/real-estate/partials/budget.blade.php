{{-- Budget lines and trades with schedule (variables from real-estate/show). --}}
@php
    $dated = $summary['items']->filter(fn ($row) => $row['item']->start_on);
    $timelineStart = $dated->min(fn ($row) => $row['item']->start_on);
    $timelineEnd = $dated->max(fn ($row) => $row['item']->end_on ?? $row['item']->start_on);
    $timelineDays = $timelineStart ? max(1, $timelineStart->diffInDays($timelineEnd) + 1) : 1;
@endphp

<div class="space-y-5">
    <form wire:submit="saveItem" class="grid gap-3 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-4 dark:bg-gray-800">
        <h3 class="font-semibold text-gray-900 sm:col-span-2 lg:col-span-4 dark:text-gray-100">{{ $editingItemId ? __('Edit budget line') : __('New budget line') }}</h3>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Phase') }}
            <select wire:model="item.phase" class="{{ $input }}">
                @foreach (\App\Models\RealEstateBudgetItem::phaseLabels() as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Trade') }}
            <select wire:model.live="item.trade" class="{{ $input }}">
                @foreach (\App\Models\RealEstateBudgetItem::tradeLabels() as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm text-gray-700 lg:col-span-2 dark:text-gray-300">{{ __('Title') }}
            <input type="text" wire:model="item.title" class="{{ $input }}">
            <x-input-error :messages="$errors->get('item.title')" />
        </label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Planned (€)') }}<input type="number" step="100" min="0" wire:model="item.planned_amount" class="{{ $input }}"></label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Status') }}
            <select wire:model="item.status" class="{{ $input }}">
                @foreach (\App\Models\RealEstateBudgetItem::statusLabels() as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('Start') }}<input type="date" wire:model="item.start_on" class="{{ $input }}"></label>
        <label class="text-sm text-gray-700 dark:text-gray-300">{{ __('End') }}<input type="date" wire:model="item.end_on" class="{{ $input }}"><x-input-error :messages="$errors->get('item.end_on')" /></label>
        <label class="text-sm text-gray-700 lg:col-span-2 dark:text-gray-300">{{ __('Contractor') }}<input type="text" wire:model="item.contractor" class="{{ $input }}"></label>
        <label class="flex items-center gap-2 self-end pb-2 text-sm text-gray-700 lg:col-span-2 dark:text-gray-300">
            <input type="checkbox" wire:model="item.energy_measure" class="rounded border-gray-300 text-indigo-600"> 🌱 {{ __('Energy-efficiency measure (subsidies, § 35c)') }}
        </label>
        <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
            <x-primary-button>{{ $editingItemId ? __('Save') : '+ '.__('Add') }}</x-primary-button>
            @if ($editingItemId)
                <x-secondary-button type="button" wire:click="cancelItem">{{ __('Cancel') }}</x-secondary-button>
                <button type="button" wire:click="deleteItem({{ $editingItemId }})" wire:confirm="{{ __('Delete this budget line? Its invoices stay.') }}" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Delete') }}</button>
            @elseif ($summary['items']->isEmpty())
                <x-secondary-button type="button" wire:click="addTemplateItems">✨ {{ __('Add typical renovation trades') }}</x-secondary-button>
            @endif
        </div>
    </form>

    @foreach (\App\Models\RealEstateBudgetItem::phaseLabels() as $phase => $phaseLabel)
        @php
            $rows = $summary['items']->filter(fn ($row) => $row['item']->phase === $phase);
        @endphp
        @continue($rows->isEmpty())
        <section class="overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800" aria-label="{{ $phaseLabel }}">
            <div class="flex items-baseline justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-700">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ $phaseLabel }}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __(':invoiced of :planned invoiced', ['invoiced' => $euro($rows->sum('invoiced')), 'planned' => $euro($rows->sum('planned'))]) }}</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-5 py-2 text-start font-medium">{{ __('Trade') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('Planned') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('Invoiced') }}</th>
                            <th class="px-3 py-2 text-end font-medium">{{ __('Paid') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ __('Period') }}</th>
                            <th class="px-3 py-2 text-start font-medium">{{ __('Status') }}</th>
                            <th class="px-3 py-2"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rows as $row)
                            <tr wire:key="budget-row-{{ $row['item']->id }}">
                                <td class="px-5 py-2">
                                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $row['item']->title }} @if ($row['item']->energy_measure)<span title="{{ __('Energy measure') }}">🌱</span>@endif</p>
                                    @php
                                        $meta = array_filter([($label = \App\Models\RealEstateBudgetItem::tradeLabels()[$row['item']->trade] ?? '') !== $row['item']->title ? $label : null, $row['item']->contractor]);
                                    @endphp
                                    @if ($meta !== [])
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ implode(' · ', $meta) }}</p>
                                    @endif
                                    @php
                                        $tradeComparison = $quoteComparisons[$row['item']->id] ?? null;
                                    @endphp
                                    <a href="{{ $tradeComparison ? route('quotes.show', $tradeComparison) : route('quotes.index', ['budget_item' => $row['item']->id]) }}" wire:navigate class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">⚖️ {{ $tradeComparison ? trans_choice('Compare :count quote|Compare :count quotes', $tradeComparison->quotes_count).($tradeComparison->chosen_quote_id ? ' ✓' : '') : __('Compare quotes') }}</a>
                                </td>
                                <td class="px-3 py-2 text-end text-gray-700 dark:text-gray-300">{{ $euro($row['planned']) }}</td>
                                <td @class(['px-3 py-2 text-end', 'font-medium text-red-700 dark:text-red-400' => $row['over'], 'text-gray-700 dark:text-gray-300' => ! $row['over']])>{{ $row['over'] ? '⚠ ' : '' }}{{ $euro($row['invoiced']) }}</td>
                                <td class="px-3 py-2 text-end text-gray-700 dark:text-gray-300">{{ $euro($row['paid']) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs text-gray-600 dark:text-gray-400">{{ $row['item']->start_on?->translatedFormat('j. M') }}@if ($row['item']->end_on) – {{ $row['item']->end_on->translatedFormat('j. M y') }}@endif</td>
                                <td class="px-3 py-2">
                                    <select wire:change="setItemStatus({{ $row['item']->id }}, $event.target.value)" aria-label="{{ __('Status') }}" class="rounded-md border-gray-300 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                        @foreach (\App\Models\RealEstateBudgetItem::statusLabels() as $key => $label)
                                            <option value="{{ $key }}" @selected($row['item']->status === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-end">
                                    <button type="button" wire:click="createTask('item', {{ $row['item']->id }})" class="text-gray-400 hover:text-indigo-600" title="{{ __('Create task') }}" aria-label="{{ __('Create task') }}">✅</button>
                                    <button type="button" wire:click="editItem({{ $row['item']->id }})" class="ms-1 text-gray-400 hover:text-indigo-600" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}">✎</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    @if ($dated->isNotEmpty())
        <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="schedule-heading">
            <h3 id="schedule-heading" class="font-semibold text-gray-900 dark:text-gray-100">🗓 {{ __('Schedule') }}</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $timelineStart->translatedFormat('j. M Y') }} – {{ $timelineEnd->translatedFormat('j. M Y') }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Drag a bar to move the trade, drag its right edge to change the end.') }}</p>
            <ul class="mt-4 space-y-2.5">
                @foreach ($dated->sortBy(fn ($row) => $row['item']->start_on) as $row)
                    @php
                        $offset = $timelineStart->diffInDays($row['item']->start_on) / $timelineDays * 100;
                        $width = max(1.5, (($row['item']->end_on ?? $row['item']->start_on)->diffInDays($row['item']->start_on) + 1) / $timelineDays * 100);
                    @endphp
                    <li class="flex items-center gap-3 text-sm" wire:key="gantt-{{ $row['item']->id }}">
                        <span class="w-40 shrink-0 truncate text-gray-700 dark:text-gray-300">{{ $row['item']->title }}</span>
                        <span class="relative h-5 flex-1 rounded bg-gray-50 dark:bg-gray-900"
                              x-data="{
                                  mode: null, startX: 0, days: 0,
                                  begin(event, mode) { this.mode = mode; this.startX = event.clientX; this.days = 0; event.target.setPointerCapture(event.pointerId) },
                                  move(event) { if (! this.mode) return; this.days = Math.round((event.clientX - this.startX) / this.$el.clientWidth * {{ $timelineDays }}) },
                                  end() { if (! this.mode) return; const mode = this.mode; this.mode = null; if (this.days !== 0) { $wire.shiftItem({{ $row['item']->id }}, this.days, mode === 'end') } },
                              }">
                            <span @class(['absolute inset-y-0 cursor-grab touch-none rounded active:cursor-grabbing', 'opacity-50' => $row['item']->status === 'done'])
                                  style="left: {{ $offset }}%; width: {{ min($width, 100 - $offset) }}%; background: #2a78d6"
                                  :style="mode ? (mode === 'move' ? { transform: 'translateX(' + (days / {{ $timelineDays }} * $el.parentElement.clientWidth) + 'px)' } : { width: 'calc({{ min($width, 100 - $offset) }}% + ' + (days / {{ $timelineDays }} * $el.parentElement.clientWidth) + 'px)' }) : {}"
                                  x-on:pointerdown="begin($event, 'move')" x-on:pointermove="move($event)" x-on:pointerup="end()" x-on:pointercancel="mode = null"
                                  title="{{ $row['item']->title }}: {{ $row['item']->start_on->translatedFormat('j. M') }}{{ $row['item']->end_on ? ' – '.$row['item']->end_on->translatedFormat('j. M') : '' }} · {{ \App\Models\RealEstateBudgetItem::statusLabels()[$row['item']->status] }}">
                                <span class="absolute inset-y-0 right-0 w-2 cursor-ew-resize rounded-r bg-white/40" x-on:pointerdown.stop="begin($event, 'end')" x-on:pointermove="move($event)" x-on:pointerup.stop="end()" aria-hidden="true"></span>
                            </span>
                            <span x-show="mode && days !== 0" x-cloak class="absolute -top-5 left-0 rounded bg-gray-900 px-1.5 text-xs text-white" x-text="(days > 0 ? '+' : '') + days + ' {{ __('days') }}'"></span>
                        </span>
                        <span class="w-20 shrink-0 text-end text-xs text-gray-500 dark:text-gray-400">{{ $row['item']->status === 'done' ? '✓ ' : '' }}{{ \App\Models\RealEstateBudgetItem::statusLabels()[$row['item']->status] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
