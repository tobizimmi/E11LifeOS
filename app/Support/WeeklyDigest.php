<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\PortfolioSnapshot;
use App\Models\ShoppingListEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\Budgets;
use App\Support\Portfolio\PortfolioLink;
use Illuminate\Support\Carbon;

/**
 * The weekly summary of a member: tasks, deadlines, meal plan, shopping list and – for the
 * modules they may use – portfolio, finances and business. Only what the member may see.
 */
class WeeklyDigest
{
    public const CHANNELS = ['off', 'email', 'push', 'both'];

    /**
     * @return array{week: string, sections: list<array{icon: string, title: string, lines: list<string>, url: string}>}
     */
    public static function for(User $user): array
    {
        $household = $user->currentHousehold();
        $sections = [];

        if (! $household) {
            return ['week' => self::weekLabel(), 'sections' => []];
        }

        $sections[] = self::tasks($user, $household->id);
        $sections[] = self::deadlines($user, $household->id);

        if ($user->canUseAddon(Addons::RECIPES)) {
            $sections[] = self::meals($user, $household->id);
        }

        $openShopping = ShoppingListEntry::where('household_id', $household->id)->where('checked', false)->count();

        if ($openShopping > 0) {
            $sections[] = ['icon' => '🛒', 'title' => __('Shopping List'), 'lines' => [trans_choice(':count item still open|:count items still open', $openShopping)], 'url' => route('shopping-list.index')];
        }

        if ($user->canUseAddon(Addons::PORTFOLIO)) {
            $sections[] = self::portfolio($user);
        }

        if ($user->canUseAddon(Addons::FINANCE)) {
            $sections[] = self::finance($user, $household->id);
        }

        if ($user->canUseAddon(Addons::BUSINESS)) {
            $sections[] = self::business($household->id);
        }

        return ['week' => self::weekLabel(), 'sections' => array_values(array_filter($sections, fn (?array $section) => $section && $section['lines'] !== []))];
    }

    private static function weekLabel(): string
    {
        $start = now()->startOfWeek();

        return __('Week :week (:from – :to)', ['week' => now()->isoWeek(), 'from' => $start->translatedFormat('j. M'), 'to' => $start->copy()->endOfWeek()->translatedFormat('j. M')]);
    }

    /**
     * Done in the last 7 days, overdue and due in the next 7 days (own or unassigned own tasks).
     *
     * @return array{icon: string, title: string, lines: list<string>, url: string}
     */
    private static function tasks(User $user, int $householdId): array
    {
        $collection = Collection::tasksFor($householdId);
        $dueProperty = $collection->systemProperty('due');
        $assigneeProperty = $collection->systemProperty('assignee');
        $mine = fn (Item $task) => ($assignee = $assigneeProperty ? (string) $task->propertyValue($assigneeProperty) : '') !== ''
            ? $assignee === (string) $user->id
            : $task->created_by === $user->id;

        $tasks = Item::query()->forHousehold($householdId)->ofType(Item::TYPE_TODO)->visibleTo($user)->get()->filter($mine);
        $done = $tasks->filter(fn (Item $task) => $task->done && $task->completed_at?->gte(now()->subDays(7)))->count();
        $today = today()->toDateString();
        $open = $dueProperty ? $tasks->where('done', false)->map(fn (Item $task) => ['task' => $task, 'due' => (string) $task->propertyValue($dueProperty)])->filter(fn (array $row) => $row['due'] !== '') : collect();
        $overdue = $open->filter(fn (array $row) => $row['due'] < $today);
        $upcoming = $open->filter(fn (array $row) => $row['due'] >= $today && $row['due'] <= today()->addDays(7)->toDateString())->sortBy('due');

        $lines = [];

        if ($done > 0) {
            $lines[] = '✓ '.trans_choice(':count task done last week|:count tasks done last week', $done);
        }

        if ($overdue->isNotEmpty()) {
            $lines[] = '⚠ '.trans_choice(':count task is overdue|:count tasks are overdue', $overdue->count());
        }

        foreach ($upcoming->take(8) as $row) {
            $lines[] = Carbon::parse($row['due'])->translatedFormat('D j. M').': '.$row['task']->title;
        }

        if ($upcoming->count() > 8) {
            $lines[] = __('and :count more', ['count' => $upcoming->count() - 8]);
        }

        return ['icon' => '✅', 'title' => __('Tasks'), 'lines' => $lines, 'url' => route('tasks.index')];
    }

    /**
     * @return array{icon: string, title: string, lines: list<string>, url: string}
     */
    private static function deadlines(User $user, int $householdId): array
    {
        $lines = collect(DashboardWidgets::deadlines($user, $householdId, 6))
            ->filter(fn (array $row) => $row['days'] >= 0 && $row['days'] <= 14)
            ->map(fn (array $row) => Carbon::parse($row['date'])->translatedFormat('j. M').': '.$row['entry']->title.' – '.$row['property']->name)
            ->values()->all();

        return ['icon' => '🔔', 'title' => __('Deadlines (next 14 days)'), 'lines' => $lines, 'url' => route('dashboard')];
    }

    /**
     * @return array{icon: string, title: string, lines: list<string>, url: string}
     */
    private static function meals(User $user, int $householdId): array
    {
        $meals = MealPlan::query()->visibleTo($user, $householdId)->whereBetween('date', [today(), today()->addDays(6)])->with('recipe')->orderBy('date')->get();
        $lines = MealPlan::withVisibleRecipes($meals, $user, $householdId)
            ->groupBy(fn (MealPlan $meal) => $meal->date->toDateString())
            ->map(fn ($group, string $date) => Carbon::parse($date)->translatedFormat('D').': '.$group->map(fn (MealPlan $meal) => $meal->recipe?->title ?? $meal->title)->filter()->implode(', '))
            ->values()->all();

        return ['icon' => '🍳', 'title' => __('Meal plan'), 'lines' => $lines, 'url' => route('recipes.plan')];
    }

    /**
     * @return array{icon: string, title: string, lines: list<string>, url: string}
     */
    private static function portfolio(User $user): array
    {
        $summary = PortfolioLink::summary($user);
        $lines = [];

        if ($summary) {
            $weekAgo = PortfolioSnapshot::whereIn('portfolio_id', $user->portfolios()->pluck('id'))
                ->where('taken_at', '<=', now()->subDays(7))->orderByDesc('taken_at')->get()->unique('portfolio_id');
            $lines[] = __('Value: :value', ['value' => self::euro($summary['value'])]).' · '.__('total :gain', ['gain' => self::signedEuro($summary['gain'])]);

            if ($weekAgo->isNotEmpty()) {
                $change = $summary['value'] - $weekAgo->sum('total_value') - ($summary['cost'] - $weekAgo->sum('total_cost'));
                $lines[] = __('This week: :change', ['change' => self::signedEuro($change)]);
            }

            foreach ($summary['movers'] as $mover) {
                $lines[] = $mover['name'].': '.($mover['change'] >= 0 ? '+' : '').number_format($mover['change'], 2, ',', '.').' % '.__('today');
            }
        }

        foreach (array_slice(PortfolioLink::events($user, today(), today()->addDays(7)), 0, 4) as $event) {
            $lines[] = Carbon::parse($event['date'])->translatedFormat('j. M').': '.$event['title'].($event['amount'] ? ' ('.self::euro($event['amount']).')' : '');
        }

        return ['icon' => '📈', 'title' => __('Portfolio'), 'lines' => $lines, 'url' => route('portfolio.index')];
    }

    /**
     * @return array{icon: string, title: string, lines: list<string>, url: string}
     */
    private static function finance(User $user, int $householdId): array
    {
        // Bank data is personal: only the member's own bookings.
        $week = Transaction::where('user_id', $user->id)->where('household_id', $householdId)->where('booked_on', '>=', today()->subDays(7));
        $spent = (float) (clone $week)->where('amount', '<', 0)->sum('amount');
        $income = (float) (clone $week)->where('amount', '>', 0)->sum('amount');
        $lines = [];

        if ($spent !== 0.0 || $income !== 0.0) {
            $lines[] = __('Last 7 days: :spent spent, :income received', ['spent' => self::euro(-$spent), 'income' => self::euro($income)]);
        }

        foreach (Budgets::status($user, $householdId) as $row) {
            if ($row['percent'] >= 80) {
                $lines[] = ($row['percent'] >= 100 ? '🚨 ' : '⚠ ').__('Budget :category: :percent %', ['category' => $row['label'], 'percent' => number_format($row['percent'], 0, ',', '.')]);
            }
        }

        return ['icon' => '💶', 'title' => __('Finances'), 'lines' => $lines, 'url' => route('finance.index')];
    }

    /**
     * @return array{icon: string, title: string, lines: list<string>, url: string}
     */
    private static function business(int $householdId): array
    {
        $open = Invoice::where('household_id', $householdId)->where('type', Invoice::INVOICE)->where('status', Invoice::OPEN)->get();
        $overdue = $open->filter(fn (Invoice $invoice) => $invoice->isOverdue());
        $lines = [];

        if ($open->isNotEmpty()) {
            $lines[] = trans_choice(':count open invoice|:count open invoices', $open->count()).': '.self::euro($open->sum(fn (Invoice $invoice) => $invoice->outstanding()));
        }

        if ($overdue->isNotEmpty()) {
            $lines[] = '⚠ '.trans_choice(':count invoice is overdue|:count invoices are overdue', $overdue->count()).': '.self::euro($overdue->sum(fn (Invoice $invoice) => $invoice->outstanding()));
        }

        return ['icon' => '💼', 'title' => __('Business'), 'lines' => $lines, 'url' => route('business.index')];
    }

    private static function euro(float $value): string
    {
        return number_format($value, 2, ',', '.').' €';
    }

    private static function signedEuro(float $value): string
    {
        return ($value >= 0 ? '+' : '−').number_format(abs($value), 2, ',', '.').' €';
    }
}
