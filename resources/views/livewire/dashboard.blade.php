<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Collection;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\ShoppingListEntry;
use App\Support\Addons;
use App\Support\DashboardWidgets;
use App\Support\Portfolio\PortfolioLink;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    /**
     * A task changed in the task window (tasks.quick-sheet): render again.
     */
    #[On('task-updated')]
    public function refreshAfterTaskChange(): void {}

    /**
     * Re-renders after an entry was edited or sorted in the inbox window.
     */
    #[On('inbox-updated')]
    public function refreshAfterInboxChange(): void {}

    private const LIST_LIMIT = 5;

    /**
     * @var array<string, string>
     */
    protected $listeners = ['item-captured' => '$refresh'];

    /**
     * Users without a household still get the dashboard, just with a hint.
     */
    protected function requiresHousehold(): bool
    {
        return false;
    }

    public bool $customizing = false;

    /**
     * Cards that need slow queries or outside services are filled in after the page is shown.
     */
    public const LAZY_WIDGETS = ['weather', 'deadlines', 'portfolio', 'real_estate', 'tax', 'costs', 'life', 'freedom'];

    public bool $widgetsLoaded = false;

    public function loadWidgets(): void
    {
        $this->widgetsLoaded = true;
    }

    public function toggleWidget(string $key): void
    {
        abort_unless(array_key_exists($key, DashboardWidgets::all()), 404);

        $widgets = DashboardWidgets::forUser($this->currentUser());
        $widgets = in_array($key, $widgets, true) ? array_values(array_diff($widgets, [$key])) : [...$widgets, $key];
        $this->currentUser()->forceFill(['dashboard_widgets' => $widgets])->save();
    }

    /**
     * Moves a widget one place up (-1) or down (+1).
     */
    public function moveWidget(string $key, int $direction): void
    {
        $widgets = DashboardWidgets::forUser($this->currentUser());
        $index = array_search($key, $widgets, true);
        $target = $index === false ? false : $index + ($direction < 0 ? -1 : 1);

        if ($index === false || $target < 0 || $target >= count($widgets)) {
            return;
        }

        [$widgets[$index], $widgets[$target]] = [$widgets[$target], $widgets[$index]];
        $this->currentUser()->forceFill(['dashboard_widgets' => $widgets])->save();
    }

    /**
     * Drag & drop of a card on the dashboard.
     */
    public function sortWidget(string $key, int $position): void
    {
        $visible = $this->visibleWidgets();
        abort_unless(in_array($key, $visible, true), 404);
        $visible = array_values(array_diff($visible, [$key]));
        array_splice($visible, max(0, min($position, count($visible))), 0, [$key]);
        // Cards of modules the member cannot use right now keep their place at the end.
        $hidden = array_values(array_diff(DashboardWidgets::forUser($this->currentUser()), $visible));
        $this->currentUser()->forceFill(['dashboard_widgets' => [...$visible, ...$hidden]])->save();
    }

    /**
     * The member's cards in order, without those of modules they cannot use.
     *
     * @return list<string>
     */
    private function visibleWidgets(): array
    {
        return array_values(array_filter(DashboardWidgets::forUser($this->currentUser()), fn (string $key) => $this->widgetAvailable($key)));
    }

    private function widgetAvailable(string $key): bool
    {
        $modules = ['meals' => Addons::RECIPES, 'portfolio' => Addons::PORTFOLIO, 'real_estate' => Addons::REAL_ESTATE, 'tax' => Addons::FINANCE, 'freedom' => Addons::FINANCE, 'energy' => Addons::SMART_HOME, 'smart_home' => Addons::SMART_HOME];

        return ! isset($modules[$key]) || $this->currentUser()->canUseAddon($modules[$key]);
    }

    public function dismissOnboarding(): void
    {
        $this->currentUser()->forceFill(['onboarding_dismissed_at' => now()])->save();
    }

    public function resetWidgets(): void
    {
        $this->currentUser()->forceFill(['dashboard_widgets' => null])->save();
    }

    public function toggleTodo(int $id): void
    {
        $todo = $this->findVisibleItem(Item::TYPE_TODO, $id);

        $todo->update(['done' => ! $todo->done]);

        if ($todo->rescheduledTo) {
            $this->announceReschedule($todo);
        } elseif ($todo->done) {
            $this->dispatch('toast', message: __('Done: :title', ['title' => \Illuminate\Support\Str::limit($todo->title, 40)]), action: ['label' => __('Undo'), 'event' => 'dashboard-task-undo', 'params' => ['taskId' => $todo->id]]);
        }
    }

    #[\Livewire\Attributes\On('dashboard-task-undo')]
    public function undoTodo(int $taskId): void
    {
        $this->findVisibleItem(Item::TYPE_TODO, $taskId)->update(['done' => false]);
    }

    /**
     * A favourite device on the dashboard: switch it like on the Smart home page.
     */
    public function pressFavorite(int $id, bool $confirmed = false): void
    {
        $entity = \App\Models\SmartHomeEntity::where('household_id', $this->householdId)->where('favorite', true)->with('connection')->find($id) ?? abort(404);

        try {
            \App\Support\SmartHome\EntityControl::press($this->currentUser(), $entity, $confirmed);
        } catch (\App\Support\SmartHome\SmartHomeException|\Illuminate\Http\Client\RequestException $exception) {
            $this->dispatch('toast', message: $exception->getMessage());
        }
    }

    public function tapHabit(int $id): void
    {
        $habit = \App\Models\Habit::where('user_id', $this->currentUser()->id)->whereNull('archived_at')->find($id) ?? abort(404);
        \App\Support\Life\HabitTracker::tap($habit, today());
    }

    public function with(): array
    {
        $hour = now()->hour;

        $data = [
            'greeting' => match (true) {
                $hour < 11 => __('Good morning'),
                $hour < 18 => __('Hello'),
                default => __('Good evening'),
            },
        ];

        if ($this->householdId === null) {
            return $data;
        }

        $tasks = Collection::tasksFor($this->householdId);
        $dueProperty = $tasks->systemProperty('due');
        $assigneeProperty = $tasks->systemProperty('assignee');

        // Tasks with a due date first (overdue on top), then the rest in manual order.
        $openTodos = $this->visibleItems(Item::TYPE_TODO)->where('done', false)->orderBy('position')->get()
            ->sortBy(fn (Item $todo) => [$dueProperty && $todo->propertyValue($dueProperty) ? 0 : 1, $dueProperty ? (string) $todo->propertyValue($dueProperty) : '', $todo->position])
            ->values();
        $openShopping = ShoppingListEntry::where('household_id', $this->householdId)->where('checked', false);
        $hasRecipes = $this->currentUser()->canUseAddon(Addons::RECIPES);
        $widgets = $this->visibleWidgets();
        $shows = fn (string $widget) => $this->widgetsLoaded && in_array($widget, $widgets, true);

        return $data + [
            'today' => $this->widgetsLoaded ? \App\Support\TodayView::for($this->currentUser(), $this->householdId) : null,
            'onboarding' => \App\Support\Onboarding::isVisible($this->currentUser()) ? \App\Support\Onboarding::steps($this->currentUser()) : null,
            'tasksCollection' => $tasks,
            'dueProperty' => $dueProperty,
            'assigneeProperty' => $assigneeProperty,
            'openTodoCount' => $openTodos->count(),
            'overdueCount' => $dueProperty ? $openTodos->filter(fn (Item $todo) => ($todo->propertyValue($dueProperty) ?? '9999') < now()->toDateString())->count() : 0,
            'openTodos' => $openTodos->take(self::LIST_LIMIT),
            'recentNotes' => $this->visibleItems(Item::TYPE_NOTE)->latest('updated_at')->limit(self::LIST_LIMIT)->get(),
            'openShoppingCount' => (clone $openShopping)->count(),
            'openShopping' => $openShopping->latest()->limit(self::LIST_LIMIT)->get(),
            'inboxCount' => $this->visibleItems(Item::TYPE_INBOX)->count(),
            'inboxEntries' => $this->visibleItems(Item::TYPE_INBOX)->latest()->limit(3)->get(),
            'hasRecipes' => $hasRecipes,
            'todaysMeals' => $hasRecipes ? $this->todaysMeals() : collect(),
            'widgets' => $widgets,
            'allWidgets' => array_filter(DashboardWidgets::all(), fn (string $key) => $this->widgetAvailable($key), ARRAY_FILTER_USE_KEY),
            'portfolio' => $shows('portfolio') ? PortfolioLink::summary($this->currentUser()) : null,
            'portfolioEvents' => $shows('portfolio') ? array_slice(PortfolioLink::events($this->currentUser(), today(), today()->addDays(30)), 0, 3) : [],
            'weather' => $this->widgetsLoaded && $this->currentUser()->weather_latitude !== null
                ? \App\Support\Weather::today($this->currentUser()->weather_latitude, $this->currentUser()->weather_longitude)
                : null,
            'realEstates' => $shows('real_estate')
                ? \App\Support\RealEstate\RealEstateLink::properties($this->currentUser(), $this->householdId)->map(fn ($realEstate) => ['model' => $realEstate, 'summary' => \App\Support\RealEstate\RealEstatePlan::summary($realEstate)])
                : collect(),
            'realEstateEvents' => $shows('real_estate') ? array_slice(\App\Support\RealEstate\RealEstateLink::events($this->currentUser(), $this->householdId, today()->subDays(30), today()->addDays(30)), 0, 3) : [],
            'taxYear' => $shows('tax') ? \App\Support\Finance\TaxYear::build($this->currentUser(), $this->householdId, (int) now()->year) : null,
            'deadlines' => $shows('deadlines') ? DashboardWidgets::deadlines($this->currentUser(), $this->householdId) : [],
            'monthlyCosts' => $shows('costs') ? DashboardWidgets::monthlyCosts($this->currentUser(), $this->householdId) : ['total' => 0, 'collections' => []],
            'lifeDates' => $shows('life') ? array_slice(\App\Support\Life\LifeDates::between($this->currentUser(), today(), today()->addDays(60)), 0, 6) : [],
            'freedom' => $shows('freedom') ? \App\Support\Finance\CashflowStatement::for($this->currentUser()) : null,
            'todayHabits' => in_array('habits', $widgets, true) ? $this->todayHabits() : [],
            'energyToday' => $shows('energy') && $this->householdId ? \App\Support\SmartHome\EnergyRecorder::energy($this->householdId, today()) : null,
            'energyNow' => $shows('energy') && $this->householdId ? \App\Support\SmartHome\EnergyRecorder::latest($this->householdId) : [],
            'favoriteDevices' => $shows('smart_home') && $this->householdId ? \App\Models\SmartHomeEntity::where('household_id', $this->householdId)->where('favorite', true)->orderBy('position')->get() : collect(),
            'todayMood' => in_array('habits', $widgets, true) ? \App\Models\JournalEntry::where('user_id', $this->currentUser()->id)->whereDate('date', today()->toDateString())->value('mood') : null,
        ];
    }

    /**
     * The habits due today with what was done and the current streak.
     *
     * @return list<array{habit: \App\Models\Habit, value: int, done: bool, streak: int}>
     */
    private function todayHabits(): array
    {
        $habits = \App\Support\Life\HabitTracker::active($this->currentUser())->filter(fn (\App\Models\Habit $habit) => $habit->isDueOn(today()));
        $logs = \App\Support\Life\HabitTracker::logs($habits);

        return $habits->map(fn (\App\Models\Habit $habit) => [
            'habit' => $habit,
            'value' => $logs[$habit->id][today()->toDateString()] ?? 0,
            'done' => $habit->isFulfilledBy($logs[$habit->id][today()->toDateString()] ?? 0),
            'streak' => \App\Support\Life\HabitTracker::stats($habit, $logs[$habit->id])['streak'],
        ])->values()->all();
    }

    /**
     * Today's meal plan in meal order; recipes the user can't see show their title only.
     *
     * @return \Illuminate\Support\Collection<int, MealPlan>
     */
    private function todaysMeals(): \Illuminate\Support\Collection
    {
        $mealOrder = array_flip(MealPlan::MEAL_TYPES);
        $meals = MealPlan::query()
            ->visibleTo($this->currentUser(), (int) $this->householdId)
            ->whereDate('date', today())
            ->with('recipe')
            ->get();

        return MealPlan::withVisibleRecipes($meals, $this->currentUser(), (int) $this->householdId)
            ->sortBy(fn (MealPlan $meal) => [$mealOrder[$meal->meal_type] ?? 9, $meal->id])
            ->values();
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ $greeting }}, {{ Auth::user()->name }}
        </h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ now()->translatedFormat('l, j. F Y') }}</p>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if ($householdId === null)
                <div class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6 text-gray-700 dark:text-gray-300">
                    {{ __('You are not a member of a household yet. Ask a household member for an invite code.') }}
                </div>
            @else
                @if ($onboarding)
                    @php
                        $onboardingDone = collect($onboarding)->where('done', true)->count();
                        $onboardingTotal = count($onboarding);
                    @endphp
                    <section class="rounded-2xl bg-indigo-50 p-5 shadow-xs dark:bg-indigo-900/20" aria-labelledby="onboarding-heading" x-data="{ showDone: false }">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <h3 id="onboarding-heading" class="font-semibold text-gray-900 dark:text-gray-100">👋 {{ __('First steps') }}</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __(':done of :total done – a few minutes to get the most out of :app.', ['done' => $onboardingDone, 'total' => $onboardingTotal, 'app' => config('app.name')]) }}</p>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-indigo-100 dark:bg-indigo-950" role="progressbar" aria-valuenow="{{ $onboardingDone }}" aria-valuemin="0" aria-valuemax="{{ $onboardingTotal }}" aria-label="{{ __('First steps') }}">
                                    <div class="h-full rounded-full bg-indigo-600 dark:bg-indigo-400" style="width: {{ round($onboardingDone / max(1, $onboardingTotal) * 100) }}%"></div>
                                </div>
                            </div>
                            <button type="button" wire:click="dismissOnboarding" class="text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400" title="{{ __('Hide') }}" aria-label="{{ __('Hide first steps') }}"><x-icon name="x-mark" class="h-4 w-4" stroke-width="2" /></button>
                        </div>
                        <ol class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach (collect($onboarding)->sortBy('done') as $step)
                                <li wire:key="onboarding-{{ $step['key'] }}" @if ($step['done']) x-show="showDone" x-cloak @endif>
                                    <a href="{{ $step['url'] }}" wire:navigate @class(['flex h-full gap-3 rounded-xl bg-white p-3 text-sm shadow-xs hover:ring-1 hover:ring-indigo-300 dark:bg-gray-800', 'opacity-60' => $step['done']])>
                                        <span aria-hidden="true">{{ $step['done'] ? '✅' : '⬜' }}</span>
                                        <span>
                                            <span @class(['block font-medium text-gray-900 dark:text-gray-100', 'line-through' => $step['done']])>{{ $step['label'] }}<span class="sr-only">{{ $step['done'] ? ' – '.__('done') : '' }}</span></span>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $step['hint'] }}</span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ol>
                        @if ($onboardingDone > 0)
                            <button type="button" x-on:click="showDone = ! showDone" class="mt-2 text-xs text-indigo-700 hover:underline dark:text-indigo-300" x-text="showDone ? @js(__('Hide completed')) : @js(trans_choice(':count completed step|:count completed steps', $onboardingDone))"></button>
                        @endif
                    </section>
                @endif

                <section class="rounded-2xl bg-white p-5 shadow-xs sm:p-6 dark:bg-gray-800" aria-labelledby="today-heading" @if (! $widgetsLoaded) wire:init="loadWidgets" @endif>
                    @if (! $today)
                        <div class="animate-pulse space-y-3" aria-busy="true">
                            <div class="h-5 w-1/4 rounded-sm bg-gray-200 dark:bg-gray-700"></div>
                            <div class="h-4 w-2/3 rounded-sm bg-gray-100 dark:bg-gray-700/60"></div>
                            <div class="h-4 w-1/2 rounded-sm bg-gray-100 dark:bg-gray-700/60"></div>
                        </div>
                    @else
                        @php($sourceIcons = ['tasks' => '✅', 'dates' => '📌', 'meals' => '🍳', 'real_estate' => '🏠', 'business' => '💼', 'portfolio' => '📈', 'life' => '🗂️', 'external' => '🗓️'])
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 id="today-heading" class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $today['mode'] === 'morning' ? '🌅' : ($today['mode'] === 'day' ? '☀️' : '🌙') }} {{ $today['title'] }}</h3>
                            <a href="{{ route('calendar.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">{{ __('Calendar') }} →</a>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-2 text-xs">
                            @if ($today['next'])
                                <span class="rounded-full bg-indigo-50 px-2.5 py-1 font-medium text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200">⏭ {{ $today['minutesUntilNext'] < 1 ? __('Now') : ($today['minutesUntilNext'] < 60 ? __('In :minutes min', ['minutes' => $today['minutesUntilNext']]) : __('At :time', ['time' => $today['next']['time']])) }}: {{ \Illuminate\Support\Str::limit($today['next']['title'], 40) }}</span>
                            @endif
                            @if ($weather)
                                <span class="rounded-full bg-sky-50 px-2.5 py-1 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200">{{ $weather['icon'] }} {{ $weather['min'] }}–{{ $weather['max'] }} °C · {{ __('rain :percent %', ['percent' => $weather['rain']]) }}</span>
                            @endif
                            @if ($today['overdue'] > 0)
                                <a href="{{ route('tasks.index') }}" wire:navigate class="rounded-full bg-red-50 px-2.5 py-1 text-red-800 hover:underline dark:bg-red-900/40 dark:text-red-200">⚠ {{ trans_choice(':count task overdue|:count tasks overdue', $today['overdue']) }}</a>
                            @endif
                            @if ($today['done'] > 0)
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200">✓ {{ trans_choice(':count done today|:count done today', $today['done']) }}</span>
                            @endif
                        </div>
                        @if ($today['events'] === [])
                            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Nothing planned for today – enjoy it.') }}</p>
                        @else
                            <ol class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($today['events'] as $index => $event)
                                    <li wire:key="today-{{ $index }}-{{ md5($event['title']) }}" @class(['flex items-center gap-3 py-2', 'opacity-50' => $event['past']])>
                                        <span class="w-14 shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $event['time'] ?? __('All day') }}</span>
                                        @if ($event['task_id'])
                                            <input type="checkbox" wire:click="toggleTodo({{ $event['task_id'] }})" class="size-5 shrink-0 rounded-full border-gray-300 text-emerald-600" aria-label="{{ __('Mark :title as done', ['title' => $event['title']]) }}">
                                        @else
                                            <span class="w-5 shrink-0 text-center" aria-hidden="true">{{ $sourceIcons[$event['source']] ?? '•' }}</span>
                                        @endif
                                        <a href="{{ $event['url'] }}" wire:navigate @class(['min-w-0 flex-1 truncate text-sm text-gray-900 hover:underline dark:text-gray-100', 'font-semibold' => $today['next'] && $event === $today['next']])>{{ $event['title'] }}</a>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                        @if ($today['tomorrow'] !== [])
                            <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Tomorrow') }}</h4>
                            <ul class="mt-1 space-y-1 text-sm">
                                @foreach (array_slice($today['tomorrow'], 0, 6) as $index => $event)
                                    <li wire:key="tomorrow-{{ $index }}" class="flex gap-3"><span class="w-14 shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $event['time'] ?? '–' }}</span><a href="{{ $event['url'] }}" wire:navigate class="min-w-0 flex-1 truncate text-gray-800 hover:underline dark:text-gray-200">{{ ($sourceIcons[$event['source']] ?? '•').' '.$event['title'] }}</a></li>
                                @endforeach
                                @if (count($today['tomorrow']) > 6)
                                    <li class="text-xs text-gray-500">{{ __('and :count more', ['count' => count($today['tomorrow']) - 6]) }}</li>
                                @endif
                            </ul>
                        @endif
                    @endif
                </section>

                <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Quick capture') }}</h3>
                        <a href="{{ route('inbox.index') }}" wire:navigate class="text-sm text-indigo-600 dark:text-indigo-400 underline">
                            {{ trans_choice(':count entry in the inbox|:count entries in the inbox', $inboxCount) }}
                        </a>
                    </div>
                    <livewire:quick-capture />
                </section>

                <div class="flex justify-end">
                    <button type="button" wire:click="$toggle('customizing')" class="text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">{{ $customizing ? '✓ '.__('Done') : '⚙ '.__('Customize dashboard') }}</button>
                </div>

                @if ($customizing)
                    <section class="rounded-xl border border-dashed border-indigo-300 bg-indigo-50/50 p-4 dark:border-indigo-700 dark:bg-indigo-900/20">
                        <p class="text-sm text-gray-700 dark:text-gray-200">{{ __('Choose which cards you see and in which order.') }}</p>
                        <ul class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($widgets as $index => $key)
                                <li wire:key="widget-setting-{{ $key }}" class="flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm shadow-xs dark:bg-gray-800">
                                    <input type="checkbox" checked wire:click="toggleWidget('{{ $key }}')" class="rounded-sm border-gray-300 text-indigo-600" aria-label="{{ $allWidgets[$key]['label'] }}" />
                                    <span class="flex-1 text-gray-800 dark:text-gray-100">{{ $allWidgets[$key]['icon'] }} {{ $allWidgets[$key]['label'] }}</span>
                                    <button type="button" wire:click="moveWidget('{{ $key }}', -1)" @disabled($index === 0) class="px-1 text-gray-400 hover:text-gray-700 disabled:opacity-30" aria-label="{{ __('Move up') }}">↑</button>
                                    <button type="button" wire:click="moveWidget('{{ $key }}', 1)" @disabled($loop->last) class="px-1 text-gray-400 hover:text-gray-700 disabled:opacity-30" aria-label="{{ __('Move down') }}">↓</button>
                                </li>
                            @endforeach
                            @foreach ($allWidgets as $key => $widget)
                                @if (! in_array($key, $widgets, true))
                                    <li wire:key="widget-setting-{{ $key }}" class="flex items-center gap-2 rounded-lg bg-white/60 px-3 py-2 text-sm text-gray-500 dark:bg-gray-800/60">
                                        <input type="checkbox" wire:click="toggleWidget('{{ $key }}')" class="rounded-sm border-gray-300 text-indigo-600" aria-label="{{ $widget['label'] }}" />
                                        <span class="flex-1">{{ $widget['icon'] }} {{ $widget['label'] }}</span>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                        <button type="button" wire:click="resetWidgets" class="mt-3 text-xs text-gray-500 underline">{{ __('Reset to default') }}</button>
                    </section>
                @endif

                <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3" x-sort="$wire.sortWidget($item, $position)" x-sort:config="{ handle: '.widget-handle', animation: 180, ghostClass: 'opacity-40' }">
                    @foreach ($widgets as $widget)
                        <div wire:key="widget-{{ $widget }}" x-sort:item="@js($widget)" class="group/widget relative">
                            <button type="button" class="widget-handle absolute right-2 top-2 z-10 cursor-grab opacity-70 sm:opacity-0 focus:opacity-100 rounded-md bg-white/80 px-1.5 py-0.5 text-xs text-gray-400 shadow-xs ring-1 ring-gray-200 sm:group-hover/widget:opacity-100 hover:text-gray-700 dark:bg-gray-900/80 dark:ring-gray-700" title="{{ __('Drag to move the card') }}" aria-label="{{ __('Drag to move the card') }}">⋮⋮</button>
                        @if (! $widgetsLoaded && in_array($widget, $this::LAZY_WIDGETS, true))
                            <section class="animate-pulse rounded-lg bg-white p-6 shadow-xs dark:bg-gray-800" aria-busy="true" aria-label="{{ $allWidgets[$widget]['label'] ?? __('Loading …') }}">
                                <div class="h-4 w-1/3 rounded-sm bg-gray-200 dark:bg-gray-700"></div>
                                <div class="mt-4 h-8 w-1/2 rounded-sm bg-gray-100 dark:bg-gray-700/60"></div>
                                <div class="mt-3 h-3 w-2/3 rounded-sm bg-gray-100 dark:bg-gray-700/60"></div>
                            </section>
                        @else
                        @switch($widget)
                            @case('tasks')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Open tasks')" :count="$openTodoCount" :href="route('tasks.index')" />
                        @if ($overdueCount > 0)
                            <p class="mt-1 text-xs font-medium text-red-600 dark:text-red-400">{{ trans_choice(':count task is overdue|:count tasks are overdue', $overdueCount) }}</p>
                        @endif
                        <ul class="mt-3 space-y-2">
                            @forelse ($openTodos as $todo)
                                <li wire:key="dashboard-todo-{{ $todo->id }}-{{ $todo->updated_at?->timestamp }}" class="flex items-center gap-3">
                                    <input
                                        type="checkbox"
                                        wire:click="toggleTodo({{ $todo->id }})"
                                        aria-label="{{ __('Mark as done') }}"
                                        class="rounded-sm border-gray-300 dark:border-gray-600 text-indigo-600 shadow-xs focus:ring-indigo-500"
                                    />
                                    <a href="{{ route('tasks.index', ['task' => $todo->id]) }}" data-open-task="{{ $todo->id }}" class="min-w-0 flex-1 truncate text-gray-900 dark:text-gray-100 hover:underline">{{ $todo->title }}</a>
                                    @if ($todo->isPrivate())
                                        <x-private-badge />
                                    @endif
                                    @if ($dueProperty && ($due = $todo->propertyValue($dueProperty)))
                                        <span @class([
                                            'shrink-0 rounded-sm px-1.5 py-0.5 text-xs',
                                            'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' => $due < now()->toDateString(),
                                            'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $due === now()->toDateString(),
                                            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $due > now()->toDateString(),
                                        ])>
                                            {{ $due === now()->toDateString() ? __('Today') : \Illuminate\Support\Carbon::parse($due)->translatedFormat('j. M') }}
                                        </span>
                                    @endif
                                </li>
                            @empty
                                <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('All done!') }}</li>
                            @endforelse
                        </ul>
                    </section>
                                @break
                            @case('energy')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Energy today')" :href="route('smart-home.index', ['tab' => 'energy'])" />
                        @if (! $energyToday || array_filter($energyToday, fn ($value) => $value !== null) === [])
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Connect evcc under Smart home to see PV, consumption and charging here.') }}</p>
                        @else
                            @php($kwh = fn (?float $value) => $value === null ? '–' : number_format($value, 1, ',', '.').' kWh')
                            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                <div><dt class="text-xs text-gray-500 dark:text-gray-400">☀️ {{ __('PV yield') }}</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $kwh($energyToday['pv_kwh']) }}@if (isset($energyNow['pv'])) <span class="text-xs font-normal text-gray-500">· {{ __('now :power', ['power' => number_format($energyNow['pv'] / 1000, 1, ',', '.').' kW']) }}</span>@endif</dd></div>
                                <div><dt class="text-xs text-gray-500 dark:text-gray-400">🏠 {{ __('Consumption') }}</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $kwh($energyToday['home_kwh']) }}</dd></div>
                                <div><dt class="text-xs text-gray-500 dark:text-gray-400">⬆️ {{ __('Feed-in') }}</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $kwh($energyToday['export_kwh']) }}</dd></div>
                                <div><dt class="text-xs text-gray-500 dark:text-gray-400">🚗 {{ __('Car') }}</dt><dd class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ isset($energyNow['vehicleSoc']) ? number_format($energyNow['vehicleSoc'], 0).' %' : '–' }} · {{ $kwh($energyToday['charged_kwh']) }}</dd></div>
                            </dl>
                        @endif
                    </section>
                                @break
                            @case('smart_home')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Favourite devices')" :href="route('smart-home.index')" />
                        @if ($favoriteDevices->isEmpty())
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Mark devices with ★ under Smart home › Connections & devices.') }}</p>
                        @else
                            <div class="mt-3 grid grid-cols-2 gap-2">
                                @foreach ($favoriteDevices as $device)
                                    @php($critical = \App\Support\SmartHome\EntityControl::isCritical($device))
                                    <button type="button" wire:key="favorite-{{ $device->id }}" wire:click="pressFavorite({{ $device->id }}, {{ $critical ? 'true' : 'false' }})" @if ($critical) wire:confirm="{{ __('Really switch :name?', ['name' => $device->name]) }}" @endif
                                        @class(['flex items-center gap-2 rounded-xl px-3 py-2 text-start text-sm active:scale-[0.97]', 'bg-amber-100 dark:bg-amber-900/40' => $device->isOn() && ! $device->isRunnable(), 'bg-gray-50 dark:bg-gray-900/40' => ! $device->isOn() || $device->isRunnable()])>
                                        <span>{{ $device->icon() }}</span><span class="min-w-0"><span class="block truncate font-medium text-gray-900 dark:text-gray-100">{{ $device->name }}</span><span class="block text-xs text-gray-500 dark:text-gray-400">{{ $device->isRunnable() ? __('Start') : $device->displayState() }}</span></span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </section>
                                @break
                            @case('habits')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Habits today')" :count="count($todayHabits) ? collect($todayHabits)->where('done', true)->count().' / '.count($todayHabits) : null" :href="route('journal.index')" />
                        @if ($todayHabits === [])
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No habits for today yet.') }} <a href="{{ route('journal.index', ['tab' => 'habits']) }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-400">{{ __('Add a habit') }}</a></p>
                        @else
                            <ul class="mt-3 space-y-2">
                                @foreach ($todayHabits as $row)
                                    <li wire:key="dashboard-habit-{{ $row['habit']->id }}" class="flex items-center gap-3">
                                        <button type="button" wire:click="tapHabit({{ $row['habit']->id }})" aria-pressed="{{ $row['done'] ? 'true' : 'false' }}" @class(['flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm', 'bg-emerald-500 text-white' => $row['done'], 'ring-2 ring-gray-300 text-transparent hover:ring-emerald-400 dark:ring-gray-600' => ! $row['done']]) aria-label="{{ $row['habit']->type === 'count' ? __('One more') : ($row['done'] ? __('Not done') : __('Done')) }}: {{ $row['habit']->name }}">{{ $row['habit']->type === 'count' && ! $row['done'] ? '＋' : '✓' }}</button>
                                        <span @class(['min-w-0 flex-1 truncate', 'text-gray-900 dark:text-gray-100' => ! $row['done'], 'text-gray-400 line-through dark:text-gray-500' => $row['done']])>{{ $row['habit']->icon }} {{ $row['habit']->name }}@if ($row['habit']->type === 'count') <span class="text-xs text-gray-500">{{ $row['value'] }}/{{ $row['habit']->target }}</span>@endif</span>
                                        @if ($row['streak'] > 0)<span class="shrink-0 text-xs text-orange-600 dark:text-orange-300">🔥 {{ $row['streak'] }}</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <a href="{{ route('journal.index') }}" wire:navigate class="mt-3 block text-xs text-gray-500 hover:underline dark:text-gray-400">{{ $todayMood ? \App\Support\Life\Journal::moods()[$todayMood]['icon'].' '.__('Mood noted') : '📔 '.__('How is your day? Write in your journal') }} →</a>
                    </section>
                                @break
                            @case('shopping')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Shopping List')" :count="$openShoppingCount" :href="route('shopping-list.index')" />
                        <ul class="mt-3 space-y-1">
                            @forelse ($openShopping as $entry)
                                <li wire:key="dashboard-shopping-{{ $entry->id }}" class="text-gray-900 dark:text-gray-100">
                                    {{ $entry->label }}
                                    @if ($entry->quantity)
                                        <span class="text-sm text-gray-500 dark:text-gray-400">({{ $entry->quantity }})</span>
                                    @endif
                                </li>
                            @empty
                                <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing to buy.') }}</li>
                            @endforelse
                        </ul>
                    </section>
                                @break
                            @case('pages')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Recent pages')" :href="route('pages.index')" />
                        <ul class="mt-3 space-y-2">
                            @forelse ($recentNotes as $note)
                                <li wire:key="dashboard-note-{{ $note->id }}" class="flex items-center gap-2">
                                    <a href="{{ route('pages.index', ['item' => $note->id]) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:underline truncate">
                                        {{ $note->displayIcon() }} {{ $note->title }}
                                    </a>
                                    @if ($note->isPrivate())
                                        <x-private-badge />
                                    @endif
                                </li>
                            @empty
                                <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('No pages yet.') }}</li>
                            @endforelse
                        </ul>
                    </section>
                                @break
                            @case('meals')
                        <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                            <x-dashboard-card-header :title="__('Today on the meal plan')" :href="route('recipes.plan')" />
                            <ul class="mt-3 space-y-2">
                                @forelse ($todaysMeals as $meal)
                                    <li wire:key="dashboard-meal-{{ $meal->id }}" class="flex items-baseline gap-2">
                                        <span class="w-20 shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ \App\Models\MealPlan::mealTypeLabel($meal->meal_type) }}</span>
                                        @if ($meal->recipe)
                                            <a href="{{ route('recipes.show', $meal->recipe) }}" wire:navigate class="min-w-0 truncate text-indigo-600 dark:text-indigo-400 hover:underline">{{ $meal->recipe->title }}</a>
                                        @else
                                            <span class="min-w-0 truncate text-gray-900 dark:text-gray-100">{{ $meal->title }}</span>
                                        @endif
                                    </li>
                                @empty
                                    <li class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ __('Nothing planned yet.') }}
                                        <a href="{{ route('recipes.plan') }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 underline">{{ __('Plan the week') }}</a>
                                    </li>
                                @endforelse
                            </ul>
                        </section>
                                @break
                            @case('inbox')
                        <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                            <x-dashboard-card-header :title="__('Inbox')" :count="$inboxCount" :href="route('inbox.index')" />
                            <ul class="mt-3 space-y-1">
                                @forelse ($inboxEntries as $entry)
                                    <li wire:key="dashboard-inbox-{{ $entry->id }}-{{ $entry->updated_at?->timestamp }}">
                                        <a href="{{ route('inbox.index') }}" data-open-inbox="{{ $entry->id }}" class="block truncate text-gray-900 hover:underline dark:text-gray-100">{{ $entry->title }}</a>
                                    </li>
                                @empty
                                    <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('Inbox is empty.') }}</li>
                                @endforelse
                            </ul>
                        </section>
                                @break
                            @case('weather')
                        <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                            <x-dashboard-card-header :title="__('Weather today')" />
                            @if ($weather)
                                <div class="mt-3 flex items-center gap-4">
                                    <span class="text-5xl" aria-hidden="true">{{ $weather['icon'] }}</span>
                                    <div>
                                        <p class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $weather['min'] }}–{{ $weather['max'] }} °C</p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $weather['label'] }} · {{ __('rain :percent %', ['percent' => $weather['rain']]) }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ auth()->user()->weather_place }}</p>
                                    </div>
                                </div>
                            @elseif (auth()->user()->weather_place)
                                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('The weather service is not reachable right now.') }}</p>
                            @else
                                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                                    {{ __('Set your place in the profile to see the weather here.') }}
                                    <a href="{{ route('profile') }}#briefing-place" class="text-indigo-600 underline dark:text-indigo-400">{{ __('Set place') }}</a>
                                </p>
                            @endif
                        </section>
                                @break
                            @case('deadlines')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Deadlines (next 30 days)')" :count="count($deadlines) ?: null" />
                        <ul class="mt-3 space-y-2">
                            @forelse ($deadlines as $row)
                                <li wire:key="dashboard-deadline-{{ $row['entry']->id }}-{{ $row['property']->id }}" class="flex items-center gap-3">
                                    <span aria-hidden="true">{{ $row['collection']->displayIcon() }}</span>
                                    <a href="{{ route('collections.show', ['collection' => $row['collection']->id, 'entry' => $row['entry']->id]) }}" wire:navigate class="min-w-0 flex-1">
                                        <span class="block truncate text-gray-900 hover:underline dark:text-gray-100">{{ $row['entry']->title }}</span>
                                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $row['property']->name }}</span>
                                    </a>
                                    <span @class([
                                        'shrink-0 rounded-sm px-1.5 py-0.5 text-xs text-end',
                                        'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' => $row['days'] < 0,
                                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $row['days'] >= 0 && $row['days'] <= 7,
                                        'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $row['days'] > 7,
                                    ])>{{ \Illuminate\Support\Carbon::parse($row['date'])->translatedFormat('j. M') }}<br><span class="opacity-80">{{ $row['days'] === 0 ? __('today') : ($row['days'] > 0 ? trans_choice('in :count day|in :count days', $row['days']) : trans_choice(':count day ago|:count days ago', -$row['days'])) }}</span></span>
                                </li>
                            @empty
                                <li class="text-sm text-gray-500 dark:text-gray-400">{{ __('No deadlines in the next 30 days.') }}</li>
                            @endforelse
                        </ul>
                    </section>
                                @break
                            @case('portfolio')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Portfolio')" :href="route('portfolio.index')" />
                        @php($tone = fn (?float $value) => $value > 0 ? 'text-emerald-700 dark:text-emerald-400' : ($value < 0 ? 'text-orange-700 dark:text-orange-400' : 'text-gray-500 dark:text-gray-400'))
                        @if ($portfolio === null)
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Create a portfolio to see its value here.') }}</p>
                        @else
                            <p class="mt-2 text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($portfolio['value'], 2, ',', '.') }} €</p>
                            <p class="text-xs tabular-nums">
                                @if ($portfolio['day_change'] !== null)
                                    <span class="{{ $tone($portfolio['day_change']) }}">{{ __('Today') }} {{ $portfolio['day_change'] >= 0 ? '+' : '' }}{{ number_format($portfolio['day_change'], 2, ',', '.') }} €@if ($portfolio['day_change_percent'] !== null) ({{ number_format($portfolio['day_change_percent'], 2, ',', '.') }} %)@endif</span> ·
                                @endif
                                <span class="{{ $tone($portfolio['gain']) }}">{{ __('Total') }} {{ $portfolio['gain'] >= 0 ? '+' : '' }}{{ number_format($portfolio['gain'], 2, ',', '.') }} €</span>
                            </p>
                            @if ($portfolio['movers'] !== [])
                                <ul class="mt-3 space-y-1 text-sm">
                                    @foreach ($portfolio['movers'] as $mover)
                                        <li wire:key="dashboard-mover-{{ $mover['symbol'] }}" class="flex items-center justify-between gap-2">
                                            <a href="{{ route('portfolio.security', ['symbol' => $mover['symbol']]) }}" wire:navigate class="truncate text-gray-700 hover:underline dark:text-gray-300">{{ $mover['name'] }}</a>
                                            <span class="shrink-0 tabular-nums {{ $tone($mover['change']) }}">{{ $mover['change'] >= 0 ? '+' : '' }}{{ number_format($mover['change'], 2, ',', '.') }} %</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                        @if ($portfolioEvents !== [])
                            <ul class="mt-3 space-y-1 border-t border-gray-100 pt-3 text-xs text-gray-600 dark:border-gray-700 dark:text-gray-400">
                                @foreach ($portfolioEvents as $event)
                                    <li wire:key="dashboard-portfolio-event-{{ $event['uid'] }}" class="flex justify-between gap-2">
                                        <span class="truncate">{{ $event['kind'] === 'dividend' ? '💰' : '📈' }} {{ $event['title'] }}</span>
                                        <span class="shrink-0">{{ \Illuminate\Support\Carbon::parse($event['date'])->translatedFormat('j. M') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                                @break
                            @case('real_estate')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Real estate')" :href="route('real-estate.index')" />
                        @forelse ($realEstates as $row)
                            <a href="{{ route('real-estate.show', $row['model']) }}" wire:navigate wire:key="dashboard-real-estate-{{ $row['model']->id }}" class="mt-3 block rounded-lg p-2 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <p class="flex justify-between gap-2 text-sm"><span class="truncate font-medium text-gray-900 dark:text-gray-100">🏠 {{ $row['model']->name }}</span><span class="shrink-0 text-gray-500 dark:text-gray-400">{{ \App\Models\RealEstate::statusLabels()[$row['model']->status] ?? '' }}</span></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ __(':paid of :total paid', ['paid' => number_format($row['summary']['paid_total'], 0, ',', '.').' €', 'total' => number_format($row['summary']['expected_total'], 0, ',', '.').' €']) }}
                                    · <span @class(['text-red-700 dark:text-red-400' => $row['summary']['funding']['gap'] > 0])>{{ $row['summary']['funding']['gap'] > 0 ? '⚠ '.__('gap :amount', ['amount' => number_format($row['summary']['funding']['gap'], 0, ',', '.').' €']) : '✓ '.__('financed') }}</span>
                                </p>
                                @if ($row['summary']['progress'] !== null)
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ $row['summary']['progress'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Renovation progress') }}"><div class="h-full rounded-full bg-indigo-500" style="width: {{ $row['summary']['progress'] }}%"></div></div>
                                @endif
                            </a>
                        @empty
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No property yet.') }} <a href="{{ route('real-estate.index') }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-400">{{ __('Plan a property') }}</a></p>
                        @endforelse
                        @if ($realEstateEvents !== [])
                            <ul class="mt-3 space-y-1 border-t border-gray-100 pt-2 text-xs dark:border-gray-700">
                                @foreach ($realEstateEvents as $event)
                                    <li @class(['text-red-700 dark:text-red-400' => $event['overdue'], 'text-gray-600 dark:text-gray-300' => ! $event['overdue']])>{{ \Illuminate\Support\Carbon::parse($event['date'])->translatedFormat('j. M') }} · {{ $event['title'] }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                                @break
                            @case('tax')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Tax :year', ['year' => now()->year])" :href="route('finance.index', ['tab' => 'tax'])" />
                        @if ($taxYear)
                            <p @class(['mt-2 text-3xl font-semibold', 'text-green-700 dark:text-green-400' => $taxYear['effect'] >= 0, 'text-red-700 dark:text-red-400' => $taxYear['effect'] < 0])>≈ {{ number_format(abs($taxYear['effect']), 0, ',', '.') }} €</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $taxYear['effect'] >= 0 ? __('less income tax (estimate)') : __('more income tax (estimate)') }}</p>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ trans_choice(':count tax-relevant booking|:count tax-relevant bookings', $taxYear['bookings']->count()) }} · {{ __('deductible :amount', ['amount' => number_format($taxYear['deductible'], 0, ',', '.').' €']) }}</p>
                        @endif
                    </section>
                                @break
                            @case('costs')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Fixed costs per month')" />
                        @if ($monthlyCosts['collections'] === [])
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Add contracts with a monthly cost to see your fixed costs here.') }}</p>
                        @else
                            <p class="mt-2 text-3xl font-semibold text-gray-900 dark:text-gray-100">{{ \App\Support\PropertyDisplay::number($monthlyCosts['total'], true) }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('≈ :amount per year', ['amount' => \App\Support\PropertyDisplay::number($monthlyCosts['total'] * 12, true)]) }}</p>
                            <ul class="mt-3 space-y-1 text-sm">
                                @foreach ($monthlyCosts['collections'] as $row)
                                    <li wire:key="dashboard-costs-{{ $row['collection']->id }}" class="flex items-center justify-between gap-2">
                                        <a href="{{ route('collections.show', $row['collection']) }}" wire:navigate class="truncate text-gray-700 hover:underline dark:text-gray-300">{{ $row['collection']->displayIcon() }} {{ $row['collection']->name }} <span class="text-gray-400">({{ $row['count'] }})</span></a>
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ \App\Support\PropertyDisplay::number($row['sum'], true) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                                @break
                            @case('life')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Life & provisions: next dates')" :href="route('emergency.index')" />
                        @if ($lifeDates === [])
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No dates in the next 60 days – documents, vehicles and maintenance are all fine.') }}</p>
                        @else
                            <ul class="mt-3 space-y-1.5 text-sm">
                                @foreach ($lifeDates as $event)
                                    <li class="flex items-center justify-between gap-2">
                                        <a href="{{ $event['url'] }}" wire:navigate class="truncate text-gray-700 hover:underline dark:text-gray-300">{{ $event['title'] }}</a>
                                        <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Carbon::parse($event['date'])->format('d.m.') }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                                @break
                            @case('freedom')
                    <section class="bg-white dark:bg-gray-800 shadow-xs rounded-2xl p-6">
                        <x-dashboard-card-header :title="__('Financial freedom')" :href="route('cashflow.index')" />
                        @if ($freedom && $freedom['has_data'])
                            @php($freedomPercent = (int) round(min(1, $freedom['freedom']) * 100))
                            <p class="mt-2 text-3xl font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $freedomPercent }} %</p>
                            <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700"><div class="h-full rounded-full bg-emerald-600 dark:bg-emerald-500" style="width: {{ $freedomPercent }}%"></div></div>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Monthly cashflow') }}: <span @class(['font-medium tabular-nums', 'text-emerald-700 dark:text-emerald-400' => $freedom['cashflow'] >= 0, 'text-rose-700 dark:text-rose-400' => $freedom['cashflow'] < 0])>{{ number_format($freedom['cashflow'], 0, ',', '.') }} €</span></p>
                        @else
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('Import bank transactions in Finances, add your portfolio or properties – the analysis builds itself from them.') }}</p>
                        @endif
                    </section>
                                @break
                        @endswitch
                        @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
