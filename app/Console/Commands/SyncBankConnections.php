<?php

namespace App\Console\Commands;

use App\Models\BankConnection;
use App\Support\Banking\BankSync;
use App\Support\Portfolio\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('banking:sync')]
#[Description('Fetch holdings and bookings of bank connections with automatic sync (runs every morning via the scheduler)')]
class SyncBankConnections extends Command
{
    public function handle(BankSync $sync, Notifier $notifier): int
    {
        $done = 0;

        BankConnection::where('auto_sync', true)->whereNotNull('pin')->with('user')->each(function (BankConnection $connection) use ($sync, $notifier, &$done) {
            foreach ($connection->portfolio_id ? ['depot', 'statement'] : ['statement'] as $operation) {
                try {
                    $result = $sync->run($connection, $operation);
                } catch (Throwable $exception) {
                    $notifier->send($connection->user, '🏦 '.__('Bank sync failed'), $connection->bank_name.': '.mb_strimwidth($exception->getMessage(), 0, 200, '…'), route('portfolio.banking'));

                    return;
                }

                if ($result['status'] === 'tan_required') {
                    // Banks ask for strong authentication regularly (at least every 90 days).
                    $notifier->send($connection->user, '🔐 '.__('Bank sync needs your confirmation'), __(':bank asks for a TAN – please open the bank sync and start it once by hand.', ['bank' => $connection->bank_name]), route('portfolio.banking'));

                    return;
                }

                $done++;
            }
        });

        $this->info("Bank syncs completed: {$done}");

        return self::SUCCESS;
    }
}
