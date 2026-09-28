<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Finance\NetWorth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('net-worth:snapshot')]
#[Description('Store today\'s net worth of every member who has something to count (history chart)')]
class SnapshotNetWorth extends Command
{
    public function handle(): int
    {
        $stored = 0;

        User::query()->each(function (User $user) use (&$stored) {
            try {
                $netWorth = NetWorth::for($user);

                if ($netWorth['assets'] == 0 && $netWorth['liabilities'] == 0) {
                    return;
                }

                NetWorth::snapshot($user);
                $stored++;
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        $this->info("Net worth snapshots: {$stored}");

        return self::SUCCESS;
    }
}
