<?php

namespace App\Support\Finance;

use App\Models\SubscriptionWatch;
use App\Models\User;
use App\Support\InAppNotifications;

/**
 * Proactive subscription radar: compares the recurring payments found by {@see SubscriptionMatcher}
 * with what was seen before and notifies the user when a new subscription appears or an existing
 * one gets more expensive. The seen state lives in {@see SubscriptionWatch}.
 */
class SubscriptionAlerts
{
    /** A price rise only counts above this share and absolute amount, so cents do not notify. */
    private const RISE_FACTOR = 1.05;

    private const RISE_MIN_EURO = 1.0;

    /**
     * Checks one user's subscriptions. On the very first run it only seeds the seen state (no
     * notifications), so existing subscriptions are not all reported as new.
     *
     * @return array{new: int, increased: int, seeded: bool}
     */
    public static function checkUser(User $user, int $householdId, bool $notify = true): array
    {
        // Only genuinely recurring payments (a detected frequency), not one-off payees.
        $payees = collect(SubscriptionMatcher::payees($user, $householdId))
            ->filter(fn (array $payee) => ($payee['frequency'] ?? null) !== null && $payee['key'] !== '')
            ->keyBy('key');

        $watches = SubscriptionWatch::where('user_id', $user->id)->get()->keyBy('payment_key');
        $firstRun = $watches->isEmpty();
        $new = 0;
        $increased = 0;

        foreach ($payees as $key => $payee) {
            $amount = (float) $payee['amount'];
            $name = (string) $payee['name'];
            $frequency = is_string($payee['frequency'] ?? null) ? $payee['frequency'] : null;
            $watch = $watches->get($key);

            if ($watch === null) {
                if (! $firstRun && $notify) {
                    $new++;
                    InAppNotifications::record(
                        $user,
                        __('New subscription detected: :name', ['name' => $name]),
                        __(':amount :frequency – add it as a contract or cancel it.', [
                            'amount' => self::euro($amount),
                            'frequency' => self::frequency($frequency),
                        ]),
                        route('contracts.index'),
                    );
                }

                SubscriptionWatch::create(['user_id' => $user->id, 'household_id' => $householdId, 'payment_key' => (string) $key, 'name' => mb_substr($name, 0, 255), 'amount' => $amount]);

                continue;
            }

            if ($notify && $amount > $watch->amount * self::RISE_FACTOR && $amount - $watch->amount >= self::RISE_MIN_EURO) {
                $increased++;
                InAppNotifications::record(
                    $user,
                    __('Subscription got more expensive: :name', ['name' => $name]),
                    __('Now :new instead of :old.', ['new' => self::euro($amount), 'old' => self::euro($watch->amount)]),
                    route('contracts.index'),
                );
            }

            // Track the latest amount (also when it drops) so the next rise is measured from here.
            if (abs($amount - $watch->amount) >= 0.01 || $watch->name !== $name) {
                $watch->update(['amount' => $amount, 'name' => mb_substr($name, 0, 255)]);
            }
        }

        return ['new' => $new, 'increased' => $increased, 'seeded' => $firstRun];
    }

    private static function euro(float $value): string
    {
        return number_format($value, 2, ',', '.').' €';
    }

    private static function frequency(?string $frequency): string
    {
        return match ($frequency) {
            'weekly' => __('weekly'),
            'monthly' => __('monthly'),
            'bimonthly' => __('every 2 months'),
            'quarterly' => __('quarterly'),
            'halfyearly' => __('half-yearly'),
            'yearly' => __('yearly'),
            default => (string) $frequency,
        };
    }
}
