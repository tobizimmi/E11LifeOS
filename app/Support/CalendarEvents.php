<?php

namespace App\Support;

use App\Models\CalendarSubscription;
use App\Models\Collection;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\RealEstateTenant;
use App\Models\User;
use App\Support\Portfolio\PortfolioLink;
use App\Support\RealEstate\RealEstateLink;
use Illuminate\Support\Carbon;

/**
 * Everything with a date across the modules, for the calendar page: tasks, collection dates with a
 * reminder, meals, property dates, rent due days, business invoices, portfolio dates and
 * subscribed external calendars (ICS).
 */
class CalendarEvents
{
    public const TASKS = 'tasks';

    public const DATES = 'dates';

    public const MEALS = 'meals';

    public const REAL_ESTATE = 'real_estate';

    public const BUSINESS = 'business';

    public const PORTFOLIO = 'portfolio';

    public const EXTERNAL = 'external';

    /**
     * @return array<string, array{label: string, icon: string, color: string}>
     */
    public static function sources(User $user): array
    {
        return array_filter([
            self::TASKS => ['label' => __('Tasks'), 'icon' => '✅', 'color' => 'indigo'],
            self::DATES => ['label' => __('Deadlines & dates'), 'icon' => '📌', 'color' => 'amber'],
            self::MEALS => $user->canUseAddon(Addons::RECIPES) ? ['label' => __('Meals'), 'icon' => '🍳', 'color' => 'emerald'] : null,
            self::REAL_ESTATE => $user->canUseAddon(Addons::REAL_ESTATE) ? ['label' => __('Real estate'), 'icon' => '🏠', 'color' => 'sky'] : null,
            self::BUSINESS => $user->canUseAddon(Addons::BUSINESS) ? ['label' => __('Small business'), 'icon' => '💼', 'color' => 'violet'] : null,
            self::PORTFOLIO => $user->canUseAddon(Addons::PORTFOLIO) ? ['label' => __('Portfolio'), 'icon' => '📈', 'color' => 'rose'] : null,
            self::EXTERNAL => CalendarSubscription::where('user_id', $user->id)->exists() ? ['label' => __('Other calendars'), 'icon' => '🗓️', 'color' => 'slate'] : null,
        ]);
    }

    /**
     * Events between two days (inclusive), sorted by date and time. Only tasks carry a task_id
     * and can be moved to another day.
     *
     * @param  list<string>  $sources
     * @return list<array{date: string, end: ?string, time: ?string, source: string, title: string, url: string, task_id: ?int, overdue: bool}>
     */
    public static function between(User $user, int $householdId, Carbon $from, Carbon $until, array $sources): array
    {
        $allowed = array_intersect($sources, array_keys(self::sources($user)));
        $events = [];

        if (in_array(self::TASKS, $allowed, true) || in_array(self::DATES, $allowed, true)) {
            array_push($events, ...self::collectionEvents($user, $householdId, $from, $until, $allowed));
        }

        if (in_array(self::MEALS, $allowed, true)) {
            array_push($events, ...self::mealEvents($user, $householdId, $from, $until));
        }

        if (in_array(self::REAL_ESTATE, $allowed, true)) {
            foreach (RealEstateLink::events($user, $householdId, $from, $until) as $event) {
                $events[] = self::event($event['date'], self::REAL_ESTATE, $event['title'], $event['url'], overdue: $event['overdue'], end: $event['end'] ?? null);
            }

            array_push($events, ...self::rentEvents($user, $householdId, $from, $until));
        }

        if (in_array(self::BUSINESS, $allowed, true)) {
            array_push($events, ...self::invoiceEvents($user, $householdId, $from, $until));
        }

        if (in_array(self::PORTFOLIO, $allowed, true)) {
            foreach (PortfolioLink::events($user, $from, $until) as $event) {
                $amount = $event['amount'] !== null ? ' ('.number_format($event['amount'], 2, ',', '.').' €)' : '';
                $events[] = self::event($event['date'], self::PORTFOLIO, ($event['kind'] === 'dividend' ? '💰 ' : '📈 ').$event['title'].$amount, route('portfolio.security', ['symbol' => $event['symbol']]));
            }
        }

        if (in_array(self::EXTERNAL, $allowed, true)) {
            foreach (CalendarSubscription::where('user_id', $user->id)->get() as $subscription) {
                foreach (IcsCalendar::eventsBetween($subscription, $from, $until) as $event) {
                    $events[] = self::event($event['date'], self::EXTERNAL, $event['title'].' · '.$subscription->name, route('calendar.index', ['date' => $event['date'], 'day' => $event['date']]), time: $event['time']);
                }
            }
        }

        usort($events, fn (array $a, array $b) => [$a['date'], $a['time'] ?? '99:99', $a['title']] <=> [$b['date'], $b['time'] ?? '99:99', $b['title']]);

        return $events;
    }

    /**
     * @param  list<string>  $allowed
     * @return list<array<string, mixed>>
     */
    private static function collectionEvents(User $user, int $householdId, Carbon $from, Carbon $until, array $allowed): array
    {
        $events = [];
        $fromDate = $from->toDateString();
        $untilDate = $until->toDateString();

        Collection::query()->visibleTo($user, $householdId)->with('properties')->get()
            ->each(function (Collection $collection) use ($user, $allowed, $fromDate, $untilDate, &$events) {
                $isTasks = $collection->isTasks();

                if (! in_array($isTasks ? self::TASKS : self::DATES, $allowed, true)) {
                    return;
                }

                $properties = $isTasks
                    ? collect([$collection->systemProperty('due')])->filter()
                    : $collection->properties->filter(fn ($property) => $property->remindDays() !== null);

                if ($properties->isEmpty()) {
                    return;
                }

                Item::query()->forHousehold($collection->household_id)->visibleTo($user)
                    ->where('collection_id', $collection->id)->where('done', false)->get()
                    ->each(function (Item $item) use ($collection, $isTasks, $properties, $fromDate, $untilDate, &$events) {
                        foreach ($properties as $property) {
                            $date = (string) $item->propertyValue($property);

                            if ($date === '' || $date < $fromDate || $date > $untilDate) {
                                continue;
                            }

                            $events[] = $isTasks
                                ? self::event($date, self::TASKS, $item->title, route('tasks.index', ['task' => $item->id]), time: $item->due_time, taskId: $item->id, overdue: $date < today()->toDateString())
                                : self::event($date, self::DATES, trim(($collection->icon ?: '📌').' '.$item->title.' – '.$property->name), route('collections.show', ['collection' => $collection->id, 'entry' => $item->id]));
                        }
                    });
            });

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function mealEvents(User $user, int $householdId, Carbon $from, Carbon $until): array
    {
        $icons = ['breakfast' => '🥐', 'lunch' => '🍝', 'dinner' => '🍲', 'snack' => '🍎'];

        return MealPlan::query()->visibleTo($user, $householdId)->with('recipe:id,title')
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $until->toDateString())
            ->get()
            ->map(fn (MealPlan $meal) => self::event(
                $meal->date->toDateString(),
                self::MEALS,
                ($icons[$meal->meal_type] ?? '🍳').' '.($meal->recipe?->title ?? $meal->title),
                $meal->recipe ? route('recipes.show', $meal->recipe_id) : route('recipes.plan'),
            ))
            ->all();
    }

    /**
     * The monthly due day of every tenant living in one of the member's properties.
     *
     * @return list<array<string, mixed>>
     */
    private static function rentEvents(User $user, int $householdId, Carbon $from, Carbon $until): array
    {
        $events = [];

        foreach (RealEstateLink::properties($user, $householdId)->load('tenants') as $realEstate) {
            foreach ($realEstate->tenants as $tenant) {
                /** @var RealEstateTenant $tenant */
                for ($month = $from->copy()->startOfMonth(); $month->lte($until); $month->addMonth()) {
                    $due = $month->copy()->day(min(28, max(1, (int) $tenant->due_day)));

                    if ($due->between($from, $until) && $tenant->isActiveOn($due)) {
                        $events[] = self::event($due->toDateString(), self::REAL_ESTATE, '🔑 '.__('Rent due: :tenant', ['tenant' => $tenant->name]).' ('.number_format($tenant->monthlyTotal(), 2, ',', '.').' €) · '.$realEstate->name, route('real-estate.show', ['realEstate' => $realEstate->id, 'tab' => 'rental']));
                    }
                }
            }
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function invoiceEvents(User $user, int $householdId, Carbon $from, Carbon $until): array
    {
        return Invoice::where('user_id', $user->id)->where('household_id', $householdId)
            ->where('type', Invoice::INVOICE)->where('status', Invoice::OPEN)
            ->whereDate('due_on', '>=', $from->toDateString())->whereDate('due_on', '<=', $until->toDateString())
            ->get()
            ->map(fn (Invoice $invoice) => self::event(
                $invoice->due_on->toDateString(),
                self::BUSINESS,
                '💼 '.__('Invoice :number due', ['number' => $invoice->number]).' · '.strtok((string) $invoice->recipient, "\n"),
                route('business.index', ['document' => $invoice->id]),
                overdue: $invoice->isOverdue(),
            ))
            ->all();
    }

    /**
     * @return array{date: string, end: ?string, time: ?string, source: string, title: string, url: string, task_id: ?int, overdue: bool}
     */
    private static function event(string $date, string $source, string $title, string $url, ?string $time = null, ?int $taskId = null, bool $overdue = false, ?string $end = null): array
    {
        return ['date' => $date, 'end' => $end, 'time' => $time, 'source' => $source, 'title' => $title, 'url' => $url, 'task_id' => $taskId, 'overdue' => $overdue];
    }
}
