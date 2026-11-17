<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use App\Support\Mail\MailSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('mail:sync {--limit=50 : Newest mails per account}')]
#[Description('Pulls new mails of the members\' personal mail accounts into the tool (read & reuse)')]
class SyncMailAccounts extends Command
{
    public function handle(MailSync $sync): int
    {
        $accounts = MailAccount::query()->where('active', true)->get();
        $synced = 0;

        foreach ($accounts as $account) {
            try {
                $synced += $sync->sync($account, 'INBOX', (int) $this->option('limit'));
            } catch (Throwable $exception) {
                report($exception);
                $account->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 300)])->save();
                $this->warn("Account #{$account->id}: ".$exception->getMessage());
            }
        }

        $this->info("Synced {$synced} new mail(s) from {$accounts->count()} account(s).");

        return self::SUCCESS;
    }
}
