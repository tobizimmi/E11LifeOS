<?php

namespace App\Support\Life;

use App\Models\Item;
use App\Models\ItemActivity;
use App\Models\MealPlan;
use App\Models\NetWorthSnapshot;
use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\RecipeRating;
use App\Models\ShoppingListEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLog;
use App\Support\Addons;
use App\Support\Finance\FinanceCategories;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A member's year at a glance: money (income, expenses, savings rate, biggest categories, net
 * worth, dividends), everyday life (tasks, pages, meals, shopping) and the car.
 */
class YearReview
{
    /**
     * @return array{year: int, finance: ?array{income: float, expenses: float, saved: float, rate: ?float, categories: list<array{label: string, icon: string, amount: float}>, best_month: ?string}, net_worth: ?array{start: float, end: float, change: float}, dividends: ?float, tasks: int, planned: int, pages: int, meals: int, top_recipe: ?string, cooked_ratings: int, shopping: int, km: ?int, vehicle_costs: ?float}
     */
    public static function for(User $user, int $year): array
    {
        $household = $user->currentHousehold();
        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = Carbon::create($year, 12, 31)->endOfDay();

        $itemIds = $household ? Item::where('household_id', $household->id)->select('id') : Item::whereRaw('1 = 0')->select('id');
        $activities = fn (string $action) => ItemActivity::whereIn('item_id', $itemIds)->where('user_id', $user->id)->where('action', $action)->whereBetween('created_at', [$from, $to])->count();

        $meals = $household ? MealPlan::query()->visibleTo($user, $household->id)->with('recipe:id,title')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get() : new Collection;
        $topRecipe = $meals->whereNotNull('recipe_id')->groupBy('recipe_id')->sortByDesc(fn (Collection $group) => $group->count())->first()?->first()?->recipe?->title;

        return [
            'year' => $year,
            'finance' => self::finance($user, $household?->id, $from, $to),
            'net_worth' => self::netWorth($user, $from, $to),
            'dividends' => $user->canUseAddon(Addons::PORTFOLIO)
                ? round(PortfolioTransaction::whereIn('portfolio_id', Portfolio::where('user_id', $user->id)->select('id'))->where('type', 'dividend')->whereBetween('executed_on', [$from->toDateString(), $to->toDateString()])->get()->sum(fn (PortfolioTransaction $transaction) => $transaction->cashFlow()), 2)
                : null,
            'tasks' => $activities('completed'),
            'planned' => $activities('created'),
            'pages' => $household ? Item::where('household_id', $household->id)->where('type', Item::TYPE_NOTE)->where('created_by', $user->id)->whereBetween('created_at', [$from, $to])->count() : 0,
            'meals' => $meals->count(),
            'top_recipe' => $topRecipe,
            'cooked_ratings' => RecipeRating::where('user_id', $user->id)->whereBetween('created_at', [$from, $to])->count(),
            'shopping' => $household ? ShoppingListEntry::where('household_id', $household->id)->where('created_by', $user->id)->whereBetween('created_at', [$from, $to])->count() : 0,
            ...self::vehicles($user, $household?->id, $from, $to),
        ];
    }

    /**
     * @return ?array{income: float, expenses: float, saved: float, rate: ?float, categories: list<array{label: string, icon: string, amount: float}>, best_month: ?string}
     */
    private static function finance(User $user, ?int $householdId, Carbon $from, Carbon $to): ?array
    {
        if (! $householdId || ! $user->canUseAddon(Addons::FINANCE)) {
            return null;
        }

        $transactions = Transaction::where('user_id', $user->id)->where('household_id', $householdId)->whereBetween('booked_on', [$from->toDateString(), $to->toDateString()])
            ->where('category', '!=', FinanceCategories::TRANSFER)->get();

        if ($transactions->isEmpty()) {
            return null;
        }

        $income = (float) $transactions->where('amount', '>', 0)->sum('amount');
        $spending = $transactions->filter(fn (Transaction $transaction) => $transaction->amount < 0 && $transaction->category !== 'savings');
        $expenses = (float) -$spending->sum('amount');
        $bestMonth = $transactions->groupBy(fn (Transaction $transaction) => $transaction->booked_on->format('Y-m'))
            ->map(fn (Collection $month) => (float) $month->where('amount', '>', 0)->sum('amount') + (float) $month->filter(fn (Transaction $transaction) => $transaction->amount < 0 && $transaction->category !== 'savings')->sum('amount'))
            ->sortDesc()->keys()->first();

        return [
            'income' => round($income, 2),
            'expenses' => round($expenses, 2),
            'saved' => round($income - $expenses, 2),
            'rate' => $income > 0 ? round(($income - $expenses) / $income * 100, 1) : null,
            'categories' => $spending->groupBy('category')->map(fn (Collection $group, string $category) => ['label' => FinanceCategories::label($category), 'icon' => FinanceCategories::icon($category), 'amount' => round((float) -$group->sum('amount'), 2)])
                ->sortByDesc('amount')->take(5)->values()->all(),
            'best_month' => $bestMonth ? Carbon::createFromFormat('!Y-m', $bestMonth)->translatedFormat('F') : null,
        ];
    }

    /**
     * @return ?array{start: float, end: float, change: float}
     */
    private static function netWorth(User $user, Carbon $from, Carbon $to): ?array
    {
        $snapshots = NetWorthSnapshot::where('user_id', $user->id)->whereBetween('taken_on', [$from->toDateString(), $to->toDateString()])->orderBy('taken_on')->get();

        if ($snapshots->count() < 2) {
            return null;
        }

        $start = (float) $snapshots->first()->assets - (float) $snapshots->first()->liabilities;
        $end = (float) $snapshots->last()->assets - (float) $snapshots->last()->liabilities;

        return ['start' => round($start, 2), 'end' => round($end, 2), 'change' => round($end - $start, 2)];
    }

    /**
     * @return array{km: ?int, vehicle_costs: ?float}
     */
    private static function vehicles(User $user, ?int $householdId, Carbon $from, Carbon $to): array
    {
        if (! $householdId) {
            return ['km' => null, 'vehicle_costs' => null];
        }

        $vehicleIds = Vehicle::query()->visibleTo($user, $householdId)->pluck('id');

        if ($vehicleIds->isEmpty()) {
            return ['km' => null, 'vehicle_costs' => null];
        }

        $logs = VehicleLog::whereIn('vehicle_id', $vehicleIds)->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
        $km = $logs->whereNotNull('odometer')->groupBy('vehicle_id')->sum(fn (Collection $group) => $group->max('odometer') - $group->min('odometer'));

        return ['km' => (int) $km, 'vehicle_costs' => round((float) $logs->where('kind', '!=', 'trip')->sum('amount'), 2)];
    }
}
