<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Collection;
use App\Models\FocusEntry;
use App\Models\Item;
use App\Support\QuickCapture;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /** The shown day (Y-m-d); empty = today. */
    #[Url(except: '')]
    public string $day = '';

    public string $brain = '';

    /** Also create a linked to-do for each brain-dump entry. */
    public bool $alsoTask = true;

    /** Entry currently being scheduled into a time box, null = dialog closed. */
    public ?int $scheduling = null;

    public string $atTime = '09:00';

    public int $forMinutes = 30;

    public const DURATIONS = [15, 30, 45, 60, 90, 120];

    private function dayDate(): Carbon
    {
        try {
            $date = $this->day !== '' ? Carbon::createFromFormat('!Y-m-d', $this->day) : today();
        } catch (\Throwable) {
            $date = today();
        }

        return $date;
    }

    public function changeDay(int $offset): void
    {
        $date = $this->dayDate()->addDays($offset);
        $this->day = $date->isSameDay(today()) ? '' : $date->toDateString();
    }

    private function find(int $id): FocusEntry
    {
        return FocusEntry::query()->forDay(Auth::user(), (int) $this->householdId, $this->dayDate()->toDateString())->findOrFail($id);
    }

    private function nextPosition(): int
    {
        return (int) $this->entries->max('position') + 1;
    }

    /**
     * Brain dump: add a thought. By default it also becomes a linked to-do (one source of truth).
     */
    public function addBrain(): void
    {
        $title = trim($this->brain);

        if ($title === '') {
            return;
        }

        $itemId = null;

        if ($this->alsoTask) {
            $task = QuickCapture::task(Auth::user(), (int) $this->householdId, $title, $this->dayDate()->toDateString());
            $itemId = $task?->id;
            $title = $task?->title ?? $title;
        }

        FocusEntry::create([
            'household_id' => $this->householdId,
            'user_id' => Auth::id(),
            'item_id' => $itemId,
            'date' => $this->dayDate()->toDateString(),
            'title' => mb_substr($title, 0, 255),
            'position' => $this->nextPosition(),
        ]);

        $this->brain = '';
        unset($this->entries);
    }

    /**
     * Pulls the member's own open to-dos (due today, overdue or undated) into the brain dump, so the
     * day can be planned from one list. Already-linked tasks are skipped.
     */
    public function importTasks(): void
    {
        $collection = Collection::tasksFor((int) $this->householdId);
        $linked = $this->entries->pluck('item_id')->filter()->all();
        $today = $this->dayDate()->toDateString();

        $tasks = Item::query()
            ->where('collection_id', $collection->id)
            ->where('created_by', Auth::id())
            ->where('done', false)
            ->whereNotIn('id', $linked)
            ->orderBy('position')
            ->limit(100)
            ->get();

        $position = $this->nextPosition();
        $added = 0;

        foreach ($tasks as $task) {
            FocusEntry::create([
                'household_id' => $this->householdId,
                'user_id' => Auth::id(),
                'item_id' => $task->id,
                'date' => $today,
                'title' => mb_substr($task->title, 0, 255),
                'position' => $position++,
            ]);
            $added++;
        }

        unset($this->entries);
        $this->dispatch('toast', message: $added > 0
            ? trans_choice(':count to-do added.|:count to-dos added.', $added)
            : __('No open to-dos to add.'));
    }

    public function setQuadrant(int $id, string $quadrant): void
    {
        abort_unless(in_array($quadrant, FocusEntry::QUADRANTS, true), 422);

        $entry = $this->find($id);
        // Only the two "important" quadrants stay eligible for a most-important-task star.
        $entry->update(['quadrant' => $quadrant, 'is_mit' => $entry->is_mit && in_array($quadrant, ['q1', 'q2'], true)]);
        unset($this->entries);
    }

    public function clearQuadrant(int $id): void
    {
        $this->find($id)->update(['quadrant' => null, 'is_mit' => false]);
        unset($this->entries);
    }

    public function toggleMit(int $id): void
    {
        $entry = $this->find($id);

        if (! $entry->is_mit && $this->entries->where('is_mit', true)->count() >= FocusEntry::MAX_MITS) {
            $this->dispatch('toast', message: __('Pick at most :count most important tasks for the day.', ['count' => FocusEntry::MAX_MITS]));

            return;
        }

        $entry->update(['is_mit' => ! $entry->is_mit, 'quadrant' => $entry->quadrant ?? 'q1']);
        unset($this->entries);
    }

    /**
     * Proposes the day's most important tasks from the Eisenhower result: the first open entries of
     * "do" (q1), then "schedule" (q2), up to the cap.
     */
    public function suggestMits(): void
    {
        $candidates = $this->entries
            ->whereIn('quadrant', ['q1', 'q2'])
            ->where('done', false)
            ->sortBy(fn (FocusEntry $entry) => [$entry->quadrant === 'q1' ? 0 : 1, $entry->position])
            ->take(FocusEntry::MAX_MITS);

        FocusEntry::query()->forDay(Auth::user(), (int) $this->householdId, $this->dayDate()->toDateString())->update(['is_mit' => false]);
        FocusEntry::whereIn('id', $candidates->pluck('id'))->update(['is_mit' => true]);

        unset($this->entries);
        $this->dispatch('toast', message: $candidates->isEmpty()
            ? __('Sort tasks into "Do first" or "Schedule" first.')
            : __('Top tasks of the day marked.'));
    }

    public function openSchedule(int $id): void
    {
        $entry = $this->find($id);
        $this->scheduling = $entry->id;
        $this->atTime = $entry->start_minute !== null ? sprintf('%02d:%02d', intdiv($entry->start_minute, 60), $entry->start_minute % 60) : '09:00';
        $this->forMinutes = in_array($entry->duration_minutes, self::DURATIONS, true) ? $entry->duration_minutes : 30;
    }

    public function saveSchedule(): void
    {
        if ($this->scheduling === null) {
            return;
        }

        [$h, $m] = array_pad(array_map('intval', explode(':', $this->atTime)), 2, 0);
        $this->find($this->scheduling)->update([
            'start_minute' => max(0, min(1439, $h * 60 + $m)),
            'duration_minutes' => in_array($this->forMinutes, self::DURATIONS, true) ? $this->forMinutes : 30,
        ]);

        $this->scheduling = null;
        unset($this->entries);
    }

    public function unschedule(int $id): void
    {
        $this->find($id)->update(['start_minute' => null]);
        unset($this->entries);
    }

    public function toggleDone(int $id): void
    {
        $entry = $this->find($id);
        $entry->update(['done' => ! $entry->done]);

        // Keep the linked to-do in sync.
        $entry->item?->update(['done' => $entry->done]);
        unset($this->entries);
    }

    public function removeEntry(int $id): void
    {
        $this->find($id)->delete(); // the linked to-do stays in the task list
        unset($this->entries);
    }

    /**
     * @return \Illuminate\Support\Collection<int, FocusEntry>
     */
    #[Computed]
    public function entries(): \Illuminate\Support\Collection
    {
        return FocusEntry::query()
            ->forDay(Auth::user(), (int) $this->householdId, $this->dayDate()->toDateString())
            ->with('item')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $entries = $this->entries;

        return [
            'date' => $this->dayDate(),
            'isToday' => $this->dayDate()->isSameDay(today()),
            'unsorted' => $entries->whereNull('quadrant')->values(),
            'byQuadrant' => collect(FocusEntry::QUADRANTS)->mapWithKeys(fn (string $q) => [$q => $entries->where('quadrant', $q)->values()]),
            'mits' => $entries->where('is_mit', true)->values(),
            'scheduled' => $entries->whereNotNull('start_minute')->sortBy('start_minute')->values(),
            'mitCount' => $entries->where('is_mit', true)->count(),
            'durations' => self::DURATIONS,
        ];
    }
}; ?>

@php
    $quadrantMeta = [
        'q1' => ['label' => __('Do first'), 'hint' => __('urgent & important'), 'class' => 'border-red-300 bg-red-50 dark:border-red-500/40 dark:bg-red-900/10'],
        'q2' => ['label' => __('Schedule'), 'hint' => __('important, not urgent'), 'class' => 'border-emerald-300 bg-emerald-50 dark:border-emerald-500/40 dark:bg-emerald-900/10'],
        'q3' => ['label' => __('Delegate'), 'hint' => __('urgent, not important'), 'class' => 'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-900/10'],
        'q4' => ['label' => __('Eliminate'), 'hint' => __('neither'), 'class' => 'border-gray-300 bg-gray-50 dark:border-gray-600 dark:bg-gray-800/40'],
    ];
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">🎯 {{ __('Focus & time boxing') }}</h2>
    </x-slot>

    <div class="py-6 sm:py-8">
        <div class="mx-auto max-w-5xl space-y-5 px-4 sm:px-6 lg:px-8">
            {{-- Day navigation --}}
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="changeDay(-1)" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200" aria-label="{{ __('Previous day') }}">&larr;</button>
                <h1 class="min-w-[12rem] text-center text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $date->translatedFormat('l, d. M') }}</h1>
                <button type="button" wire:click="changeDay(1)" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-gray-700 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200" aria-label="{{ __('Next day') }}">&rarr;</button>
                @unless ($isToday)
                    <button type="button" wire:click="$set('day', '')" class="text-sm text-indigo-600 underline dark:text-indigo-400">{{ __('Today') }}</button>
                @endunless
                <span class="flex-1"></span>
                <a href="{{ route('journal.index') }}" wire:navigate class="text-sm text-gray-500 hover:underline dark:text-gray-400">{{ __('Routines & journal') }} &rarr;</a>
            </div>

            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Dump everything on your mind, sort it with the Eisenhower matrix, pick the day\'s top tasks and box them into your calendar – the method used by focused managers.') }}</p>

            {{-- 1 · Brain dump --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">🧠 {{ __('Brain dump') }}</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Get it out of your head first – one thought per line.') }}</p>

                <form wire:submit="addBrain" class="mt-3 flex flex-wrap gap-2">
                    <x-text-input wire:model="brain" class="min-w-0 flex-1" placeholder="{{ __('What is on your mind?') }}" />
                    <x-primary-button>+ {{ __('Add') }}</x-primary-button>
                    <button type="button" wire:click="importTasks" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-700">⬇️ {{ __('Pull from to-dos') }}</button>
                </form>
                <label class="mt-2 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                    <input type="checkbox" wire:model="alsoTask" class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900" /> {{ __('Also save as a to-do') }}
                </label>

                @if ($unsorted->isNotEmpty())
                    <ul class="mt-3 space-y-1">
                        @foreach ($unsorted as $entry)
                            <li wire:key="unsorted-{{ $entry->id }}" class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-900/60">
                                <span class="min-w-0 flex-1 text-sm text-gray-900 dark:text-gray-100">{{ $entry->title }}@if ($entry->item_id)<span class="ms-1 text-xs text-indigo-500" title="{{ __('Linked to-do') }}">🔗</span>@endif</span>
                                <span class="flex items-center gap-1">
                                    @foreach ($quadrantMeta as $q => $meta)
                                        <button type="button" wire:click="setQuadrant({{ $entry->id }}, '{{ $q }}')" class="rounded px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-gray-300 hover:bg-white dark:ring-gray-600 dark:hover:bg-gray-700" title="{{ $meta['label'] }} · {{ $meta['hint'] }}">{{ strtoupper($q) }}</button>
                                    @endforeach
                                    <button type="button" wire:click="removeEntry({{ $entry->id }})" class="ms-1 text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- 2 · Eisenhower matrix --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">🧭 {{ __('Prioritise (Eisenhower)') }}</h3>
                    <button type="button" wire:click="suggestMits" class="rounded-md bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-900/40 dark:text-indigo-200">⭐ {{ __('Suggest top :count', ['count' => \App\Models\FocusEntry::MAX_MITS]) }}</button>
                </div>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    @foreach ($quadrantMeta as $q => $meta)
                        <div class="rounded-xl border p-3 {{ $meta['class'] }}">
                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $meta['label'] }} <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $meta['hint'] }}</span></p>
                            <ul class="mt-2 space-y-1">
                                @forelse ($byQuadrant[$q] as $entry)
                                    <li wire:key="q-{{ $entry->id }}" class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:click="toggleDone({{ $entry->id }})" @checked($entry->done) class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900" />
                                        <span @class(['min-w-0 flex-1 break-words text-gray-900 dark:text-gray-100', 'text-gray-400 line-through' => $entry->done])>{{ $entry->title }}</span>
                                        @if (in_array($q, ['q1', 'q2'], true))
                                            <button type="button" wire:click="toggleMit({{ $entry->id }})" class="shrink-0 {{ $entry->is_mit ? 'text-amber-500' : 'text-gray-300 hover:text-amber-400 dark:text-gray-600' }}" title="{{ __('Most important task') }}" aria-label="{{ __('Most important task') }}">★</button>
                                        @endif
                                        <button type="button" wire:click="clearQuadrant({{ $entry->id }})" class="shrink-0 text-gray-300 hover:text-gray-600 dark:text-gray-600" title="{{ __('Back to brain dump') }}" aria-label="{{ __('Back to brain dump') }}">↩</button>
                                    </li>
                                @empty
                                    <li class="text-xs text-gray-400">{{ __('Empty') }}</li>
                                @endforelse
                            </ul>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- 3 · Time boxing --}}
            <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">📅 {{ __('Time boxing') }}</h3>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('Give each important task a fixed slot in your day.') }}</p>

                {{-- Most important tasks, with schedule action --}}
                @if ($mits->isNotEmpty())
                    <div class="mt-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-amber-600 dark:text-amber-300">⭐ {{ __('Top :count today', ['count' => \App\Models\FocusEntry::MAX_MITS]) }}</p>
                        <ul class="mt-1 space-y-1">
                            @foreach ($mits as $entry)
                                <li wire:key="mit-{{ $entry->id }}" class="flex flex-wrap items-center gap-2 rounded-lg bg-amber-50 px-3 py-2 text-sm dark:bg-amber-900/20">
                                    <span @class(['min-w-0 flex-1 break-words text-gray-900 dark:text-gray-100', 'text-gray-400 line-through' => $entry->done])>{{ $entry->title }}</span>
                                    @if ($entry->timeRange())
                                        <span class="rounded bg-white px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-gray-800 dark:text-amber-200">⏰ {{ $entry->timeRange() }}</span>
                                    @endif
                                    <button type="button" wire:click="openSchedule({{ $entry->id }})" class="rounded-md px-2 py-1 text-xs ring-1 ring-amber-300 hover:bg-white dark:ring-amber-500/40 dark:hover:bg-gray-700">{{ $entry->isScheduled() ? __('Change time') : __('Set time') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- The day plan --}}
                <div class="mt-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Day plan') }}</p>
                    @forelse ($scheduled as $entry)
                        <div wire:key="sched-{{ $entry->id }}" class="mt-1 flex items-center gap-3 rounded-lg border-l-4 border-indigo-400 bg-gray-50 px-3 py-2 dark:bg-gray-900/60">
                            <span class="w-24 shrink-0 text-sm font-medium tabular-nums text-gray-700 dark:text-gray-200">{{ $entry->timeRange() }}</span>
                            <input type="checkbox" wire:click="toggleDone({{ $entry->id }})" @checked($entry->done) class="rounded-sm border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900" />
                            <span @class(['min-w-0 flex-1 break-words text-sm text-gray-900 dark:text-gray-100', 'text-gray-400 line-through' => $entry->done])>{{ $entry->is_mit ? '⭐ ' : '' }}{{ $entry->title }}</span>
                            <button type="button" wire:click="openSchedule({{ $entry->id }})" class="shrink-0 text-gray-400 hover:text-indigo-600" aria-label="{{ __('Change time') }}"><x-icon name="pencil" class="h-4 w-4" stroke-width="2" /></button>
                            <button type="button" wire:click="unschedule({{ $entry->id }})" class="shrink-0 text-gray-400 hover:text-red-600" title="{{ __('Remove from plan') }}" aria-label="{{ __('Remove from plan') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                        </div>
                    @empty
                        <p class="mt-1 text-sm text-gray-400">{{ __('Nothing scheduled yet. Mark the day\'s top tasks above and set a time.') }}</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

    {{-- Schedule dialog --}}
    @if ($scheduling)
        <div class="fixed inset-0 z-[60] flex items-end justify-center bg-gray-900/60 p-4 sm:items-center" x-on:keydown.escape.window="$wire.set('scheduling', null)" wire:click.self="$set('scheduling', null)">
            <div class="w-full max-w-xs space-y-4 rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800" role="dialog" aria-modal="true">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('Set time') }}</h3>
                <div class="flex items-end gap-3">
                    <div>
                        <x-input-label for="atTime" :value="__('Start')" />
                        <x-text-input wire:model="atTime" id="atTime" type="time" class="mt-1 block w-full" />
                    </div>
                    <div class="flex-1">
                        <x-input-label for="forMinutes" :value="__('Duration')" />
                        <select wire:model="forMinutes" id="forMinutes" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                            @foreach ($durations as $minutes)
                                <option value="{{ $minutes }}">{{ $minutes }} {{ __('min') }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-3 pt-1">
                    <x-primary-button type="button" wire:click="saveSchedule">{{ __('Save') }}</x-primary-button>
                    <button type="button" wire:click="$set('scheduling', null)" class="text-sm text-gray-500 underline">{{ __('Cancel') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
