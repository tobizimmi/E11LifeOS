<?php

namespace App\Console\Commands;

use App\Models\MailAccount;
use App\Models\User;
use App\Support\Mail\MailSync;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('mail:sync {--limit=50 : Newest mails per account}')]
#[Description('Pulls new mails of the members\' personal mail accounts into the tool (read & reuse)')]
class SyncMailAccounts extends Command
{
    public function handle(MailSync $sync, WebPush $push): int
    {
        $accounts = MailAccount::query()->where('active', true)->get();
        $synced = 0;

        foreach ($accounts as $account) {
            try {
                $sinceId = (int) $account->messages()->max('id');
                $synced += $sync->syncAccount($account, (int) $this->option('limit'));
                $this->notifyNewMail($account, $sinceId, $push);
            } catch (Throwable $exception) {
                report($exception);
                $account->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 300)])->save();
                $this->warn("Account #{$account->id}: ".$exception->getMessage());
            }
        }

        $this->info("Synced {$synced} new mail(s) from {$accounts->count()} account(s).");

        return self::SUCCESS;
    }

    /**
     * Sends one push notification per account that just received new, unread inbox mail.
     */
    private function notifyNewMail(MailAccount $account, int $sinceId, WebPush $push): void
    {
        if (! $account->notify_new_mail) {
            return;
        }

        $new = $account->messages()->where('folder', 'INBOX')->where('id', '>', $sinceId)->where('is_read', false)->orderByDesc('id')->get();
        $user = $account->user;

        if ($new->isEmpty() || ! $user instanceof User) {
            return;
        }

        $latest = $new->firstOrFail();
        $title = '📬 '.trans_choice(':count new mail|:count new mails', $new->count(), ['count' => $new->count()]);
        $body = $latest->fromLabel().': '.($latest->subject ?: __('(No subject)'));

        $push->send($user, $title, mb_substr($body, 0, 200), route('mail.index'));
    }
}
