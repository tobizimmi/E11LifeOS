<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WeeklyDigestNotification;
use App\Support\WebPush;
use App\Support\WeeklyDigest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('digest:weekly {--user= : Only this e-mail address} {--force : Ignore the chosen day and hour}')]
#[Description('Send the weekly summary to everyone whose chosen weekday and hour is now (runs hourly via the scheduler)')]
class SendWeeklyDigests extends Command
{
    public function handle(WebPush $webPush): int
    {
        $sent = 0;

        User::query()
            ->where('weekly_digest', '!=', 'off')
            ->when($this->option('user'), fn ($query, $email) => $query->where('email', $email))
            ->each(function (User $user) use ($webPush, &$sent) {
                if (! $this->option('force') && ! $this->isDue($user)) {
                    return;
                }

                self::deliver($user, $webPush);
                $sent++;
            });

        $this->info("Weekly summaries sent: {$sent}");

        return self::SUCCESS;
    }

    /**
     * The chosen weekday and hour, at most once per week.
     */
    private function isDue(User $user): bool
    {
        return now()->isoWeekday() === $user->weekly_digest_day
            && now()->hour === $user->weekly_digest_hour
            && (! $user->weekly_digest_sent_at || $user->weekly_digest_sent_at->lt(now()->subDays(6)));
    }

    public static function deliver(User $user, WebPush $webPush, ?string $channel = null): void
    {
        $channel ??= $user->weekly_digest;
        $digest = WeeklyDigest::for($user);

        if (in_array($channel, ['email', 'both'], true)) {
            $user->notify(new WeeklyDigestNotification($digest));
        }

        if (in_array($channel, ['push', 'both'], true)) {
            $lines = collect($digest['sections'])->map(fn (array $section) => $section['icon'].' '.($section['lines'][0] ?? $section['title']));
            $webPush->send($user, __('Your week').' · '.$digest['week'], $lines->take(5)->implode("\n") ?: __('A quiet week – nothing due.'), route('dashboard'));
        }

        $user->forceFill(['weekly_digest_sent_at' => now()])->save();
    }
}
