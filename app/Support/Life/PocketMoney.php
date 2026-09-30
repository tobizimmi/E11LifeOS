<?php

namespace App\Support\Life;

use App\Models\HouseholdMember;
use App\Models\Kid;
use App\Models\KidChore;
use App\Models\KidChoreCompletion;
use App\Models\KidTransaction;
use App\Models\User;
use App\Support\WebPush;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pocket money for the children: chores are ticked off by the child, confirmed by a parent and
 * credited; the weekly allowance is booked automatically on its weekday.
 */
class PocketMoney
{
    /**
     * A child ticks off a chore: waiting for a parent, or credited right away with auto approval.
     */
    public static function complete(Kid $kid, KidChore $chore, ?User $by = null): KidChoreCompletion
    {
        $completion = $chore->completions()->create([
            'kid_id' => $kid->id,
            'done_on' => today()->toDateString(),
            'status' => KidChoreCompletion::PENDING,
            'reward' => $chore->reward,
        ]);

        if ($kid->auto_approve) {
            self::approve($completion, $by);
        } else {
            self::notifyParents($kid, $chore);
        }

        return $completion;
    }

    public static function approve(KidChoreCompletion $completion, ?User $by = null): void
    {
        if ($completion->status === KidChoreCompletion::APPROVED) {
            return;
        }

        $completion->update(['status' => KidChoreCompletion::APPROVED, 'reviewed_by' => $by?->id]);

        if ($completion->reward > 0) {
            KidTransaction::create([
                'kid_id' => $completion->kid_id,
                'date' => $completion->done_on->toDateString(),
                'kind' => 'chore',
                'label' => $completion->chore->title,
                'amount' => $completion->reward,
                'kid_chore_completion_id' => $completion->id,
                'created_by' => $by?->id,
            ]);
        }
    }

    public static function reject(KidChoreCompletion $completion, ?User $by = null): void
    {
        $completion->update(['status' => KidChoreCompletion::REJECTED, 'reviewed_by' => $by?->id]);
    }

    /**
     * Books the weekly allowance of every child whose weekday it is; returns how many were booked.
     */
    public static function payAllowances(?Carbon $day = null): int
    {
        $day ??= today();
        $paid = 0;

        Kid::whereNotNull('weekly_allowance')->where('weekly_allowance', '>', 0)->where('allowance_day', $day->dayOfWeekIso)
            ->where(fn ($query) => $query->whereNull('last_allowance_on')->orWhere('last_allowance_on', '<', $day->toDateString()))
            ->get()
            ->each(function (Kid $kid) use ($day, &$paid) {
                KidTransaction::create(['kid_id' => $kid->id, 'date' => $day->toDateString(), 'kind' => 'allowance', 'label' => __('Pocket money'), 'amount' => $kid->weekly_allowance]);
                $kid->update(['last_allowance_on' => $day->toDateString()]);
                $paid++;
            });

        return $paid;
    }

    /**
     * The chores a child can do today.
     *
     * @param  Collection<int, KidChore>  $chores
     * @return Collection<int, KidChore>
     */
    public static function openChores(Kid $kid, Collection $chores, ?Carbon $day = null): Collection
    {
        return $chores->filter(fn (KidChore $chore) => $chore->isOpenFor($kid, $day ?? today()))->values();
    }

    /**
     * Share of the savings goal reached with the current balance (0–100), null without a goal.
     */
    public static function goalProgress(Kid $kid, float $balance): ?float
    {
        return $kid->goal_amount ? round(min(100, max(0, $balance / $kid->goal_amount * 100)), 1) : null;
    }

    private static function notifyParents(Kid $kid, KidChore $chore): void
    {
        $webPush = app(WebPush::class);

        HouseholdMember::where('household_id', $kid->household_id)->with('user')->get()->pluck('user')->filter()
            ->each(fn (User $parent) => $webPush->send($parent, '⭐ '.__(':name has done ":chore"', ['name' => $kid->name, 'chore' => $chore->title]), __('Please confirm so the reward is credited.'), route('kids.index')));
    }
}
