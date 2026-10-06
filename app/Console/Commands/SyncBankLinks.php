<?php

namespace App\Console\Commands;

use App\Models\BankLink;
use App\Models\User;
use App\Support\Banking\BankLinkSync;
use App\Support\Portfolio\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('banking:sync-links')]
#[Description('Pull balances and bookings of PSD2 bank connections (GoCardless) with automatic sync (runs every morning via the scheduler)')]
class SyncBankLinks extends Command
{
    public function handle(BankLinkSync $sync, Notifier $notifier): int
    {
        $done = 0;

        BankLink::where('auto_sync', true)->where('status', BankLink::STATUS_LINKED)->with('user')->each(function (BankLink $link) use ($sync, $notifier, &$done) {
            $user = $link->user;

            if (! $user instanceof User) {
                return;
            }

            if ($link->isExpired()) {
                $link->update(['status' => BankLink::STATUS_EXPIRED]);
                $notifier->send($user, '🏦 '.__('Bank connection expired'), __(':bank needs to be reconnected (the 90-day consent has run out).', ['bank' => $link->institution_name]), route('portfolio.banking'));

                return;
            }

            try {
                $sync->sync($link);
                $done++;
            } catch (Throwable $exception) {
                $notifier->send($user, '🏦 '.__('Bank sync failed'), $link->institution_name.': '.mb_strimwidth($exception->getMessage(), 0, 200, '…'), route('portfolio.banking'));
            }
        });

        $this->info("Bank links synced: {$done}");

        return self::SUCCESS;
    }
}
