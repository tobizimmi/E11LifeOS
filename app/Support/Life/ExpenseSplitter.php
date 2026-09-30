<?php

namespace App\Support\Life;

use App\Models\Expense;
use App\Models\ExpenseGroup;
use App\Models\ExpenseMember;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Shared expenses like Splid/Tricount: splits an expense exactly to the cent (equally, by shares
 * or by amounts), computes who is owed how much and the fewest transfers to settle up.
 */
class ExpenseSplitter
{
    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function categories(): array
    {
        return [
            'groceries' => ['label' => __('Groceries'), 'icon' => '🛒'],
            'restaurant' => ['label' => __('Restaurant & café'), 'icon' => '🍽️'],
            'drinks' => ['label' => __('Drinks & going out'), 'icon' => '🍻'],
            'accommodation' => ['label' => __('Accommodation'), 'icon' => '🏨'],
            'transport' => ['label' => __('Travel & tickets'), 'icon' => '🚆'],
            'fuel' => ['label' => __('Fuel & parking'), 'icon' => '⛽'],
            'activities' => ['label' => __('Activities & admission'), 'icon' => '🎟️'],
            'household' => ['label' => __('Household & rent'), 'icon' => '🏠'],
            'shopping' => ['label' => __('Shopping'), 'icon' => '🛍️'],
            'other' => ['label' => __('Other'), 'icon' => '🧾'],
        ];
    }

    /**
     * Splits a total between participants. For "equal" only the keys count, for "shares" the
     * values are weights, for "amounts" they are euros and must add up to the total.
     *
     * @param  array<int, float>  $values  member id => weight or amount
     * @return array<int, float> member id => euros (adding up exactly to the total)
     */
    public static function split(float $total, string $mode, array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException(__('Choose at least one person who shares the expense.'));
        }

        if ($mode === 'amounts') {
            $amounts = array_map(fn ($value) => round((float) $value, 2), $values);

            if (abs(array_sum($amounts) - $total) > 0.005) {
                throw new InvalidArgumentException(__('The amounts add up to :sum instead of :total.', ['sum' => number_format(array_sum($amounts), 2, ',', '.'), 'total' => number_format($total, 2, ',', '.')]));
            }

            return $amounts;
        }

        $weights = array_map(fn ($value) => $mode === 'equal' ? 1.0 : max(0.0, (float) $value), $values);
        $weightSum = array_sum($weights);

        if ($weightSum <= 0) {
            throw new InvalidArgumentException(__('Choose at least one person who shares the expense.'));
        }

        // Largest remainder: whole cents first, the leftover cents to the largest fractions.
        $cents = (int) round($total * 100);
        $result = [];
        $fractions = [];

        foreach ($weights as $memberId => $weight) {
            $exact = $cents * $weight / $weightSum;
            $result[$memberId] = (int) floor($exact);
            $fractions[$memberId] = $exact - floor($exact);
        }

        arsort($fractions);

        foreach (array_slice(array_keys($fractions), 0, $cents - array_sum($result)) as $memberId) {
            $result[$memberId]++;
        }

        return array_map(fn (int $value) => round($value / 100, 2), $result);
    }

    /**
     * Stores the split of an expense (replacing an earlier one).
     *
     * @param  array<int, float>  $values  member id => weight or amount (for "equal" any value)
     */
    public static function saveShares(Expense $expense, array $values): void
    {
        $amounts = self::split($expense->amount, $expense->split_mode, $values);
        $expense->shares()->delete();

        foreach ($amounts as $memberId => $amount) {
            $expense->shares()->create(['expense_member_id' => $memberId, 'value' => $expense->split_mode === 'equal' ? 1 : (float) $values[$memberId], 'amount' => $amount]);
        }
    }

    /**
     * Records a repayment: the payer "pays" the receiver's share, which settles the difference.
     */
    public static function recordTransfer(ExpenseGroup $group, int $fromMemberId, int $toMemberId, float $amount, ?int $userId = null): Expense
    {
        $to = $group->members->firstWhere('id', $toMemberId);
        $transfer = $group->expenses()->create([
            'paid_by' => $fromMemberId,
            'created_by' => $userId,
            'title' => __('Repayment to :name', ['name' => $to?->name]),
            'amount' => round($amount, 2),
            'spent_on' => today(),
            'category' => 'other',
            'split_mode' => 'amounts',
            'is_transfer' => true,
        ]);
        self::saveShares($transfer, [$toMemberId => round($amount, 2)]);

        return $transfer;
    }

    /**
     * What each participant paid and carries; a positive balance means the others owe them.
     *
     * @return array<int, array{member: ExpenseMember, paid: float, owed: float, balance: float}>
     */
    public static function balances(ExpenseGroup $group): array
    {
        $group->loadMissing(['members', 'expenses.shares']);
        $balances = [];

        foreach ($group->members as $member) {
            $balances[$member->id] = ['member' => $member, 'paid' => 0.0, 'owed' => 0.0, 'balance' => 0.0];
        }

        foreach ($group->expenses as $expense) {
            if (isset($balances[$expense->paid_by])) {
                $balances[$expense->paid_by]['paid'] += $expense->amount;
            }

            foreach ($expense->shares as $share) {
                if (isset($balances[$share->expense_member_id])) {
                    $balances[$share->expense_member_id]['owed'] += $share->amount;
                }
            }
        }

        foreach ($balances as $memberId => $row) {
            $balances[$memberId]['paid'] = round($row['paid'], 2);
            $balances[$memberId]['owed'] = round($row['owed'], 2);
            $balances[$memberId]['balance'] = round($row['paid'] - $row['owed'], 2);
        }

        return $balances;
    }

    /**
     * The fewest transfers that settle all balances (largest debtor pays largest creditor).
     *
     * @param  array<int, array{balance: float}>  $balances
     * @return list<array{from: int, to: int, amount: float}>
     */
    public static function settlements(array $balances): array
    {
        $debtors = [];
        $creditors = [];

        foreach ($balances as $memberId => $row) {
            $cents = (int) round($row['balance'] * 100);

            if ($cents < 0) {
                $debtors[$memberId] = -$cents;
            } elseif ($cents > 0) {
                $creditors[$memberId] = $cents;
            }
        }

        $transfers = [];

        while ($debtors !== [] && $creditors !== []) {
            arsort($debtors);
            arsort($creditors);
            $from = array_key_first($debtors);
            $to = array_key_first($creditors);
            $amount = min($debtors[$from], $creditors[$to]);
            $transfers[] = ['from' => $from, 'to' => $to, 'amount' => round($amount / 100, 2)];

            $debtors[$from] -= $amount;
            $creditors[$to] -= $amount;

            if ($debtors[$from] === 0) {
                unset($debtors[$from]);
            }

            if ($creditors[$to] === 0) {
                unset($creditors[$to]);
            }
        }

        return $transfers;
    }

    /**
     * Figures for the evaluation (repayments are not spending).
     *
     * @return array{total: float, count: int, per_person: float, by_category: array<string, float>, by_member: array<int, array{name: string, paid: float, share: float}>, by_day: array<string, float>, largest: ?Expense}
     */
    public static function statistics(ExpenseGroup $group): array
    {
        $group->loadMissing(['members', 'expenses.shares']);
        /** @var Collection<int, Expense> $spending */
        $spending = $group->expenses->reject->is_transfer;
        $total = round((float) $spending->sum('amount'), 2);
        $byMember = [];

        foreach ($group->members as $member) {
            $byMember[$member->id] = [
                'name' => $member->name,
                'paid' => round((float) $spending->where('paid_by', $member->id)->sum('amount'), 2),
                'share' => round((float) $spending->flatMap->shares->where('expense_member_id', $member->id)->sum('amount'), 2),
            ];
        }

        return [
            'total' => $total,
            'count' => $spending->count(),
            'per_person' => $group->members->count() > 0 ? round($total / $group->members->count(), 2) : 0.0,
            'by_category' => $spending->groupBy('category')->map(fn ($items) => round((float) $items->sum('amount'), 2))->sortDesc()->all(),
            'by_member' => $byMember,
            'by_day' => $spending->groupBy(fn (Expense $expense) => $expense->spent_on->toDateString())->map(fn ($items) => round((float) $items->sum('amount'), 2))->sortKeys()->all(),
            'largest' => $spending->sortByDesc('amount')->first(),
        ];
    }

    /**
     * All expenses with every participant's part, for spreadsheets (semicolon, German numbers).
     */
    public static function csv(ExpenseGroup $group): string
    {
        $group->loadMissing(['members', 'expenses.shares', 'expenses.payer']);
        $categories = self::categories();
        $number = fn (float $value) => number_format($value, 2, ',', '');
        $rows = [[__('Date'), __('Title'), __('Category'), __('Amount'), __('Paid by'), __('Settlement payment'), ...$group->members->pluck('name')->all()]];

        foreach ($group->expenses->sortBy('spent_on') as $expense) {
            $shares = $expense->shares->keyBy('expense_member_id');
            $rows[] = [
                $expense->spent_on->format('d.m.Y'),
                $expense->title,
                $categories[$expense->category]['label'] ?? $expense->category,
                $number($expense->amount),
                $expense->payer?->name,
                $expense->is_transfer ? __('Yes') : '',
                ...$group->members->map(fn (ExpenseMember $member) => $number((float) ($shares[$member->id]->amount ?? 0)))->all(),
            ];
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * The given ids that belong to participants of this group.
     *
     * @param  array<int|string, mixed>  $memberIds
     * @return list<int>
     */
    public static function validMemberIds(ExpenseGroup $group, array $memberIds): array
    {
        $allowed = $group->members->pluck('id')->all();

        return array_values(array_filter(array_map('intval', $memberIds), fn (int $id) => in_array($id, $allowed, true)));
    }
}
