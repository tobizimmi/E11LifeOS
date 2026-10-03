<?php

use App\Models\WorkDay;
use App\Models\WorkProfile;
use App\Support\Life\PublicHolidays;
use App\Support\Life\WorkTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Personal working time account (target/actual, overtime) and holiday planning with public
 * holidays of the federal state and bridge day tips.
 */
new #[Layout('layouts.app')] class extends Component
{
    #[Url(except: 'week')]
    public string $tab = 'week';

    /** Monday of the week shown (Y-m-d); empty = current week. */
    #[Url(except: '')]
    public string $week = '';

    /** Month shown (Y-m); empty = current month. */
    #[Url(except: '')]
    public string $month = '';

    public ?string $editingDate = null;

    /**
     * @var array{type: string, starts_at: string, ends_at: string, break_minutes: string, note: string}
     */
    public array $day = ['type' => 'work', 'starts_at' => '', 'ends_at' => '', 'break_minutes' => '0', 'note' => ''];

    /**
     * @var array{from: string, to: string, half: bool}
     */
    public array $leave = ['from' => '', 'to' => '', 'half' => false];

    /**
     * @var array{weekly_hours: string, work_days: list<string>, vacation_days: string, carry_over_days: string, state: string, overtime_start: string}
     */
    public array $settings = ['weekly_hours' => '40', 'work_days' => ['1', '2', '3', '4', '5'], 'vacation_days' => '30', 'carry_over_days' => '0', 'state' => 'BY', 'overtime_start' => '0:00'];

    public function mount(): void
    {
        $this->tab = in_array($this->tab, ['week', 'month', 'leave', 'settings'], true) ? $this->tab : 'week';
        $profile = $this->profile();
        $this->settings = [
            'weekly_hours' => rtrim(rtrim(number_format($profile->weekly_hours, 2, ',', ''), '0'), ','),
            'work_days' => array_map('strval', $profile->work_days),
            'vacation_days' => rtrim(rtrim(number_format($profile->vacation_days, 1, ',', ''), '0'), ','),
            'carry_over_days' => $profile->carry_over_year === today()->year ? rtrim(rtrim(number_format($profile->carry_over_days, 1, ',', ''), '0'), ',') : '0',
            'state' => $profile->state,
            'overtime_start' => WorkTime::format($profile->overtime_start_minutes),
        ];
    }

    /**
     * The entry of a day (a new one when there is none yet).
     */
    private function entryFor(string $date): WorkDay
    {
        return WorkDay::where('user_id', Auth::id())->whereDate('date', $date)->first() ?? new WorkDay(['user_id' => Auth::id(), 'date' => $date]);
    }

    private function profile(): WorkProfile
    {
        return WorkProfile::for(Auth::user());
    }

    private function weekStart(): Carbon
    {
        try {
            return ($this->week !== '' ? Carbon::parse($this->week) : today())->startOfWeek(Carbon::MONDAY);
        } catch (Throwable) {
            return today()->startOfWeek(Carbon::MONDAY);
        }
    }

    private function monthStart(): Carbon
    {
        try {
            return $this->month !== '' ? Carbon::createFromFormat('!Y-m', $this->month)->startOfMonth() : today()->startOfMonth();
        } catch (Throwable) {
            return today()->startOfMonth();
        }
    }

    public function shiftWeek(int $weeks): void
    {
        $this->week = $this->weekStart()->addWeeks($weeks)->toDateString();
        $this->editingDate = null;
    }

    public function shiftMonth(int $months): void
    {
        $this->month = $this->monthStart()->addMonthsNoOverflow($months)->format('Y-m');
    }

    /**
     * "Start": today's entry begins now; "Stop": it ends now (with the legal minimum break).
     */
    public function clock(): void
    {
        $entry = $this->entryFor(today()->toDateString());

        if (! $entry->exists || ! $entry->starts_at) {
            $entry->fill(['type' => 'work', 'starts_at' => now()->format('H:i'), 'ends_at' => null])->save();

            return;
        }

        $entry->ends_at = now()->format('H:i');
        $worked = $entry->workedMinutes();
        $entry->break_minutes = max($entry->break_minutes, $worked > 555 ? 45 : ($worked > 390 ? 30 : 0));
        $entry->save();
    }

    public function editDay(string $date): void
    {
        $date = Carbon::parse($date)->toDateString();
        $entry = WorkDay::where('user_id', Auth::id())->whereDate('date', $date)->first();
        $this->editingDate = $date;
        $this->day = [
            'type' => $entry?->type ?? 'work',
            'starts_at' => substr((string) $entry?->starts_at, 0, 5),
            'ends_at' => substr((string) $entry?->ends_at, 0, 5),
            'break_minutes' => (string) ($entry?->break_minutes ?? 0),
            'note' => (string) $entry?->note,
        ];
        $this->resetValidation();
    }

    public function saveDay(): void
    {
        $data = $this->validate([
            'editingDate' => ['required', 'date'],
            'day.type' => ['required', Rule::in(WorkDay::TYPES)],
            'day.starts_at' => ['nullable', 'date_format:H:i'],
            'day.ends_at' => ['nullable', 'date_format:H:i', 'after:day.starts_at'],
            'day.break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'day.note' => ['nullable', 'string', 'max:255'],
        ], attributes: ['day.ends_at' => __('End'), 'day.starts_at' => __('Start')]);
        $values = $data['day'];
        $withTimes = in_array($values['type'], ['work', 'half_vacation'], true);

        $this->entryFor(Carbon::parse($data['editingDate'])->toDateString())->fill([
            'type' => $values['type'],
            'starts_at' => $withTimes ? ($values['starts_at'] ?: null) : null,
            'ends_at' => $withTimes ? ($values['ends_at'] ?: null) : null,
            'break_minutes' => $withTimes ? (int) $values['break_minutes'] : 0,
            'note' => trim((string) $values['note']) ?: null,
        ])->save();

        $this->editingDate = null;
    }

    public function clearDay(string $date): void
    {
        WorkDay::where('user_id', Auth::id())->whereDate('date', Carbon::parse($date)->toDateString())->delete();
        $this->editingDate = null;
    }

    /**
     * Books leave for every working day in the range (days already worked stay untouched).
     */
    public function planLeave(): void
    {
        $data = $this->validate([
            'leave.from' => ['required', 'date'],
            'leave.to' => ['required', 'date', 'after_or_equal:leave.from'],
        ], attributes: ['leave.from' => __('From'), 'leave.to' => __('Until')])['leave'];

        $time = WorkTime::forProfile($this->profile());
        $dates = $time->workingDaysBetween(Carbon::parse($data['from']), Carbon::parse($data['to']));

        if ($dates === []) {
            $this->addError('leave.to', __('There is no working day in this period.'));

            return;
        }

        foreach ($dates as $date) {
            $entry = $this->entryFor($date->toDateString());

            if (! $entry->exists || $entry->type !== 'work' || ! $entry->starts_at) {
                $entry->fill(['type' => $this->leave['half'] ? 'half_vacation' : 'vacation', 'starts_at' => null, 'ends_at' => null, 'break_minutes' => 0])->save();
            }
        }

        $this->dispatch('toast', message: trans_choice(':count leave day booked.|:count leave days booked.', count($dates) * ($this->leave['half'] ? 0.5 : 1)));
        $this->reset('leave');
    }

    public function cancelLeave(string $from, string $to): void
    {
        WorkDay::where('user_id', Auth::id())->whereIn('type', ['vacation', 'half_vacation'])
            ->whereDate('date', '>=', Carbon::parse($from)->toDateString())->whereDate('date', '<=', Carbon::parse($to)->toDateString())->delete();
    }

    public function saveSettings(): void
    {
        $this->settings['weekly_hours'] = str_replace(',', '.', $this->settings['weekly_hours']);
        $this->settings['vacation_days'] = str_replace(',', '.', $this->settings['vacation_days']);
        $this->settings['carry_over_days'] = str_replace(',', '.', $this->settings['carry_over_days']);
        $data = $this->validate([
            'settings.weekly_hours' => ['required', 'numeric', 'min:0', 'max:80'],
            'settings.work_days' => ['required', 'array', 'min:1'],
            'settings.work_days.*' => ['integer', 'between:1,7'],
            'settings.vacation_days' => ['required', 'numeric', 'min:0', 'max:100'],
            'settings.carry_over_days' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'settings.state' => ['required', Rule::in(array_keys(PublicHolidays::states()))],
            'settings.overtime_start' => ['nullable', 'regex:/^[−-]?\d{1,4}(:[0-5]\d)?$/u'],
        ], attributes: ['settings.work_days' => __('Working days'), 'settings.overtime_start' => __('Overtime at the start of the year')])['settings'];

        $overtime = (string) $data['overtime_start'];
        $negative = str_starts_with($overtime, '-') || str_starts_with($overtime, '−');
        [$hours, $minutes] = array_pad(explode(':', ltrim($overtime, '-−')), 2, '0');

        $this->profile()->update([
            'weekly_hours' => (float) $data['weekly_hours'],
            'work_days' => array_values(array_unique(array_map('intval', $data['work_days']))),
            'vacation_days' => (float) $data['vacation_days'],
            'carry_over_days' => (float) ($data['carry_over_days'] ?: 0),
            'carry_over_year' => today()->year,
            'state' => $data['state'],
            'overtime_start_minutes' => ($negative ? -1 : 1) * ((int) $hours * 60 + (int) $minutes),
        ]);

        $this->dispatch('toast', message: __('Saved.'));
    }

    public function with(): array
    {
        $profile = $this->profile();
        $time = WorkTime::forProfile($profile);
        $year = today()->year;
        $yearDays = WorkDay::where('user_id', Auth::id())->whereYear('date', $year)->get();
        $weekStart = $this->weekStart();
        $monthStart = $this->monthStart();
        $rangeDays = fn (Carbon $from, Carbon $to) => WorkDay::where('user_id', Auth::id())->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->get();
        $today = WorkDay::where('user_id', Auth::id())->whereDate('date', today())->first();

        // Upcoming leave grouped into continuous ranges.
        $leaveRanges = [];

        foreach ($yearDays->merge(WorkDay::where('user_id', Auth::id())->whereDate('date', '>', Carbon::create($year)->endOfYear())->get())
            ->filter(fn (WorkDay $day) => in_array($day->type, ['vacation', 'half_vacation'], true) && $day->date->gte(today()))->sortBy('date') as $day) {
            $last = end($leaveRanges);

            if ($last && $time->workingDaysBetween($last['to']->copy()->addDay(), $day->date->copy()->subDay()) === []) {
                $leaveRanges[array_key_last($leaveRanges)]['to'] = $day->date;
                $leaveRanges[array_key_last($leaveRanges)]['days'] += $day->type === 'half_vacation' ? 0.5 : 1;
            } else {
                $leaveRanges[] = ['from' => $day->date, 'to' => $day->date, 'days' => $day->type === 'half_vacation' ? 0.5 : 1];
            }
        }

        return [
            'profile' => $profile,
            'time' => $time,
            'today' => $today,
            'todayMinutes' => $today?->workedMinutes() ?? 0,
            'weekStart' => $weekStart,
            'weekData' => $this->tab === 'week' ? $time->period($weekStart, $weekStart->copy()->addDays(6), $rangeDays($weekStart, $weekStart->copy()->addDays(6))) : null,
            'monthStart' => $monthStart,
            'monthData' => $this->tab === 'month' ? $time->period($monthStart, $monthStart->copy()->endOfMonth(), $rangeDays($monthStart, $monthStart->copy()->endOfMonth())) : null,
            'balance' => $time->balance($yearDays),
            'leaveStats' => $time->leave($year, $yearDays),
            'leaveRanges' => $leaveRanges,
            'bridgeDays' => $this->tab === 'leave' ? $time->bridgeDays($year) : [],
            'upcomingHolidays' => collect($time->holidays($year) + $time->holidays($year + 1))->filter(fn ($name, $date) => $date >= today()->toDateString())->take(6),
            'leaveCost' => $this->leave['from'] !== '' && $this->leave['to'] !== '' && $this->leave['to'] >= $this->leave['from']
                ? count($time->workingDaysBetween(Carbon::parse($this->leave['from']), Carbon::parse($this->leave['to']))) * ($this->leave['half'] ? 0.5 : 1)
                : null,
        ];
    }
}; ?>

@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 text-sm shadow-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300';
    $fmt = fn (int $minutes, bool $signed = false) => \App\Support\Life\WorkTime::format($minutes, $signed);
    $types = \App\Models\WorkDay::types();
    $days = fn (float $value) => rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    $weekdayNames = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Working time & leave') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <section class="grid gap-3 sm:grid-cols-3">
                <div class="flex flex-col rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Today') }}</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $fmt($todayMinutes) }} <span class="text-sm font-normal text-gray-500">/ {{ $fmt($time->targetMinutes(today())) }}</span></p>
                    @if ($today?->starts_at)
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Since :time', ['time' => substr($today->starts_at, 0, 5)]) }}@if ($today->ends_at) – {{ substr($today->ends_at, 0, 5) }}@endif</p>
                    @endif
                    <button type="button" wire:click="clock" class="mt-auto self-start rounded-lg px-3 py-1.5 text-sm font-medium text-white {{ $today?->starts_at && ! $today->ends_at ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700' }} mt-2">
                        {{ $today?->starts_at && ! $today->ends_at ? '⏹ '.__('Stop work') : '▶ '.__('Start work') }}
                    </button>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Overtime balance') }}</p>
                    <p @class(['mt-1 text-xl font-semibold tabular-nums', 'text-emerald-700 dark:text-emerald-300' => $balance > 0, 'text-red-700 dark:text-red-300' => $balance < 0, 'text-gray-900 dark:text-gray-100' => $balance === 0])>{{ $fmt($balance, true) }} h</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __(':hours h per week, :days', ['hours' => $days($profile->weekly_hours), 'days' => collect($profile->work_days)->sort()->map(fn ($day) => $weekdayNames[$day])->implode(', ')]) }}</p>
                </div>
                <div class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Leave left :year', ['year' => today()->year]) }}</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $days($leaveStats['remaining']) }} <span class="text-sm font-normal text-gray-500">/ {{ $days($leaveStats['entitlement']) }} {{ __('days') }}</span></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __(':taken taken · :planned planned', ['taken' => $days($leaveStats['taken']), 'planned' => $days($leaveStats['planned'])]) }}</p>
                </div>
            </section>

            <div class="flex max-w-full gap-1 overflow-x-auto rounded-lg bg-white p-1 shadow-xs ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" role="tablist">
                @foreach (['week' => '📅 '.__('Week'), 'month' => '🗓️ '.__('Month'), 'leave' => '🏖️ '.__('Leave'), 'settings' => '⚙️ '.__('Settings')] as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" @class(['shrink-0 rounded-md px-3 py-1.5 text-sm', 'bg-indigo-600 text-white' => $tab === $key, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $tab !== $key])>{{ $label }}</button>
                @endforeach
            </div>

            @if ($tab === 'week' || $tab === 'month')
                @php($data = $tab === 'week' ? $weekData : $monthData)
                <section class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                    <div class="flex items-center justify-between gap-3">
                        <button type="button" wire:click="{{ $tab === 'week' ? 'shiftWeek(-1)' : 'shiftMonth(-1)' }}" class="rounded-md px-2 py-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" aria-label="{{ __('Previous') }}">‹</button>
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">
                            {{ $tab === 'week' ? __('Week :number', ['number' => $weekStart->isoWeek]).' · '.$weekStart->format('d.m.').' – '.$weekStart->copy()->addDays(6)->format('d.m.Y') : $monthStart->translatedFormat('F Y') }}
                        </h3>
                        <button type="button" wire:click="{{ $tab === 'week' ? 'shiftWeek(1)' : 'shiftMonth(1)' }}" class="rounded-md px-2 py-1 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700" aria-label="{{ __('Next') }}">›</button>
                    </div>
                    <p class="mt-1 text-center text-sm text-gray-600 dark:text-gray-400">{{ __('Target :target · actual :actual · difference :diff', ['target' => $fmt($data['target']), 'actual' => $fmt($data['actual']), 'diff' => $fmt($data['diff'], true)]) }}</p>

                    <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($data['rows'] as $row)
                            @php($dateKey = $row['date']->toDateString())
                            <li wire:key="day-{{ $dateKey }}" @class(['py-2', 'opacity-60' => ! $row['working'] && ! $row['day']])>
                                <div class="flex flex-wrap items-center gap-3">
                                    <button type="button" wire:click="editDay('{{ $dateKey }}')" class="flex min-w-0 flex-1 items-center gap-3 text-left">
                                        <span @class(['w-20 shrink-0 whitespace-nowrap text-sm', 'font-semibold text-indigo-600 dark:text-indigo-400' => $row['date']->isToday(), 'text-gray-700 dark:text-gray-300' => ! $row['date']->isToday()])>{{ $weekdayNames[$row['date']->dayOfWeekIso] }} {{ $row['date']->format('d.m.') }}</span>
                                        <span class="min-w-0 flex-1 truncate text-sm text-gray-600 dark:text-gray-400">
                                            @if ($row['holiday'])
                                                🎉 {{ $row['holiday'] }}
                                            @endif
                                            @if ($row['day'])
                                                {{ $types[$row['day']->type]['icon'] ?? '' }}
                                                @if ($row['day']->starts_at)
                                                    {{ substr($row['day']->starts_at, 0, 5) }}–{{ $row['day']->ends_at ? substr($row['day']->ends_at, 0, 5) : '…' }}@if ($row['day']->break_minutes) · {{ __(':minutes min break', ['minutes' => $row['day']->break_minutes]) }}@endif
                                                @else
                                                    {{ $types[$row['day']->type]['label'] ?? '' }}
                                                @endif
                                                @if ($row['day']->note)
                                                    · {{ $row['day']->note }}
                                                @endif
                                            @elseif ($row['missing'])
                                                <span class="text-amber-700 dark:text-amber-300">{{ __('No entry') }}</span>
                                            @endif
                                        </span>
                                    </button>
                                    <span class="w-16 shrink-0 text-right text-sm tabular-nums text-gray-900 dark:text-gray-100">{{ $row['actual'] || $row['day'] ? $fmt($row['actual']) : '' }}</span>
                                    <span @class(['w-16 shrink-0 text-right text-xs tabular-nums', 'text-emerald-700 dark:text-emerald-300' => $row['diff'] > 0, 'text-red-700 dark:text-red-300' => $row['diff'] < 0, 'text-gray-400' => $row['diff'] === 0])>{{ $row['diff'] !== 0 ? $fmt($row['diff'], true) : '' }}</span>
                                </div>
                                @if ($row['hint'])
                                    <p class="mt-1 ps-[5.75rem] text-xs text-amber-700 dark:text-amber-300">⚠️ {{ $row['hint'] }}</p>
                                @endif

                                @if ($editingDate === $dateKey)
                                    <form wire:submit="saveDay" class="mt-2 grid gap-2 rounded-lg bg-gray-50 p-3 sm:grid-cols-6 dark:bg-gray-900/40">
                                        <label class="text-xs text-gray-600 sm:col-span-2 dark:text-gray-300">{{ __('Type') }}
                                            <select wire:model.live="day.type" class="{{ $input }}">
                                                @foreach ($types as $key => $type)
                                                    <option value="{{ $key }}">{{ $type['icon'] }} {{ $type['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        @if (in_array($day['type'], ['work', 'half_vacation'], true))
                                            <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('Start') }}<input type="time" wire:model="day.starts_at" class="{{ $input }}"></label>
                                            <label class="text-xs text-gray-600 dark:text-gray-300">{{ __('End') }}<input type="time" wire:model="day.ends_at" class="{{ $input }}"><x-input-error :messages="$errors->get('day.ends_at')" /></label>
                                            <label class="text-xs text-gray-600 sm:col-span-2 dark:text-gray-300">{{ __('Break (minutes)') }}<input type="number" min="0" step="5" wire:model="day.break_minutes" class="{{ $input }}"></label>
                                        @endif
                                        <label class="text-xs text-gray-600 sm:col-span-6 dark:text-gray-300">{{ __('Note') }}<input type="text" wire:model="day.note" class="{{ $input }}" maxlength="255"></label>
                                        <div class="flex flex-wrap gap-2 sm:col-span-6">
                                            <x-primary-button>{{ __('Save') }}</x-primary-button>
                                            <x-secondary-button type="button" wire:click="$set('editingDate', null)">{{ __('Cancel') }}</x-secondary-button>
                                            @if ($row['day'])
                                                <button type="button" wire:click="clearDay('{{ $dateKey }}')" class="ms-auto text-sm text-red-600 underline dark:text-red-400">{{ __('Remove entry') }}</button>
                                            @endif
                                        </div>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($tab === 'leave')
                <form wire:submit="planLeave" class="grid gap-3 rounded-2xl bg-white p-5 shadow-xs sm:grid-cols-6 dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 sm:col-span-6 dark:text-gray-100">{{ __('Plan leave') }}</h3>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('From') }}<input type="date" wire:model.live="leave.from" class="{{ $input }}"><x-input-error :messages="$errors->get('leave.from')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Until') }}<input type="date" wire:model.live="leave.to" class="{{ $input }}"><x-input-error :messages="$errors->get('leave.to')" /></label>
                    <label class="flex items-center gap-2 self-end text-sm text-gray-700 sm:col-span-2 dark:text-gray-300"><input type="checkbox" wire:model.live="leave.half" class="rounded-sm border-gray-300 text-indigo-600"> {{ __('Half days') }}</label>
                    @if ($leaveCost !== null)
                        <p class="text-sm text-gray-600 sm:col-span-6 dark:text-gray-400">{{ __('Costs :days leave days (weekends and public holidays are not counted) – :left left afterwards.', ['days' => $days($leaveCost), 'left' => $days($leaveStats['remaining'] - $leaveCost)]) }}</p>
                    @endif
                    <div class="sm:col-span-6"><x-primary-button>{{ __('Book leave') }}</x-primary-button></div>
                </form>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🏖️ {{ __('Planned leave') }}</h3>
                    @forelse ($leaveRanges as $range)
                        <div wire:key="leave-{{ $range['from']->toDateString() }}" class="mt-2 flex items-center gap-3 text-sm">
                            <span class="min-w-0 flex-1 text-gray-900 dark:text-gray-100">{{ $range['from']->translatedFormat('D, d.m.Y') }}@if (! $range['from']->equalTo($range['to'])) – {{ $range['to']->translatedFormat('D, d.m.Y') }}@endif</span>
                            <span class="tabular-nums text-gray-500 dark:text-gray-400">{{ trans_choice(':count day|:count days', $range['days'], ['count' => $days($range['days'])]) }}</span>
                            <button type="button" wire:click="cancelLeave('{{ $range['from']->toDateString() }}', '{{ $range['to']->toDateString() }}')" wire:confirm="{{ __('Cancel this leave?') }}" class="text-xs text-red-600 underline dark:text-red-400">{{ __('Cancel leave') }}</button>
                        </div>
                    @empty
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('No leave planned yet.') }}</p>
                    @endforelse
                    @if ($leaveStats['sick'] > 0)
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count sick day this year|:count sick days this year', $leaveStats['sick']) }}</p>
                    @endif
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🌉 {{ __('Bridge days') }}</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Few leave days next to a public holiday give a long break (:state).', ['state' => \App\Support\Life\PublicHolidays::states()[$profile->state] ?? $profile->state]) }}</p>
                    @forelse ($bridgeDays as $tip)
                        <div class="mt-2 flex flex-wrap items-center gap-3 text-sm" wire:key="bridge-{{ $tip['leave'][0]->toDateString() }}">
                            <span class="min-w-0 flex-1 text-gray-900 dark:text-gray-100">{{ $tip['holiday'] }} ({{ $tip['date']->translatedFormat('D, d.m.') }}): {{ collect($tip['leave'])->map(fn ($date) => $date->translatedFormat('D d.m.'))->implode(', ') }}</span>
                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">{{ __(':leave for :free days off', ['leave' => trans_choice(':count leave day|:count leave days', count($tip['leave'])), 'free' => $tip['free_days']]) }}</span>
                            <button type="button" wire:click="$set('leave', {{ json_encode(['from' => $tip['leave'][0]->toDateString(), 'to' => end($tip['leave'])->toDateString(), 'half' => false]) }})" class="text-xs text-indigo-600 underline dark:text-indigo-400">{{ __('Use') }}</button>
                        </div>
                    @empty
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('No more bridge days this year.') }}</p>
                    @endforelse
                </section>

                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🎉 {{ __('Next public holidays') }}</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($upcomingHolidays as $date => $name)
                            <li class="flex gap-3"><span class="w-28 shrink-0 text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('D, d.m.Y') }}</span><span class="text-gray-900 dark:text-gray-100">{{ $name }}</span></li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($tab === 'settings')
                <form wire:submit="saveSettings" class="grid gap-3 rounded-2xl bg-white p-5 shadow-xs sm:grid-cols-6 dark:bg-gray-800">
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Hours per week') }}<input type="text" inputmode="decimal" wire:model="settings.weekly_hours" class="{{ $input }}"><x-input-error :messages="$errors->get('settings.weekly_hours')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Leave days per year') }}<input type="text" inputmode="decimal" wire:model="settings.vacation_days" class="{{ $input }}"><x-input-error :messages="$errors->get('settings.vacation_days')" /></label>
                    <label class="text-sm text-gray-700 sm:col-span-2 dark:text-gray-300">{{ __('Carried over from last year') }}<input type="text" inputmode="decimal" wire:model="settings.carry_over_days" class="{{ $input }}"></label>
                    <fieldset class="text-sm text-gray-700 sm:col-span-6 dark:text-gray-300">
                        <legend>{{ __('Working days') }}</legend>
                        <div class="mt-1 flex flex-wrap gap-3">
                            @foreach ($weekdayNames as $number => $name)
                                <label class="flex items-center gap-1.5"><input type="checkbox" value="{{ $number }}" wire:model="settings.work_days" class="rounded-sm border-gray-300 text-indigo-600"> {{ $name }}</label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('settings.work_days')" />
                    </fieldset>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Federal state (public holidays)') }}
                        <select wire:model="settings.state" class="{{ $input }}">
                            @foreach (\App\Support\Life\PublicHolidays::states() as $code => $name)
                                <option value="{{ $code }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">{{ __('Overtime at the start of the year') }}<input type="text" wire:model="settings.overtime_start" class="{{ $input }}" placeholder="0:00"><span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ __('Hours:minutes, negative with a minus sign.') }}</span><x-input-error :messages="$errors->get('settings.overtime_start')" /></label>
                    <div class="sm:col-span-6"><x-primary-button>{{ __('Save') }}</x-primary-button></div>
                </form>
            @endif
        </div>
    </div>
</div>
