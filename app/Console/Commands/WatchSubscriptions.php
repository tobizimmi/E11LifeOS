<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\User;
use App\Support\Addons;
use App\Support\Finance\SubscriptionAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:watch-subscriptions')]
#[Description('Subscription radar: notify each user about newly appeared recurring payments or ones that got more expensive (runs daily via the scheduler)')]
class WatchSubscriptions extends Command
{
    public function handle(): int
    {
        $userIds = Transaction::where('amount', '<', 0)
            ->where('booked_on', '>=', today()->subDays(400)->toDateString())
            ->distinct()
            ->pluck('user_id')
            ->filter()
            ->all();

        $notified = 0;

        foreach (User::whereKey($userIds)->get() as $user) {
            $household = $user->currentHousehold();

            if ($household === null || ! $user->canUseAddon(Addons::FINANCE)) {
                continue;
            }

            $result = SubscriptionAlerts::checkUser($user, $household->id);
            $notified += $result['new'] + $result['increased'];
        }

        $this->info("Subscription notifications sent: {$notified}");

        return self::SUCCESS;
    }
}
