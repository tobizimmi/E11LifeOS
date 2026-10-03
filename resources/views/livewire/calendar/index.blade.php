<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\CalendarSubscription;
use App\Models\Collection;
use App\Models\Item;
use App\Support\CalendarEvents;
use App\Support\IcsCalendar;
use App\Support\QuickCapture;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * One calendar for all modules: tasks, deadlines, meals, property and rent dates, invoices and
 * portfolio dates. Tasks can be dragged to another day; a click on a day shows it and adds tasks.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public const VIEWS = ['month', 'week', 'agenda'];

    private const AGENDA_DAYS = 30;

    #[Url(except: 'month')]
    public string $view = 'month';

    /**
     * Any day of the shown month or week (Y-m-d).
     */
    #[Url(as: 'date', except: '')]
    public string $anchor = '';

    #[Url(as: 'day', except: '')]
    public string $selectedDay = '';

    /**
     * @var list<string>
     */
    #[Url(as: 'hide', except: [])]
    public array $hidden = [];

    public string $newTask = '';

    public string $subscriptionName = '';

    public string $subscriptionUrl = '';

    /** New calendars are private unless the member shares them with the household. */
    public bool $subscriptionShared = false;

    public function mount(): void
    {
        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'month';
        $this->anchor = $this->validDate($this->anchor) ?? today()->toDateString();
        $this->selectedDay = $this->validDate($this->selectedDay) ?? today()->toDateString();
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : null;
    }

    public function setView(string $view): void
    {
        abort_unless(in_array($view, self::VIEWS, true), 404);
        $this->view = $view;
    }

    public function previous(): void
    {
        $this->shift(-1);
    }

    public function next(): void
    {
        $this->shift(1);
    }

    private function shift(int $direction): void
    {
        $anchor = Carbon::parse($this->anchor);
        $this->anchor = match ($this->view) {
            'month' => $anchor->addMonthsNoOverflow($direction)->toDateString(),
            'week' => $anchor->addWeeks($direction)->toDateString(),
            default => $anchor->addDays($direction * self::AGENDA_DAYS)->toDateString(),
        };
    }

    public function goToToday(): void
    {
        $this->anchor = today()->toDateString();
        $this->selectedDay = today()->toDateString();
    }

    public function selectDay(string $date): void
    {
        $this->selectedDay = $this->validDate($date) ?? abort(422);
        $this->resetErrorBag('newTask');
        $this->dispatch('calendar-day-selected');
    }

    public function toggleSource(string $source): void
    {
        abort_unless(array_key_exists($source, CalendarEvents::sources(Auth::user())), 404);

        $this->hidden = in_array($source, $this->hidden, true)
            ? array_values(array_diff($this->hidden, [$source]))
            : [...$this->hidden, $source];
    }

    /**
     * Drag & drop: a task was dropped on another day.
     */
    public function moveTask(int $taskId, string $date): void
    {
        $date = $this->validDate($date) ?? abort(422);
        $collection = Collection::tasksFor((int) $this->householdId)->load('properties');
        $task = Item::query()->forHousehold((int) $this->householdId)->visibleTo(Auth::user())
            ->where('collection_id', $collection->id)->find($taskId) ?? abort(404);
        $property = $collection->systemProperty('due');

        $task->setPropertyValue($property, $property->normalizeValue($date, []));
        $task->save();

        $this->dispatch('toast', message: __(':title moved to :date', ['title' => \Illuminate\Support\Str::limit($task->title, 40), 'date' => Carbon::parse($date)->translatedFormat('D, j. M')]));
    }

    /**
     * New task on the selected day (the quick input understands times, #tags and !priority).
     */
    public function addTask(): void
    {
        $task = QuickCapture::task(Auth::user(), (int) $this->householdId, $this->newTask, $this->selectedDay);

        if (! $task) {
            $this->addError('newTask', __('Please enter a title.'));

            return;
        }

        $this->reset('newTask');
        $this->dispatch('toast', message: __('Task added'));
    }

    /**
     * Subscribes to an external calendar (ICS link, e.g. the secret address from Google Calendar).
     */
    public function addSubscription(): void
    {
        $this->validate([
            'subscriptionName' => ['required', 'string', 'max:100'],
            'subscriptionUrl' => ['required', 'string', 'max:2000', fn ($attribute, $value, $fail) => IcsCalendar::validUrl((string) $value) ? null : $fail(__('Enter the calendar address (ICS link, starts with https:// or webcal://).'))],
        ], attributes: ['subscriptionName' => __('Name'), 'subscriptionUrl' => __('Calendar address')]);

        $subscription = CalendarSubscription::create([
            'user_id' => Auth::id(),
            'household_id' => $this->householdId,
            'name' => trim($this->subscriptionName),
            'url' => trim($this->subscriptionUrl),
            'visibility' => $this->subscriptionShared ? Item::VISIBILITY_SHARED : Item::VISIBILITY_PRIVATE,
        ]);
        IcsCalendar::fetch($subscription);
        $this->reset('subscriptionName', 'subscriptionUrl', 'subscriptionShared');
        $this->dispatch('toast', message: $subscription->fresh()->error ? __('Saved, but the calendar could not be loaded yet.') : __('Calendar added'));
    }

    public function deleteSubscription(int $id): void
    {
        (CalendarSubscription::where('user_id', Auth::id())->find($id) ?? abort(404))->delete();
    }

    /**
     * Switches one of the member's own calendars between private and shared with the household.
     */
    public function toggleSubscriptionVisibility(int $id): void
    {
        $subscription = CalendarSubscription::where('user_id', Auth::id())->find($id) ?? abort(404);
        $subscription->update([
            'household_id' => $this->householdId,
            'visibility' => $subscription->isPrivate() ? Item::VISIBILITY_SHARED : Item::VISIBILITY_PRIVATE,
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(): array
    {
        $anchor = Carbon::parse($this->anchor);

        return match ($this->view) {
            'month' => [$anchor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY)],
            'week' => [$anchor->copy()->startOfWeek(Carbon::MONDAY), $anchor->copy()->endOfWeek(Carbon::SUNDAY)],
            default => [$anchor->copy()->startOfDay(), $anchor->copy()->addDays(self::AGENDA_DAYS - 1)],
        };
    }

    public function with(): array
    {
        [$from, $until] = $this->range();
        $sources = CalendarEvents::sources(Auth::user());
        $selected = Carbon::parse($this->selectedDay);
        $shown = array_values(array_diff(array_keys($sources), $this->hidden));

        // The selected day may lie outside the shown range (e.g. after paging).
        $events = collect(CalendarEvents::between(Auth::user(), (int) $this->householdId, $from->copy()->min($selected), $until->copy()->max($selected), $shown));
        $byDay = $events->groupBy('date');
        $anchor = Carbon::parse($this->anchor);

        return [
            'sources' => $sources,
            'days' => collect(Carbon::parse($from)->daysUntil($until))->map(fn (Carbon $day) => $day->copy()),
            'byDay' => $byDay,
            'selected' => $selected,
            'selectedEvents' => $byDay->get($this->selectedDay, collect()),
            'title' => match ($this->view) {
                'month' => $anchor->translatedFormat('F Y'),
                'week' => __('Week :number', ['number' => $from->isoWeek()]).' · '.$from->translatedFormat('j. M').' – '.$until->translatedFormat('j. M Y'),
                default => $from->translatedFormat('j. M').' – '.$until->translatedFormat('j. M Y'),
            },
            'month' => $anchor->month,
            'subscriptions' => CalendarSubscription::query()->visibleTo(Auth::user(), (int) $this->householdId)->with('user')->orderBy('name')->get(),
            'colors' => [
                'indigo' => 'bg-indigo-50 text-indigo-900 ring-indigo-200 dark:bg-indigo-900/40 dark:text-indigo-100 dark:ring-indigo-800',
                'amber' => 'bg-amber-50 text-amber-900 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-100 dark:ring-amber-800',
                'emerald' => 'bg-emerald-50 text-emerald-900 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-100 dark:ring-emerald-800',
                'sky' => 'bg-sky-50 text-sky-900 ring-sky-200 dark:bg-sky-900/30 dark:text-sky-100 dark:ring-sky-800',
                'violet' => 'bg-violet-50 text-violet-900 ring-violet-200 dark:bg-violet-900/30 dark:text-violet-100 dark:ring-violet-800',
                'rose' => 'bg-rose-50 text-rose-900 ring-rose-200 dark:bg-rose-900/30 dark:text-rose-100 dark:ring-rose-800',
                'slate' => 'bg-slate-100 text-slate-800 ring-slate-200 dark:bg-slate-800 dark:text-slate-100 dark:ring-slate-700',
                'teal' => 'bg-teal-50 text-teal-900 ring-teal-200 dark:bg-teal-900/30 dark:text-teal-100 dark:ring-teal-800',
            ],
        ];
    }
}; ?>

@php
    $chip = function (array $event) use ($sources, $colors): string {
        return $colors[$sources[$event['source']]['color'] ?? 'indigo'];
    };
    $sortConfig = "{ group: 'calendar', filter: '.static-event', animation: 150, ghostClass: 'opacity-40', forceFallback: true, fallbackOnBody: true }";
@endphp

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Calendar') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
            {{-- Navigation and view --}}
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-1">
                    <button type="button" wire:click="previous" class="rounded-lg px-2.5 py-1.5 text-gray-600 ring-1 ring-gray-200 hover:bg-white dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-800" aria-label="{{ __('Previous') }}">‹</button>
                    <button type="button" wire:click="goToToday" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-200 hover:bg-white dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-800">{{ __('Today') }}</button>
                    <button type="button" wire:click="next" class="rounded-lg px-2.5 py-1.5 text-gray-600 ring-1 ring-gray-200 hover:bg-white dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-800" aria-label="{{ __('Next') }}">›</button>
                </div>
                <h3 class="me-auto text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $title }}</h3>
                <div class="inline-flex rounded-lg bg-white p-0.5 text-sm ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700" role="group" aria-label="{{ __('View') }}">
                    @foreach (['month' => __('Month'), 'week' => __('Week'), 'agenda' => __('List')] as $key => $label)
                        <button type="button" wire:click="setView('{{ $key }}')" aria-pressed="{{ $view === $key ? 'true' : 'false' }}" @class(['rounded-md px-3 py-1', 'bg-indigo-600 text-white' => $view === $key, 'text-gray-600 dark:text-gray-300' => $view !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Sources: tap to show or hide --}}
            <div class="flex flex-wrap gap-2" aria-label="{{ __('Show in the calendar') }}">
                @foreach ($sources as $key => $source)
                    <button type="button" wire:click="toggleSource('{{ $key }}')" aria-pressed="{{ in_array($key, $hidden, true) ? 'false' : 'true' }}"
                        @class(['rounded-full px-3 py-1 text-xs font-medium ring-1 transition', $colors[$source['color']] => ! in_array($key, $hidden, true), 'bg-white text-gray-400 ring-gray-200 line-through dark:bg-gray-800 dark:ring-gray-700' => in_array($key, $hidden, true)])>
                        {{ $source['icon'] }} {{ $source['label'] }}
                    </button>
                @endforeach
            </div>

            <div class="grid gap-4 lg:grid-cols-[1fr_20rem]">
                <div class="min-w-0">
                    @if ($view === 'agenda')
                        <div class="space-y-3">
                            @php
                                $agendaDays = $days->filter(fn ($day) => $byDay->has($day->toDateString()));
                            @endphp
                            @forelse ($agendaDays as $day)
                                <section wire:key="agenda-{{ $day->toDateString() }}" class="rounded-2xl bg-white p-4 shadow-xs dark:bg-gray-800">
                                    <h4 @class(['mb-2 text-sm font-semibold', 'text-indigo-600 dark:text-indigo-400' => $day->isToday(), 'text-gray-900 dark:text-gray-100' => ! $day->isToday()])>
                                        {{ $day->isToday() ? __('Today') : ($day->isTomorrow() ? __('Tomorrow') : $day->translatedFormat('l')) }} · {{ $day->translatedFormat('j. F') }}
                                    </h4>
                                    <ul class="space-y-1.5">
                                        @foreach ($byDay->get($day->toDateString()) as $event)
                                            <li wire:key="agenda-event-{{ $day->toDateString() }}-{{ $loop->index }}">
                                                <a href="{{ $event['url'] }}" wire:navigate class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm ring-1 {{ $chip($event) }}">
                                                    @if ($event['time'])<span class="font-mono text-xs opacity-70">{{ $event['time'] }}</span>@endif
                                                    <span class="min-w-0 flex-1 truncate">{{ $event['source'] === 'tasks' ? '✅ ' : '' }}{{ $event['title'] }}</span>
                                                    @if ($event['overdue'])<span class="text-xs font-medium text-rose-600 dark:text-rose-400">{{ __('overdue') }}</span>@endif
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                            @empty
                                <div class="rounded-2xl bg-white p-8 text-center text-sm text-gray-500 shadow-xs dark:bg-gray-800 dark:text-gray-400">{{ __('Nothing planned in the next 30 days.') }}</div>
                            @endforelse
                        </div>
                    @else
                        {{-- Swipe left/right to page (touch); the next period slides in from the other side --}}
                        <div class="overflow-hidden rounded-2xl bg-white shadow-xs dark:bg-gray-800" x-data="e11Swipe({ onRight: () => $wire.previous(), onLeft: () => $wire.next(), enterFromOpposite: true })">
                          <div x-ref="content" style="touch-action: pan-y" x-on:pointerdown="start($event)" x-on:pointermove="move($event)" x-on:pointerup="end()" x-on:pointercancel="end()">
                            <div class="grid grid-cols-7 border-b border-gray-100 text-center text-xs font-medium text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                @foreach ($days->take(7) as $day)
                                    <div class="py-2">{{ $day->translatedFormat('D') }}</div>
                                @endforeach
                            </div>
                            <div class="grid grid-cols-7">
                                @foreach ($days as $day)
                                    @php
                                        $date = $day->toDateString();
                                        $dayEvents = $byDay->get($date, collect());
                                    @endphp
                                    <div wire:key="day-{{ $date }}" wire:click="selectDay('{{ $date }}')"
                                        @class(['group relative cursor-pointer border-b border-e border-gray-100 p-1 dark:border-gray-700', 'min-h-14 sm:min-h-28' => $view === 'month', 'min-h-20 sm:min-h-72' => $view === 'week', 'bg-gray-50/70 dark:bg-gray-900/30' => $view === 'month' && $day->month !== $month, 'ring-2 ring-inset ring-indigo-400' => $date === $selectedDay])>
                                        <div class="flex items-center justify-between px-0.5">
                                            <span @class(['flex h-6 w-6 items-center justify-center rounded-full text-xs', 'bg-indigo-600 font-semibold text-white' => $day->isToday(), 'text-gray-400' => ! $day->isToday() && $view === 'month' && $day->month !== $month, 'text-gray-700 dark:text-gray-300' => ! $day->isToday() && ($view === 'week' || $day->month === $month)])>{{ $day->day }}</span>
                                            @if ($dayEvents->count() > 0)
                                                <span class="text-[10px] text-gray-400 sm:hidden">{{ $dayEvents->count() }}</span>
                                            @endif
                                        </div>
                                        {{-- Drop target for tasks; other dates stay where they are --}}
                                        <ul class="mt-0.5 hidden min-h-6 space-y-0.5 sm:block" x-sort="$wire.moveTask($item, '{{ $date }}')" x-sort:config="{{ $sortConfig }}" x-on:click.stop>
                                            @foreach ($dayEvents->take($view === 'month' ? 4 : 20) as $event)
                                                <li wire:key="event-{{ $date }}-{{ $event['task_id'] ?? 'x'.$loop->index }}" @if ($event['task_id']) x-sort:item="{{ $event['task_id'] }}" @endif @class(['static-event' => ! $event['task_id'], 'cursor-grab' => $event['task_id']])>
                                                    <a href="{{ $event['url'] }}" wire:navigate title="{{ $event['title'] }}" @class(['block truncate rounded-sm px-1.5 py-0.5 text-[11px] ring-1', $chip($event), 'ring-rose-300 dark:ring-rose-700' => $event['overdue']])>
                                                        @if ($event['time'])<span class="opacity-70">{{ $event['time'] }}</span>@endif {{ $event['title'] }}
                                                    </a>
                                                </li>
                                            @endforeach
                                            @if ($view === 'month' && $dayEvents->count() > 4)
                                                <li class="static-event px-1 text-[11px] text-gray-500"><button type="button" wire:click="selectDay('{{ $date }}')">+ {{ $dayEvents->count() - 4 }} {{ __('more') }}</button></li>
                                            @endif
                                        </ul>
                                        {{-- Phones: dots instead of titles --}}
                                        @if ($dayEvents->isNotEmpty())
                                            <div class="mt-1 flex flex-wrap gap-0.5 px-0.5 sm:hidden" aria-hidden="true">
                                                @foreach ($dayEvents->take(6) as $event)
                                                    <span class="h-1.5 w-1.5 rounded-full {{ ['indigo' => 'bg-indigo-500', 'amber' => 'bg-amber-500', 'emerald' => 'bg-emerald-500', 'sky' => 'bg-sky-500', 'violet' => 'bg-violet-500', 'rose' => 'bg-rose-500', 'slate' => 'bg-slate-400', 'teal' => 'bg-teal-500'][$sources[$event['source']]['color'] ?? 'indigo'] }}"></span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                          </div>
                        </div>
                        <p class="mt-2 hidden text-xs text-gray-500 sm:block dark:text-gray-400">{{ __('Tip: drag a task to another day. Click a day to see everything and to add a task.') }}</p>
                    @endif
                </div>

                {{-- Selected day --}}
                <aside x-data x-on:calendar-day-selected.window="if (window.innerWidth < 1024) { $nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'start' })) }" class="scroll-mt-20 space-y-3 rounded-2xl bg-white p-4 shadow-xs lg:sticky lg:top-4 lg:self-start dark:bg-gray-800" aria-label="{{ __('Selected day') }}">
                    <h4 class="font-semibold text-gray-900 dark:text-gray-100">
                        {{ $selected->isToday() ? __('Today') : $selected->translatedFormat('l') }}, {{ $selected->translatedFormat('j. F Y') }}
                    </h4>
                    <form wire:submit="addTask" class="flex gap-2">
                        <x-text-input wire:model="newTask" class="block w-full text-sm" placeholder="{{ __('New task on this day…') }}" aria-label="{{ __('New task on this day…') }}" />
                        <x-primary-button type="submit" class="shrink-0">+</x-primary-button>
                    </form>
                    <x-input-error :messages="$errors->get('newTask')" />
                    <ul class="space-y-1.5">
                        @forelse ($selectedEvents as $event)
                            <li wire:key="selected-{{ $loop->index }}-{{ $event['task_id'] }}">
                                <a href="{{ $event['url'] }}" wire:navigate class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm ring-1 {{ $chip($event) }}">
                                    <span aria-hidden="true">{{ $sources[$event['source']]['icon'] ?? '' }}</span>
                                    @if ($event['time'])<span class="font-mono text-xs opacity-70">{{ $event['time'] }}</span>@endif
                                    <span class="min-w-0 flex-1">{{ $event['title'] }}</span>
                                    @if ($event['overdue'])<span class="text-xs font-medium text-rose-600 dark:text-rose-400">{{ __('overdue') }}</span>@endif
                                </a>
                            </li>
                        @empty
                            <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing planned for this day.') }}</li>
                        @endforelse
                    </ul>

                    <details class="border-t border-gray-100 pt-3 text-sm dark:border-gray-700" @if ($errors->has('subscriptionUrl') || $errors->has('subscriptionName')) open @endif>
                        <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-300">🗓️ {{ __('Show other calendars') }} @if ($subscriptions->isNotEmpty())({{ $subscriptions->count() }})@endif</summary>
                        <ul class="mt-2 space-y-1">
                            @foreach ($subscriptions as $subscription)
                                <li wire:key="subscription-{{ $subscription->id }}" class="flex items-start gap-2">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-gray-800 dark:text-gray-200">{{ $subscription->name }}</span>
                                        @if ($subscription->error)
                                            <span class="block text-xs text-red-600 dark:text-red-400">{{ __('Could not be loaded: :message', ['message' => \Illuminate\Support\Str::limit($subscription->error, 80)]) }}</span>
                                        @elseif ($subscription->fetched_at)
                                            <span class="block text-xs text-gray-400">{{ __('Updated :time', ['time' => $subscription->fetched_at->diffForHumans()]) }}</span>
                                        @endif
                                    </span>
                                    @if ($subscription->user_id === auth()->id())
                                        <button type="button" wire:click="toggleSubscriptionVisibility({{ $subscription->id }})" class="shrink-0 rounded-full px-2 py-0.5 text-xs ring-1 {{ $subscription->isPrivate() ? 'text-gray-600 ring-gray-200 dark:text-gray-300 dark:ring-gray-600' : 'text-indigo-700 ring-indigo-200 dark:text-indigo-300 dark:ring-indigo-800' }}" title="{{ $subscription->isPrivate() ? __('Only you see this calendar – click to share it with the household') : __('The household sees this calendar – click to make it private') }}">{{ $subscription->isPrivate() ? '🔒 '.__('Private') : '👥 '.__('Shared') }}</button>
                                        <button type="button" wire:click="deleteSubscription({{ $subscription->id }})" wire:confirm="{{ __('Remove this calendar?') }}" class="text-gray-400 hover:text-red-600" aria-label="{{ __('Remove') }}">×</button>
                                    @else
                                        <span class="shrink-0 text-xs text-gray-400">👥 {{ $subscription->user?->name }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <form wire:submit="addSubscription" class="mt-2 space-y-2">
                            <x-text-input wire:model="subscriptionName" class="block w-full text-sm" placeholder="{{ __('Name, e.g. Work') }}" aria-label="{{ __('Name') }}" />
                            <x-input-error :messages="$errors->get('subscriptionName')" />
                            <x-text-input wire:model="subscriptionUrl" class="block w-full text-sm" placeholder="https://calendar.google.com/…/basic.ics" aria-label="{{ __('Calendar address') }}" />
                            <x-input-error :messages="$errors->get('subscriptionUrl')" />
                            <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model="subscriptionShared" class="rounded-sm border-gray-300 text-indigo-600"> {{ __('Share with the household (otherwise only you see it)') }}</label>
                            <x-secondary-button type="submit">＋ {{ __('Add calendar') }}</x-secondary-button>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Google: calendar settings → “Secret address in iCal format”. iCloud: share calendar → public calendar. Outlook: settings → shared calendars → publish (ICS). Proton: share calendar → “Share with anyone” → copy link. Updated hourly.') }}</p>
                        </form>
                    </details>
                </aside>
            </div>
        </div>
    </div>
</div>
