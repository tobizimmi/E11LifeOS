<?php

namespace App\Support\Finance;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Finds bookings that should probably not count as real income or expense: transfers between the
 * owner's own accounts (a debit matched by an equal credit on another account) and duplicates (the
 * same payment imported twice, e.g. once via CSV and once via the bank sync). It only *detects* –
 * the owner confirms each suggestion, which then excludes it from the reports.
 */
class TransactionReview
{
    /** The two legs of a transfer can be booked a few days apart. */
    public const TRANSFER_DAYS = 4;

    /** A duplicate of the same booking shows up within a day or two. */
    public const DUPLICATE_DAYS = 2;

    private const MAX_PAIRS = 100;

    /**
     * Candidate transfers: a debit on one account matched by a credit of the same size on another.
     *
     * @return list<array{out: Transaction, in: Transaction}>
     */
    public function transfers(User $user, int $householdId): array
    {
        $candidates = $this->candidates($user, $householdId)->filter(fn (Transaction $transaction) => $transaction->bank_account_id !== null);

        $creditsByAmount = $candidates->where('amount', '>', 0)->sortBy(fn (Transaction $transaction) => $transaction->booked_on->getTimestamp())
            ->groupBy(fn (Transaction $transaction) => number_format($transaction->amount, 2, '.', ''));

        $used = [];
        $pairs = [];

        foreach ($candidates->where('amount', '<', 0)->sortBy(fn (Transaction $transaction) => $transaction->booked_on->getTimestamp()) as $debit) {
            $need = number_format(-$debit->amount, 2, '.', '');

            foreach ($creditsByAmount->get($need) ?? [] as $credit) {
                if (isset($used[$credit->id]) || $credit->bank_account_id === $debit->bank_account_id) {
                    continue;
                }

                if (abs($debit->booked_on->diffInDays($credit->booked_on)) > self::TRANSFER_DAYS) {
                    continue;
                }

                $used[$credit->id] = true;
                $pairs[] = ['out' => $debit, 'in' => $credit];

                break;
            }

            if (count($pairs) >= self::MAX_PAIRS) {
                break;
            }
        }

        return $pairs;
    }

    /**
     * Candidate duplicates: two bookings with the same amount, almost the same date and the same
     * counterparty + purpose.
     *
     * @return list<array{keep: Transaction, duplicate: Transaction}>
     */
    public function duplicates(User $user, int $householdId): array
    {
        $pairs = [];
        $used = [];

        foreach ($this->candidates($user, $householdId)->groupBy(fn (Transaction $transaction) => $this->fingerprint($transaction)) as $group) {
            if ($group->count() < 2) {
                continue;
            }

            $sorted = $group->sortBy('id')->values();
            $keep = $sorted->firstOrFail();

            foreach ($sorted->slice(1) as $later) {
                if (isset($used[$later->id]) || abs($keep->booked_on->diffInDays($later->booked_on)) > self::DUPLICATE_DAYS) {
                    continue;
                }

                $used[$later->id] = true;
                $pairs[] = ['keep' => $keep, 'duplicate' => $later];

                if (count($pairs) >= self::MAX_PAIRS) {
                    return $pairs;
                }
            }
        }

        return $pairs;
    }

    /**
     * Bookings eligible for review: the owner's, not already marked as a transfer or confirmed
     * duplicate, not dismissed before, bounded to the last 18 months for speed.
     *
     * @return Collection<int, Transaction>
     */
    private function candidates(User $user, int $householdId): Collection
    {
        return Transaction::query()
            ->with('account')
            ->where('user_id', $user->id)
            ->where('household_id', $householdId)
            ->whereNull('duplicate_of_id')
            ->whereNull('review_ignored_at')
            ->where(fn ($query) => $query->whereNull('category')->orWhere('category', '!=', FinanceCategories::TRANSFER))
            ->where('booked_on', '>=', now()->subMonths(18)->toDateString())
            ->orderBy('id')
            ->limit(8000)
            ->get();
    }

    private function fingerprint(Transaction $transaction): string
    {
        $amount = number_format($transaction->amount, 2, '.', '');
        $party = mb_strtolower(trim((string) $transaction->counterparty));
        $purpose = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $transaction->purpose)));

        return $amount.'|'.$party.'|'.$purpose;
    }
}
