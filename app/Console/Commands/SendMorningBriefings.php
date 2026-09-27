<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\MorningBriefingNotification;
use App\Support\MorningBriefing;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('briefing:morning {--user= : Only this e-mail address} {--force : Ignore the chosen hour}')]
#[Description('Send the morning briefing to everyone whose chosen hour is now (runs hourly via the scheduler)')]
class SendMorningBriefings extends Command
{
    public function handle(WebPush $webPush): int
    {
        $sent = 0;

        User::query()
            ->where('morning_briefing', '!=', 'off')
            ->when($this->option('user'), fn ($query, $email) => $query->where('email', $email))
            ->each(function (User $user) use ($webPush, &$sent) {
                if (! $this->option('force') && (now()->hour !== $user->morning_briefing_hour || $user->morning_briefing_sent_on?->isToday())) {
                    return;
                }

                self::deliver($user, $webPush);
                $sent++;
            });

        $this->info("Morning briefings sent: {$sent}");

        return self::SUCCESS;
    }

    public static function deliver(User $user, WebPush $webPush, ?string $channel = null): void
    {
        $channel ??= $user->morning_briefing;
        $briefing = MorningBriefing::for($user);

        if (in_array($channel, ['email', 'both'], true)) {
            $user->notify(new MorningBriefingNotification($briefing));
        }

        if (in_array($channel, ['push', 'both'], true)) {
            $webPush->send($user, __('Good morning').' · '.$briefing['date'], MorningBriefing::pushBody($briefing), route('dashboard'));
        }

        $user->forceFill(['morning_briefing_sent_on' => today()])->save();
    }
}
