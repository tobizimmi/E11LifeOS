<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\LifeReminder;
use App\Support\Life\LifeDates;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('life:reminders')]
#[Description('Remind of expiring documents and other dates of the life areas (at their lead time, a week and a day before and on the day)')]
class SendLifeReminders extends Command
{
    public function handle(WebPush $webPush): int
    {
        $sent = 0;

        User::query()->each(function (User $user) use ($webPush, &$sent) {
            $events = LifeDates::dueForReminder($user);

            if ($events === []) {
                return;
            }

            if ($user->reminder_emails) {
                $user->notify(new LifeReminder($events));
            }

            $webPush->send($user, trans_choice('📌 :count upcoming date|📌 :count upcoming dates', count($events)), collect($events)->take(4)->map(fn (array $event) => Carbon::parse($event['date'])->format('d.m.').' '.$event['title'])->implode("\n"), $events[0]['url']);
            $sent++;
        });

        $this->info("Life reminders: {$sent}");

        return self::SUCCESS;
    }
}
