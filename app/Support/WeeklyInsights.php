<?php

namespace App\Support;

use App\Models\Item;
use App\Models\NetWorthSnapshot;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\FinanceCategories;

/**
 * Short observations for the weekly review, each comparing the last seven days with the time
 * before: finished tasks, spending, net worth, upcoming cancellation deadlines and a full inbox.
 * Everything is computed from the member's own data; nothing is sent anywhere.
 */
class WeeklyInsights
{
    /**
     * @return list<string>
     */
    public static function for(User $user, int $householdId): array
    {
        return array_values(array_filter([
            self::tasks($user, $householdId),
            ...($user->canUseAddon(Addons::FINANCE) ? self::spending($user, $householdId) : []),
            self::netWorth($user),
            self::deadlines($user, $householdId),
            self::inbox($user, $householdId),
        ]));
    }

    private static function tasks(User $user, int $householdId): ?string
    {
        $finished = Item::query()->forHousehold($householdId)->ofType(Item::TYPE_TODO)->visibleTo($user)
            ->where('done', true)->where('completed_at', '>=', now()->subDays(14))->pluck('completed_at');
        $thisWeek = $finished->filter(fn ($at) => $at->gte(now()->subDays(7)))->count();
        $lastWeek = $finished->count() - $thisWeek;

        if ($thisWeek === 0 && $lastWeek === 0) {
            return null;
        }

        return match (true) {
            $thisWeek > $lastWeek => '📈 '.__(':this tasks done – :more more than the week before.', ['this' => $thisWeek, 'more' => $thisWeek - $lastWeek]),
            $thisWeek < $lastWeek => '📉 '.__(':this tasks done – the week before it were :last.', ['this' => $thisWeek, 'last' => $lastWeek]),
            default => '➖ '.__(':this tasks done, as many as the week before.', ['this' => $thisWeek]),
        };
    }

    /**
     * Spending of the last 7 days against the weekly average of the four weeks before, and the
     * category that grew the most.
     *
     * @return list<string>
     */
    private static function spending(User $user, int $householdId): array
    {
        $bookings = Transaction::where('user_id', $user->id)->where('household_id', $householdId)
            ->where('amount', '<', 0)->whereDate('booked_on', '>=', today()->subDays(34)->toDateString())
            ->whereNotIn('category', array_keys(array_filter(FinanceCategories::all(), fn (array $category) => in_array($category['group'], ['income', 'transfer'], true))))
            ->get(['id', 'amount', 'category', 'booked_on']);
        $recent = $bookings->filter(fn (Transaction $transaction) => $transaction->booked_on->gt(today()->subDays(7)));
        $before = $bookings->diff($recent);

        if ($recent->isEmpty() || $before->isEmpty()) {
            return [];
        }

        $spent = -$recent->sum('amount');
        $average = -$before->sum('amount') / 4;
        $lines = [];

        if ($average > 0 && abs($spent - $average) / $average >= 0.2) {
            $replace = ['spent' => number_format($spent, 2, ',', '.').' €', 'percent' => number_format(abs($spent - $average) / $average * 100, 0, ',', '.')];
            $lines[] = $spent > $average
                ? '💸 '.__('Spent :spent in the last 7 days – :percent % more than an average week.', $replace)
                : '👍 '.__('Spent :spent in the last 7 days – :percent % less than an average week.', $replace);
        }

        $growth = $recent->groupBy('category')->map(fn ($group, string $category) => -$group->sum('amount') - (-$before->where('category', $category)->sum('amount') / 4))->sortDesc();
        $category = $growth->keys()->first();

        if ($category !== null && $growth->first() >= 30) {
            $lines[] = '🔎 '.__(':category: :amount more than usual this week.', ['category' => FinanceCategories::label($category), 'amount' => number_format($growth->first(), 2, ',', '.').' €']);
        }

        return $lines;
    }

    private static function netWorth(User $user): ?string
    {
        $snapshots = NetWorthSnapshot::where('user_id', $user->id)->whereDate('taken_on', '>=', today()->subDays(7)->toDateString())->orderBy('taken_on')->get();

        if ($snapshots->count() < 2) {
            return null;
        }

        $change = ($snapshots->last()->assets - $snapshots->last()->liabilities) - ($snapshots->first()->assets - $snapshots->first()->liabilities);

        if (abs($change) < 1) {
            return null;
        }

        return ($change > 0 ? '💎 ' : '🔻 ').__('Net worth :change this week.', ['change' => ($change > 0 ? '+' : '−').number_format(abs($change), 2, ',', '.').' €']);
    }

    private static function deadlines(User $user, int $householdId): ?string
    {
        $deadlines = ContractOverview::for($user, $householdId, 30)['deadlines'];

        if ($deadlines === []) {
            return null;
        }

        return '⏰ '.trans_choice(':count contract can be cancelled within 30 days: :names.|:count contracts can be cancelled within 30 days: :names.', count($deadlines), [
            'names' => collect($deadlines)->take(3)->map(fn (array $contract) => $contract['item']->title)->implode(', '),
        ]);
    }

    private static function inbox(User $user, int $householdId): ?string
    {
        $waiting = Item::query()->forHousehold($householdId)->ofType(Item::TYPE_INBOX)->visibleTo($user)
            ->where('done', false)->where('created_at', '<=', now()->subDays(7))->count();

        return $waiting >= 5 ? '📥 '.trans_choice(':count inbox entry has been waiting for over a week.|:count inbox entries have been waiting for over a week.', $waiting) : null;
    }
}
