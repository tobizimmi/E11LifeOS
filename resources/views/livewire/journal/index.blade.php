<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Habit;
use App\Models\JournalEntry;
use App\Support\Life\HabitTracker;
use App\Support\Life\Journal;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Journal & habits of the member (only visible to them): ticking off habits, mood, activities and
 * the questions of the day, streaks and the year view, and what goes with a good mood.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use WithFileUploads;

    #[Url(except: 'today')]
    public string $tab = 'today';

    /** Day shown (Y-m-d); empty = today. */
    #[Url(except: '')]
    public string $date = '';

    /**
     * Texts of the journal entry of the day.
     *
     * @var array{grateful: string, great_day: string, highlights: string, better: string, notes: string}
     */
    public array $entry = ['grateful' => '', 'great_day' => '', 'highlights' => '', 'better' => '', 'notes' => ''];

    public string $newActivity = '';

    /** Photo while uploading (saved right away). */
    public $photo = null;

    public bool $showHabitForm = false;

    public ?int $editingHabitId = null;

    /**
     * @var array{name: string, icon: string, type: string, target: string, unit: string, schedule: string, days: list<string>, per_week: string, reminder_at: string}
     */
    public array $habitForm = [];

    #[Url(as: 'habit', except: 0)]
    public int $selectedHabitId = 0;

    public int $journalLimit = 30;

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->tab = in_array($this->tab, ['today', 'habits', 'journal', 'insights'], true) ? $this->tab : 'today';
        $this->date = $this->day()->toDateString() === today()->toDateString() ? '' : $this->day()->toDateString();
        $this->loadEntry();
    }

    private function day(): Carbon
    {
        try {
            $day = $this->date !== '' ? Carbon::parse($this->date)->startOfDay() : today();
        } catch (\Throwable) {
            $day = today();
        }

        return $day->gt(today()) ? today() : $day;
    }

    public function shiftDay(int $days): void
    {
        $day = $this->day()->addDays($days);
        $this->date = $day->gte(today()) ? '' : $day->toDateString();
        $this->loadEntry();
    }

    public function showDay(string $date): void
    {
        $this->date = $date;
        $this->date = $this->day()->isToday() ? '' : $this->day()->toDateString();
        $this->tab = 'today';
        $this->loadEntry();
    }

    private function currentEntry(): ?JournalEntry
    {
        return JournalEntry::where('user_id', $this->currentUser()->id)->whereDate('date', $this->day()->toDateString())->first();
    }

    private function entryForDay(): JournalEntry
    {
        return $this->currentEntry() ?? new JournalEntry(['user_id' => $this->currentUser()->id, 'date' => $this->day()->toDateString()]);
    }

    private function loadEntry(): void
    {
        $entry = $this->currentEntry();
        $this->entry = collect(['grateful', 'great_day', 'highlights', 'better', 'notes'])->mapWithKeys(fn (string $field) => [$field => (string) $entry?->{$field}])->all();
    }

    public function updatedEntry(): void
    {
        $this->validate(['entry.*' => ['nullable', 'string', 'max:5000']]);
        $entry = $this->entryForDay();
        $entry->fill(array_map(fn (string $text) => trim($text) !== '' ? trim($text) : null, $this->entry));
        $this->persist($entry);
    }

    public function setMood(int $mood): void
    {
        abort_unless(array_key_exists($mood, Journal::moods()), 422);
        $entry = $this->entryForDay();
        $entry->mood = $entry->mood === $mood ? null : $mood;
        $this->persist($entry);
    }

    public function toggleActivity(string $activity): void
    {
        $activity = mb_substr(trim($activity), 0, 40);

        if ($activity === '') {
            return;
        }

        $entry = $this->entryForDay();
        $activities = $entry->activities ?? [];
        $entry->activities = in_array($activity, $activities, true) ? array_values(array_diff($activities, [$activity])) : [...$activities, $activity];
        $this->persist($entry);
    }

    public function addActivity(): void
    {
        $this->toggleActivity($this->newActivity);
        $this->newActivity = '';
    }

    public function updatedPhoto(): void
    {
        $this->validate(['photo' => ['image', 'max:15360']], attributes: ['photo' => __('Photo')]);
        $entry = $this->entryForDay();

        if (count($entry->photos ?? []) >= Journal::MAX_PHOTOS) {
            $this->addError('photo', __('At most :count photos per day.', ['count' => Journal::MAX_PHOTOS]));

            return;
        }

        $entry->photos = [...($entry->photos ?? []), Journal::storePhoto($this->photo, $this->currentUser())];
        $this->persist($entry);
        $this->photo = null;
    }

    public function deletePhoto(int $index): void
    {
        $entry = $this->currentEntry() ?? abort(404);
        $photos = $entry->photos ?? [];
        abort_unless(isset($photos[$index]), 404);
        Journal::deletePhoto($photos[$index]);
        unset($photos[$index]);
        $entry->photos = array_values($photos);
        $this->persist($entry);
    }

    /**
     * Saves the entry, or removes it when nothing is left in it.
     */
    private function persist(JournalEntry $entry): void
    {
        if ($entry->isEmpty()) {
            $entry->exists && $entry->delete();

            return;
        }

        $entry->save();
    }

    private function findHabit(int $id): Habit
    {
        return Habit::where('user_id', $this->currentUser()->id)->find($id) ?? abort(404);
    }

    public function tapHabit(int $id, int $step = 1): void
    {
        $habit = $this->findHabit($id);
        $value = HabitTracker::tap($habit, $this->day(), $step === -1 ? -1 : 1);

        if ($habit->isFulfilledBy($value) && ! $habit->isFulfilledBy($value - ($habit->type === Habit::COUNT ? 1 : $value))) {
            $this->dispatch('toast', message: __(':habit done – well done!', ['habit' => $habit->icon.' '.$habit->name]));
        }
    }

    /**
     * Ready-made habits to start with.
     *
     * @return array<string, array{name: string, icon: string, type: string, target: int, unit: string, schedule: string, per_week: ?int}>
     */
    public function presets(): array
    {
        return [
            'water' => ['name' => __('Drink enough water'), 'icon' => '💧', 'type' => Habit::COUNT, 'target' => 8, 'unit' => __('glasses'), 'schedule' => Habit::DAILY, 'per_week' => null],
            'sport' => ['name' => __('Sport'), 'icon' => '🏃', 'type' => Habit::CHECK, 'target' => 1, 'unit' => '', 'schedule' => Habit::WEEKLY, 'per_week' => 3],
            'reading' => ['name' => __('Read 20 minutes'), 'icon' => '📖', 'type' => Habit::CHECK, 'target' => 1, 'unit' => '', 'schedule' => Habit::DAILY, 'per_week' => null],
            'meditation' => ['name' => __('Meditate'), 'icon' => '🧘', 'type' => Habit::CHECK, 'target' => 1, 'unit' => '', 'schedule' => Habit::DAILY, 'per_week' => null],
            'steps' => ['name' => __('Walk 10,000 steps'), 'icon' => '🚶', 'type' => Habit::CHECK, 'target' => 1, 'unit' => '', 'schedule' => Habit::DAILY, 'per_week' => null],
            'screen' => ['name' => __('No phone after 10 pm'), 'icon' => '📵', 'type' => Habit::CHECK, 'target' => 1, 'unit' => '', 'schedule' => Habit::DAILY, 'per_week' => null],
        ];
    }

    public function newHabit(?string $preset = null): void
    {
        $base = $this->presets()[$preset] ?? ['name' => '', 'icon' => '✅', 'type' => Habit::CHECK, 'target' => 1, 'unit' => '', 'schedule' => Habit::DAILY, 'per_week' => null];
        $this->resetValidation();
        $this->editingHabitId = null;
        $this->habitForm = ['name' => $base['name'], 'icon' => $base['icon'], 'type' => $base['type'], 'target' => (string) $base['target'], 'unit' => $base['unit'], 'schedule' => $base['schedule'], 'days' => ['1', '2', '3', '4', '5'], 'per_week' => (string) ($base['per_week'] ?? 3), 'reminder_at' => ''];
        $this->showHabitForm = true;
    }

    public function editHabit(int $id): void
    {
        $habit = $this->findHabit($id);
        $this->resetValidation();
        $this->editingHabitId = $habit->id;
        $this->habitForm = ['name' => $habit->name, 'icon' => $habit->icon, 'type' => $habit->type, 'target' => (string) $habit->target, 'unit' => (string) $habit->unit, 'schedule' => $habit->schedule, 'days' => array_map('strval', $habit->days ?? ['1', '2', '3', '4', '5']), 'per_week' => (string) ($habit->per_week ?? 3), 'reminder_at' => (string) $habit->reminder_at];
        $this->showHabitForm = true;
    }

    public function closeHabitForm(): void
    {
        $this->showHabitForm = false;
        $this->editingHabitId = null;
    }

    public function saveHabit(): void
    {
        $data = $this->validate([
            'habitForm.name' => ['required', 'string', 'max:100'],
            'habitForm.icon' => ['nullable', 'string', 'max:16'],
            'habitForm.type' => ['required', 'in:check,count'],
            'habitForm.target' => ['required_if:habitForm.type,count', 'nullable', 'integer', 'min:1', 'max:9999'],
            'habitForm.unit' => ['nullable', 'string', 'max:30'],
            'habitForm.schedule' => ['required', 'in:daily,weekdays,weekly'],
            'habitForm.days' => ['required_if:habitForm.schedule,weekdays', 'array'],
            'habitForm.days.*' => ['integer', 'between:1,7'],
            'habitForm.per_week' => ['required_if:habitForm.schedule,weekly', 'nullable', 'integer', 'min:1', 'max:7'],
            'habitForm.reminder_at' => ['nullable', 'date_format:H:i'],
        ], attributes: ['habitForm.name' => __('Name'), 'habitForm.target' => __('Daily target'), 'habitForm.days' => __('Weekdays'), 'habitForm.per_week' => __('Times per week'), 'habitForm.reminder_at' => __('Reminder')])['habitForm'];

        $attributes = [
            'name' => trim($data['name']),
            'icon' => trim((string) $data['icon']) ?: '✅',
            'type' => $data['type'],
            'target' => $data['type'] === Habit::COUNT ? (int) $data['target'] : 1,
            'unit' => $data['type'] === Habit::COUNT ? (trim((string) $data['unit']) ?: null) : null,
            'schedule' => $data['schedule'],
            'days' => $data['schedule'] === Habit::WEEKDAYS ? array_values(array_map('intval', $data['days'])) : null,
            'per_week' => $data['schedule'] === Habit::WEEKLY ? (int) $data['per_week'] : null,
            'reminder_at' => $data['reminder_at'] ?: null,
        ];

        if ($this->editingHabitId) {
            $this->findHabit($this->editingHabitId)->update($attributes);
        } else {
            $habit = Habit::create($attributes + ['user_id' => $this->currentUser()->id, 'position' => (int) Habit::where('user_id', $this->currentUser()->id)->max('position') + 1]);
            $this->selectedHabitId = $habit->id;
        }

        $this->closeHabitForm();
    }

    public function archiveHabit(int $id): void
    {
        $habit = $this->findHabit($id);
        $habit->update(['archived_at' => $habit->archived_at ? null : now()]);
        $this->closeHabitForm();
    }

    public function deleteHabit(int $id): void
    {
        $this->findHabit($id)->delete();
        $this->selectedHabitId = 0;
        $this->closeHabitForm();
    }

    public function moveHabit(int $id, int $position): void
    {
        $habits = HabitTracker::active($this->currentUser())->values();
        $moving = $habits->firstWhere('id', $id) ?? abort(404);
        $ordered = $habits->reject(fn (Habit $habit) => $habit->id === $moving->id)->values();
        $ordered->splice(max(0, min($position, $ordered->count())), 0, [$moving]);
        $ordered->each(fn (Habit $habit, int $index) => $habit->position !== $index ? $habit->update(['position' => $index]) : null);
    }

    public function with(): array
    {
        $user = $this->currentUser();
        $day = $this->day();
        $habits = HabitTracker::active($user);
        $logs = HabitTracker::logs($habits);
        $stats = $habits->mapWithKeys(fn (Habit $habit) => [$habit->id => HabitTracker::stats($habit, $logs[$habit->id])]);
        $selected = $this->tab === 'habits' ? ($habits->firstWhere('id', $this->selectedHabitId) ?? $habits->first()) : null;
        $entry = $this->currentEntry();

        return [
            'day' => $day,
            'habits' => $habits,
            'logs' => $logs,
            'stats' => $stats,
            'journalEntry' => $entry,
            'moods' => Journal::moods(),
            'questions' => Journal::questions(),
            'activities' => $this->tab === 'today' ? Journal::activities($user) : [],
            'context' => $this->tab === 'today' && $this->householdId ? Journal::dayContext($user, (int) $this->householdId, $day) : null,
            'onThisDay' => $this->tab === 'today' ? Journal::onThisDay($user, $day) : collect(),
            'selected' => $selected,
            'heatmap' => $selected ? HabitTracker::heatmap($selected, $logs[$selected->id]) : [],
            'archived' => $this->tab === 'habits' ? Habit::where('user_id', $user->id)->whereNotNull('archived_at')->orderBy('name')->get() : collect(),
            'entries' => $this->tab === 'journal' ? JournalEntry::where('user_id', $user->id)->orderByDesc('date')->limit($this->journalLimit + 1)->get() : collect(),
            'insights' => $this->tab === 'insights' ? HabitTracker::insights($user) : null,
        ];
    }
}; ?>

@php
    $weekdays = [1 => __('Mo'), 2 => __('Tu'), 3 => __('We'), 4 => __('Th'), 5 => __('Fr'), 6 => __('Sa'), 7 => __('Su')];
    $streakText = fn (array $stat) => $stat['unit'] === 'weeks' ? trans_choice(':count week|:count weeks', $stat['streak']) : trans_choice(':count day|:count days', $stat['streak']);
    $heatColors = ['bg-gray-100 dark:bg-gray-700/60', 'bg-emerald-200 dark:bg-emerald-900', 'bg-emerald-300 dark:bg-emerald-700', 'bg-emerald-500 dark:bg-emerald-500', 'bg-emerald-700 dark:bg-emerald-300'];
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Journal & habits') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see this.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            <div class="flex max-w-full gap-1 overflow-x-auto rounded-lg bg-white p-1 shadow-xs ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" role="tablist">
                @foreach (['today' => '☀️ '.__('Day'), 'habits' => '✅ '.__('Habits'), 'journal' => '📔 '.__('Journal'), 'insights' => '📊 '.__('Evaluation')] as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" @class(['shrink-0 rounded-md px-3 py-1.5 text-sm', 'bg-indigo-600 text-white' => $tab === $key, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $tab !== $key])>{{ $label }}</button>
                @endforeach
            </div>

            @if ($tab === 'today')
                <div class="flex items-center justify-between gap-2">
                    <button type="button" wire:click="shiftDay(-1)" class="rounded-full px-3 py-1.5 text-sm text-gray-600 ring-1 ring-gray-200 hover:bg-white dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-800" aria-label="{{ __('Previous day') }}">←</button>
                    <div class="text-center">
                        <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $day->isToday() ? __('Today') : ($day->isYesterday() ? __('Yesterday') : $day->translatedFormat('l')) }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $day->translatedFormat('j. F Y') }}</p>
                    </div>
                    <button type="button" wire:click="shiftDay(1)" @disabled($day->isToday()) class="rounded-full px-3 py-1.5 text-sm text-gray-600 ring-1 ring-gray-200 hover:bg-white disabled:opacity-30 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-800" aria-label="{{ __('Next day') }}">→</button>
                </div>

                {{-- Habits of the day --}}
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="day-habits">
                    <div class="flex items-center justify-between gap-2">
                        <h3 id="day-habits" class="font-semibold text-gray-900 dark:text-gray-100">✅ {{ __('Habits') }}</h3>
                        <button type="button" wire:click="newHabit" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">＋ {{ __('Habit') }}</button>
                    </div>
                    @php($dueHabits = $habits->filter(fn ($habit) => $habit->isDueOn($day)))
                    @if ($habits->isEmpty())
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Small steps every day: choose a habit to start with.') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($this->presets() as $presetKey => $preset)
                                <button type="button" wire:click="newHabit('{{ $presetKey }}')" class="rounded-full bg-gray-100 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">{{ $preset['icon'] }} {{ $preset['name'] }}</button>
                            @endforeach
                        </div>
                    @else
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($dueHabits as $habit)
                                @php($value = $logs[$habit->id][$day->toDateString()] ?? 0)
                                @php($fulfilled = $habit->isFulfilledBy($value))
                                @php($stat = $stats[$habit->id])
                                <li wire:key="day-habit-{{ $habit->id }}" class="flex items-center gap-3 py-2.5">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xl {{ $fulfilled ? 'bg-emerald-100 dark:bg-emerald-900/50' : 'bg-gray-100 dark:bg-gray-700' }}" aria-hidden="true">{{ $habit->icon }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p @class(['truncate font-medium', 'text-gray-900 dark:text-gray-100' => ! $fulfilled, 'text-emerald-700 dark:text-emerald-300' => $fulfilled])>{{ $habit->name }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            @if ($habit->schedule === 'weekly')
                                                {{ __(':done of :target this week', ['done' => $stat['week'], 'target' => $habit->per_week]) }}
                                            @endif
                                            @if ($stat['streak'] > 0)<span class="text-orange-600 dark:text-orange-300">🔥 {{ $streakText($stat) }}</span>@endif
                                            @if ($habit->schedule !== 'weekly' && $stat['streak'] === 0){{ __('Strength :percent %', ['percent' => $stat['strength']]) }}@endif
                                        </p>
                                    </div>
                                    @if ($habit->type === 'count')
                                        <div class="flex items-center gap-1">
                                            <button type="button" wire:click="tapHabit({{ $habit->id }}, -1)" @disabled($value === 0) class="h-9 w-9 rounded-full bg-gray-100 text-lg text-gray-600 hover:bg-gray-200 disabled:opacity-30 dark:bg-gray-700 dark:text-gray-300" aria-label="{{ __('One less') }}">−</button>
                                            <span @class(['min-w-14 text-center text-sm font-semibold tabular-nums', 'text-emerald-700 dark:text-emerald-300' => $fulfilled, 'text-gray-900 dark:text-gray-100' => ! $fulfilled])>{{ $value }}/{{ $habit->target }}@if ($habit->unit)<span class="block text-[10px] font-normal text-gray-500">{{ $habit->unit }}</span>@endif</span>
                                            <button type="button" wire:click="tapHabit({{ $habit->id }})" class="h-9 w-9 rounded-full bg-indigo-600 text-lg text-white hover:bg-indigo-500" aria-label="{{ __('One more') }}">＋</button>
                                        </div>
                                    @else
                                        <button type="button" wire:click="tapHabit({{ $habit->id }})" aria-pressed="{{ $fulfilled ? 'true' : 'false' }}" @class(['flex h-10 w-10 items-center justify-center rounded-full text-lg transition', 'bg-emerald-500 text-white' => $fulfilled, 'ring-2 ring-gray-300 text-transparent hover:ring-emerald-400 dark:ring-gray-600' => ! $fulfilled]) aria-label="{{ $fulfilled ? __('Not done') : __('Done') }}: {{ $habit->name }}">✓</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($dueHabits->count() < $habits->count())
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Free today: :habits', ['habits' => $habits->reject(fn ($habit) => $habit->isDueOn($day))->map(fn ($habit) => $habit->icon.' '.$habit->name)->implode(', ')]) }}</p>
                        @endif
                    @endif
                </section>

                {{-- Mood and activities --}}
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="day-mood">
                    <h3 id="day-mood" class="font-semibold text-gray-900 dark:text-gray-100">{{ __('How was your day?') }}</h3>
                    <div class="mt-3 flex justify-between gap-1 sm:justify-start sm:gap-3" role="group" aria-label="{{ __('Mood') }}">
                        @foreach ($moods as $moodValue => $mood)
                            <button type="button" wire:click="setMood({{ $moodValue }})" aria-pressed="{{ $journalEntry?->mood === $moodValue ? 'true' : 'false' }}" @class(['flex flex-col items-center rounded-2xl px-2 py-1.5 text-xs transition sm:px-3', 'bg-indigo-100 text-indigo-900 ring-2 ring-indigo-400 dark:bg-indigo-900/50 dark:text-indigo-100' => $journalEntry?->mood === $moodValue, 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $journalEntry?->mood !== $moodValue])>
                                <span class="text-3xl" aria-hidden="true">{{ $mood['icon'] }}</span>{{ $mood['label'] }}
                            </button>
                        @endforeach
                    </div>
                    <div class="mt-4 flex flex-wrap gap-1.5">
                        @foreach ($activities as $activity)
                            @php($chosen = in_array($activity, $journalEntry?->activities ?? [], true))
                            <button type="button" wire:click="toggleActivity(@js($activity))" aria-pressed="{{ $chosen ? 'true' : 'false' }}" @class(['rounded-full px-3 py-1 text-sm', 'bg-indigo-600 text-white' => $chosen, 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600' => ! $chosen])>{{ $activity }}</button>
                        @endforeach
                        <form wire:submit="addActivity" class="flex">
                            <input type="text" wire:model="newActivity" maxlength="40" placeholder="＋ {{ __('own') }}" aria-label="{{ __('Own activity') }}" class="w-28 rounded-full border-gray-300 px-3 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">
                        </form>
                    </div>
                </section>

                {{-- Questions of the day --}}
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="day-questions">
                    <h3 id="day-questions" class="font-semibold text-gray-900 dark:text-gray-100">📔 {{ __('Journal') }}</h3>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        @foreach (['morning' => '🌅 '.__('Morning'), 'evening' => '🌙 '.__('Evening')] as $part => $partLabel)
                            <div class="space-y-3">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $partLabel }}</p>
                                @foreach (collect($questions)->where('part', $part) as $field => $question)
                                    <label class="block">
                                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ $question['label'] }}</span>
                                        <textarea wire:model.blur="entry.{{ $field }}" rows="2" placeholder="{{ $question['placeholder'] }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                                    </label>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <label class="mt-4 block">
                        <span class="text-sm text-gray-800 dark:text-gray-200">{{ __('Notes') }}</span>
                        <textarea wire:model.blur="entry.notes" rows="4" placeholder="{{ __('What moved you today?') }}" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"></textarea>
                    </label>
                    <x-input-error :messages="$errors->get('entry.*')" class="mt-1" />

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        @foreach ($journalEntry?->photos ?? [] as $photoIndex => $photoPath)
                            <div class="group relative" wire:key="photo-{{ md5($photoPath) }}">
                                <a href="{{ route('journal.photo', [$journalEntry, $photoIndex, 'v' => substr(md5($photoPath), 0, 8)]) }}" target="_blank"><img src="{{ route('journal.photo', [$journalEntry, $photoIndex, 'v' => substr(md5($photoPath), 0, 8)]) }}" alt="" loading="lazy" class="h-20 w-20 rounded-xl object-cover"></a>
                                <button type="button" wire:click="deletePhoto({{ $photoIndex }})" wire:confirm="{{ __('Delete this photo?') }}" class="absolute -end-1.5 -top-1.5 flex h-6 w-6 items-center justify-center rounded-full bg-white text-xs text-gray-600 shadow ring-1 ring-gray-200 hover:text-red-600 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-600" aria-label="{{ __('Delete photo') }}">✕</button>
                            </div>
                        @endforeach
                        @if (count($journalEntry?->photos ?? []) < \App\Support\Life\Journal::MAX_PHOTOS)
                            <label class="flex h-20 w-20 cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-300 text-xs text-gray-500 hover:border-indigo-400 hover:text-indigo-600 dark:border-gray-600 dark:text-gray-400">
                                <span wire:loading.remove wire:target="photo" class="text-xl" aria-hidden="true">📷</span><span wire:loading wire:target="photo">⏳</span>
                                {{ __('Photo') }}
                                <input type="file" wire:model="photo" accept="image/*" class="sr-only">
                            </label>
                        @endif
                    </div>
                    <x-input-error :messages="$errors->get('photo')" class="mt-1" />
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('Saved automatically. Texts are stored encrypted.') }}</p>
                </section>

                {{-- What else happened that day --}}
                @if ($context && ($context['events'] !== [] || $context['tasks']->isNotEmpty() || $context['trips']->isNotEmpty()))
                    <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="day-context">
                        <h3 id="day-context" class="font-semibold text-gray-900 dark:text-gray-100">🗓️ {{ __('The day in E11LifeOS') }}</h3>
                        <ul class="mt-2 space-y-1.5 text-sm">
                            @foreach ($context['trips'] as $trip)
                                <li><a href="{{ route('trips.show', $trip) }}" wire:navigate class="text-gray-800 hover:underline dark:text-gray-200">🧳 {{ $trip->title }}</a></li>
                            @endforeach
                            @foreach ($context['events'] as $event)
                                <li><a href="{{ $event['url'] }}" wire:navigate class="text-gray-800 hover:underline dark:text-gray-200">{{ $event['icon'] }} @if ($event['time'])<span class="tabular-nums text-gray-500">{{ $event['time'] }}</span> @endif{{ $event['title'] }}</a></li>
                            @endforeach
                            @foreach ($context['tasks'] as $task)
                                <li class="text-gray-600 dark:text-gray-300">✔️ {{ $task->title }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($onThisDay->isNotEmpty())
                    <section class="rounded-2xl bg-amber-50/70 p-5 ring-1 ring-amber-100 dark:bg-amber-900/10 dark:ring-amber-900/40" aria-labelledby="day-memories">
                        <h3 id="day-memories" class="font-semibold text-amber-900 dark:text-amber-200">🕰️ {{ __('On this day') }}</h3>
                        <ul class="mt-2 space-y-2">
                            @foreach ($onThisDay as $memory)
                                <li wire:key="memory-{{ $memory->id }}">
                                    <button type="button" wire:click="showDay('{{ $memory->date->toDateString() }}')" class="text-left text-sm">
                                        <span class="font-medium text-amber-900 dark:text-amber-100">{{ trans_choice(':count year ago|:count years ago', $day->year - $memory->date->year) }} {{ $memory->mood ? $moods[$memory->mood]['icon'] : '' }}</span>
                                        <span class="block text-amber-800 dark:text-amber-200">{{ \Illuminate\Support\Str::limit($memory->highlights ?: ($memory->notes ?: ($memory->grateful ?: implode(', ', $memory->activities ?? []))), 160) }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif
            @elseif ($tab === 'habits')
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="habit-list">
                    <div class="flex items-center justify-between gap-2">
                        <h3 id="habit-list" class="font-semibold text-gray-900 dark:text-gray-100">✅ {{ __('My habits') }}</h3>
                        <x-primary-button type="button" wire:click="newHabit">＋ {{ __('Habit') }}</x-primary-button>
                    </div>
                    @if ($habits->isEmpty())
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($this->presets() as $presetKey => $preset)
                                <button type="button" wire:click="newHabit('{{ $presetKey }}')" class="rounded-full bg-gray-100 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">{{ $preset['icon'] }} {{ $preset['name'] }}</button>
                            @endforeach
                        </div>
                    @else
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700" x-sort="$wire.moveHabit($item, $position)">
                            @foreach ($habits as $habit)
                                @php($stat = $stats[$habit->id])
                                <li wire:key="habit-{{ $habit->id }}" x-sort:item="{{ $habit->id }}" @class(['flex items-center gap-3 py-2.5', 'rounded-lg bg-indigo-50/60 dark:bg-indigo-900/20' => $selected?->id === $habit->id])>
                                    <span x-sort:handle class="cursor-grab px-1 text-gray-300 hover:text-gray-500 dark:text-gray-600" aria-hidden="true">⠿</span>
                                    <button type="button" wire:click="$set('selectedHabitId', {{ $habit->id }})" class="flex min-w-0 flex-1 items-center gap-3 text-left">
                                        <span class="text-2xl" aria-hidden="true">{{ $habit->icon }}</span>
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $habit->name }}</span>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                                {{ match ($habit->schedule) { 'weekdays' => collect($habit->days ?? [])->map(fn ($dayNumber) => $weekdays[$dayNumber] ?? '')->implode(', '), 'weekly' => trans_choice(':count time a week|:count times a week', (int) $habit->per_week), default => __('daily') } }}
                                                @if ($habit->type === 'count') · {{ $habit->target }} {{ $habit->unit }}@endif
                                                @if ($habit->reminder_at) · ⏰ {{ $habit->reminder_at }}@endif
                                            </span>
                                        </span>
                                    </button>
                                    <div class="hidden text-right text-xs sm:block">
                                        <p class="text-orange-600 dark:text-orange-300">🔥 {{ $streakText($stat) }}</p>
                                        <p class="text-gray-500 dark:text-gray-400">{{ __('Best: :count', ['count' => $stat['best']]) }} · {{ __(':percent % in 30 days', ['percent' => $stat['rate']]) }}</p>
                                    </div>
                                    <div class="w-12 text-center" title="{{ __('Habit strength') }}">
                                        <svg viewBox="0 0 36 36" class="h-10 w-10" role="img" aria-label="{{ __('Strength :percent %', ['percent' => $stat['strength']]) }}">
                                            <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" class="stroke-gray-200 dark:stroke-gray-700" />
                                            <circle cx="18" cy="18" r="15.5" fill="none" stroke-width="3.5" stroke-linecap="round" class="stroke-emerald-500" stroke-dasharray="{{ round($stat['strength'] * 0.974, 1) }} 100" transform="rotate(-90 18 18)" />
                                            <text x="18" y="21.5" text-anchor="middle" class="fill-gray-700 text-[9px] font-semibold dark:fill-gray-200">{{ $stat['strength'] }}</text>
                                        </svg>
                                    </div>
                                    <button type="button" wire:click="editHabit({{ $habit->id }})" class="rounded-lg px-2 py-1 text-sm text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700" aria-label="{{ __('Edit :habit', ['habit' => $habit->name]) }}">✎</button>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('The ring shows the habit strength: it grows with every planned day you do it, and a missed day costs only a little.') }}</p>
                    @endif
                </section>

                @if ($selected)
                    @php($stat = $stats[$selected->id])
                    <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="habit-year">
                        <h3 id="habit-year" class="font-semibold text-gray-900 dark:text-gray-100">{{ $selected->icon }} {{ $selected->name }} · {{ __('the last year') }}</h3>
                        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40"><dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Current streak') }}</dt><dd class="text-lg font-semibold text-gray-900 dark:text-gray-100">🔥 {{ $streakText($stat) }}</dd></div>
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40"><dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Best streak') }}</dt><dd class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $stat['unit'] === 'weeks' ? trans_choice(':count week|:count weeks', $stat['best']) : trans_choice(':count day|:count days', $stat['best']) }}</dd></div>
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40"><dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Habit strength') }}</dt><dd class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $stat['strength'] }} %</dd></div>
                            <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/40"><dt class="text-xs text-gray-500 dark:text-gray-400">{{ __('Last 30 days') }}</dt><dd class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $stat['rate'] }} %</dd></div>
                        </dl>
                        <div class="mt-4 overflow-x-auto pb-1" x-data x-init="$el.scrollLeft = $el.scrollWidth">
                            <div class="flex gap-[3px]" role="img" aria-label="{{ __('Year view of :habit', ['habit' => $selected->name]) }}">
                                @foreach ($heatmap as $week)
                                    <div class="flex flex-col gap-[3px]">
                                        @foreach ($week as $cell)
                                            <span @class(['h-3 w-3 rounded-[3px]', 'opacity-0' => $cell['future'], $heatColors[$cell['level']] => ! $cell['future'], 'opacity-40' => ! $cell['future'] && ! $cell['due'] && $cell['level'] === 0]) title="{{ \Illuminate\Support\Carbon::parse($cell['date'])->translatedFormat('D, j. M Y') }}{{ $cell['value'] > 0 ? ': '.$cell['value'] : '' }}"></span>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <p class="mt-2 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">{{ __('less') }} @foreach ($heatColors as $color)<span class="h-3 w-3 rounded-[3px] {{ $color }}"></span>@endforeach {{ __('more done') }}</p>
                    </section>
                @endif

                @if ($archived->isNotEmpty())
                    <details class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800">
                        <summary class="cursor-pointer text-sm font-semibold text-gray-700 dark:text-gray-200">🗄️ {{ __('Archived habits') }} ({{ $archived->count() }})</summary>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($archived as $habit)
                                <li wire:key="archived-{{ $habit->id }}" class="flex items-center gap-2">
                                    <span class="min-w-0 flex-1 text-gray-600 dark:text-gray-300">{{ $habit->icon }} {{ $habit->name }}</span>
                                    <button type="button" wire:click="archiveHabit({{ $habit->id }})" class="text-xs text-indigo-600 hover:underline dark:text-indigo-400">{{ __('Restore') }}</button>
                                    <button type="button" wire:click="deleteHabit({{ $habit->id }})" wire:confirm="{{ __('Delete this habit with all its entries?') }}" class="text-xs text-gray-400 hover:text-red-600">{{ __('Delete') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            @elseif ($tab === 'journal')
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="journal-list">
                    <h3 id="journal-list" class="font-semibold text-gray-900 dark:text-gray-100">📔 {{ __('All entries') }}</h3>
                    @if ($entries->isEmpty())
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('No entries yet – write your first one on the “Day” tab.') }}</p>
                    @else
                        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($entries->take($journalLimit) as $listEntry)
                                <li wire:key="entry-{{ $listEntry->id }}">
                                    <button type="button" wire:click="showDay('{{ $listEntry->date->toDateString() }}')" class="flex w-full items-start gap-3 py-2.5 text-left hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                        <span class="w-12 shrink-0 text-center"><span class="block text-2xl" aria-hidden="true">{{ $listEntry->mood ? $moods[$listEntry->mood]['icon'] : '📝' }}</span></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $listEntry->date->translatedFormat('D, j. F Y') }}@if (count($listEntry->photos ?? [])) <span class="text-xs text-gray-500">· 📷 {{ count($listEntry->photos) }}</span>@endif</span>
                                            @if ($listEntry->activities)<span class="block truncate text-xs text-indigo-700 dark:text-indigo-300">{{ implode(' · ', $listEntry->activities) }}</span>@endif
                                            <span class="line-clamp-2 text-sm text-gray-600 dark:text-gray-300">{{ $listEntry->highlights ?: ($listEntry->notes ?: $listEntry->grateful) }}</span>
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        @if ($entries->count() > $journalLimit)
                            <div class="mt-3 text-center"><x-secondary-button type="button" wire:click="$set('journalLimit', {{ $journalLimit + 30 }})">{{ __('Show more') }}</x-secondary-button></div>
                        @endif
                    @endif
                </section>
            @elseif ($tab === 'insights')
                <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="insights-mood">
                    <h3 id="insights-mood" class="font-semibold text-gray-900 dark:text-gray-100">😊 {{ __('Mood in the last 90 days') }}@if ($insights['average'] !== null) <span class="font-normal text-gray-500 dark:text-gray-400">· Ø {{ number_format($insights['average'], 1, ',', '.') }}</span>@endif</h3>
                    @if ($insights['moods'] === [])
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Choose your mood on the “Day” tab – after a few days you see how it develops and what goes with good days.') }}</p>
                    @else
                        @php($moodByDate = collect($insights['moods'])->pluck('mood', 'date'))
                        <div class="mt-4 flex h-28 items-end gap-px" role="img" aria-label="{{ __('Mood per day') }}">
                            @for ($offset = 89; $offset >= 0; $offset--)
                                @php($moodDate = today()->subDays($offset)->toDateString())
                                @php($moodValue = $moodByDate[$moodDate] ?? null)
                                <span class="flex-1 rounded-t-sm {{ $moodValue ? ['', 'bg-red-400', 'bg-orange-400', 'bg-amber-300', 'bg-lime-400', 'bg-emerald-500'][$moodValue] : 'bg-gray-100 dark:bg-gray-700/50' }}" style="height: {{ $moodValue ? $moodValue * 20 : 4 }}%" title="{{ \Illuminate\Support\Carbon::parse($moodDate)->translatedFormat('D, j. M') }}{{ $moodValue ? ': '.$moods[$moodValue]['label'] : '' }}"></span>
                            @endfor
                        </div>
                        <div class="mt-1 flex justify-between text-[11px] text-gray-500 dark:text-gray-400"><span>{{ today()->subDays(89)->translatedFormat('j. M') }}</span><span>{{ __('Today') }}</span></div>
                    @endif
                </section>

                <div class="grid gap-5 md:grid-cols-2">
                    <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="insights-activities">
                        <h3 id="insights-activities" class="font-semibold text-gray-900 dark:text-gray-100">🏷️ {{ __('Activities and mood') }}</h3>
                        @forelse ($insights['activities'] as $row)
                            <div class="mt-2 flex items-center gap-2 text-sm">
                                <span class="min-w-0 flex-1 truncate text-gray-800 dark:text-gray-200">{{ $row['label'] }} <span class="text-xs text-gray-500">({{ $row['count'] }}×)</span></span>
                                <span class="tabular-nums text-gray-600 dark:text-gray-300">Ø {{ number_format($row['average'], 1, ',', '.') }}</span>
                                <span @class(['w-12 text-end text-xs font-semibold tabular-nums', 'text-emerald-600 dark:text-emerald-400' => $row['diff'] > 0, 'text-red-600 dark:text-red-400' => $row['diff'] < 0, 'text-gray-400' => $row['diff'] == 0])>{{ $row['diff'] > 0 ? '+' : '' }}{{ number_format($row['diff'], 1, ',', '.') }}</span>
                            </div>
                        @empty
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('Tag your days with activities to see which ones go with a good mood.') }}</p>
                        @endforelse
                    </section>
                    <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="insights-habits">
                        <h3 id="insights-habits" class="font-semibold text-gray-900 dark:text-gray-100">✅ {{ __('Habits and mood') }}</h3>
                        @forelse ($insights['habits'] as $row)
                            <div class="mt-2 text-sm">
                                <p class="text-gray-800 dark:text-gray-200">{{ $row['habit']->icon }} {{ $row['habit']->name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Mood on days with: Ø :with · without: Ø :without', ['with' => number_format($row['with'], 1, ',', '.'), 'without' => number_format($row['without'], 1, ',', '.')]) }}
                                    <span @class(['font-semibold', 'text-emerald-600 dark:text-emerald-400' => $row['diff'] > 0, 'text-red-600 dark:text-red-400' => $row['diff'] < 0])>({{ $row['diff'] > 0 ? '+' : '' }}{{ number_format($row['diff'], 1, ',', '.') }})</span></p>
                            </div>
                        @empty
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ __('After a few weeks with habits and mood you see here which habits do you good.') }}</p>
                        @endforelse
                    </section>
                </div>

                @if ($habits->isNotEmpty())
                    <section class="rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800" aria-labelledby="insights-rates">
                        <h3 id="insights-rates" class="font-semibold text-gray-900 dark:text-gray-100">🎯 {{ __('Done in the last 30 days') }}</h3>
                        @foreach ($habits as $habit)
                            <div class="mt-2 flex items-center gap-3 text-sm">
                                <span class="w-32 truncate text-gray-800 sm:w-40 dark:text-gray-200">{{ $habit->icon }} {{ $habit->name }}</span>
                                <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full bg-emerald-500" style="width: {{ $stats[$habit->id]['rate'] }}%"></div></div>
                                <span class="w-12 whitespace-nowrap text-end tabular-nums text-gray-600 dark:text-gray-300">{{ $stats[$habit->id]['rate'] }} %</span>
                            </div>
                        @endforeach
                    </section>
                @endif
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Connections, not proof: a habit on good days does not have to be the reason for them.') }}</p>
            @endif
        </div>
    </div>

    @if ($showHabitForm)
        <x-sheet close="closeHabitForm" :label="$editingHabitId ? __('Edit habit') : __('New habit')" size="md" wire:key="habit-form-{{ $editingHabitId ?? 'new' }}">
            <x-slot:header><strong class="text-gray-800 dark:text-gray-100">{{ $editingHabitId ? __('Edit habit') : __('New habit') }}</strong></x-slot:header>
            <form wire:submit="saveHabit" id="habit-form" class="space-y-4 p-5">
                <div class="flex gap-3">
                    <div class="w-20">
                        <x-input-label for="habit-icon" :value="__('Icon')" />
                        <x-text-input id="habit-icon" wire:model="habitForm.icon" maxlength="16" class="mt-1 block w-full text-center text-xl" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <x-input-label for="habit-name" :value="__('Name')" />
                        <x-text-input id="habit-name" wire:model="habitForm.name" class="mt-1 block w-full" placeholder="{{ __('e.g. Read 20 minutes') }}" />
                        <x-input-error :messages="$errors->get('habitForm.name')" class="mt-1" />
                    </div>
                </div>
                <div>
                    <x-input-label :value="__('Kind')" />
                    <div class="mt-1 inline-flex rounded-lg bg-gray-100 p-0.5 text-sm dark:bg-gray-900" role="group">
                        @foreach (['check' => __('Tick off'), 'count' => __('Count up')] as $typeKey => $typeLabel)
                            <button type="button" wire:click="$set('habitForm.type', '{{ $typeKey }}')" aria-pressed="{{ ($habitForm['type'] ?? '') === $typeKey ? 'true' : 'false' }}" @class(['rounded-md px-3 py-1', 'bg-white font-medium shadow-xs dark:bg-gray-700 dark:text-gray-100' => ($habitForm['type'] ?? '') === $typeKey, 'text-gray-600 dark:text-gray-400' => ($habitForm['type'] ?? '') !== $typeKey])>{{ $typeLabel }}</button>
                        @endforeach
                    </div>
                    @if (($habitForm['type'] ?? '') === 'count')
                        <div class="mt-2 flex gap-2">
                            <div class="w-28"><x-input-label for="habit-target" :value="__('Daily target')" /><x-text-input id="habit-target" type="number" min="1" wire:model="habitForm.target" class="mt-1 block w-full" /></div>
                            <div class="min-w-0 flex-1"><x-input-label for="habit-unit" :value="__('Unit')" /><x-text-input id="habit-unit" wire:model="habitForm.unit" class="mt-1 block w-full" placeholder="{{ __('e.g. glasses, pages, km') }}" /></div>
                        </div>
                        <x-input-error :messages="$errors->get('habitForm.target')" class="mt-1" />
                    @endif
                </div>
                <div>
                    <x-input-label :value="__('How often')" />
                    <div class="mt-1 inline-flex flex-wrap rounded-lg bg-gray-100 p-0.5 text-sm dark:bg-gray-900" role="group">
                        @foreach (['daily' => __('Every day'), 'weekdays' => __('On certain days'), 'weekly' => __('x times a week')] as $scheduleKey => $scheduleLabel)
                            <button type="button" wire:click="$set('habitForm.schedule', '{{ $scheduleKey }}')" aria-pressed="{{ ($habitForm['schedule'] ?? '') === $scheduleKey ? 'true' : 'false' }}" @class(['rounded-md px-3 py-1', 'bg-white font-medium shadow-xs dark:bg-gray-700 dark:text-gray-100' => ($habitForm['schedule'] ?? '') === $scheduleKey, 'text-gray-600 dark:text-gray-400' => ($habitForm['schedule'] ?? '') !== $scheduleKey])>{{ $scheduleLabel }}</button>
                        @endforeach
                    </div>
                    @if (($habitForm['schedule'] ?? '') === 'weekdays')
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach ($weekdays as $dayNumber => $dayLabel)
                                <label class="cursor-pointer"><input type="checkbox" wire:model="habitForm.days" value="{{ $dayNumber }}" class="peer sr-only"><span class="block rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700 peer-checked:bg-indigo-600 peer-checked:text-white dark:bg-gray-700 dark:text-gray-200">{{ $dayLabel }}</span></label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('habitForm.days')" class="mt-1" />
                    @elseif (($habitForm['schedule'] ?? '') === 'weekly')
                        <div class="mt-2 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><x-text-input type="number" min="1" max="7" wire:model="habitForm.per_week" class="w-20" aria-label="{{ __('Times per week') }}" /> {{ __('times a week, on any day') }}</div>
                        <x-input-error :messages="$errors->get('habitForm.per_week')" class="mt-1" />
                    @endif
                </div>
                <div>
                    <x-input-label for="habit-reminder" :value="__('Reminder (push)')" />
                    <x-text-input id="habit-reminder" type="time" wire:model="habitForm.reminder_at" class="mt-1 block w-36" />
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('Only when the habit is still open that day.') }}</p>
                    <x-input-error :messages="$errors->get('habitForm.reminder_at')" class="mt-1" />
                </div>
            </form>
            <x-slot:footer>
                <div class="flex flex-wrap items-center gap-2">
                    <x-primary-button form="habit-form">{{ __('Save') }}</x-primary-button>
                    @if ($editingHabitId)
                        <button type="button" wire:click="archiveHabit({{ $editingHabitId }})" class="text-sm text-gray-600 hover:underline dark:text-gray-300">🗄️ {{ __('Move to archive') }}</button>
                        <button type="button" wire:click="deleteHabit({{ $editingHabitId }})" wire:confirm="{{ __('Delete this habit with all its entries?') }}" class="ms-auto text-sm text-red-600 hover:underline">{{ __('Delete') }}</button>
                    @endif
                </div>
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
