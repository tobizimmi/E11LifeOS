<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\RealEstateReminder;
use App\Support\RealEstate\RealEstateLink;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('real-estate:reminders')]
#[Description('Remind of invoices due, subsidy deadlines and trades starting (a week before, the day before and on the day; overdue invoices on Mondays)')]
class SendRealEstateReminders extends Command
{
    public function handle(WebPush $webPush): int
    {
        $sent = 0;
        $remindOn = [today()->toDateString(), today()->addDay()->toDateString(), today()->addDays(7)->toDateString()];

        User::query()
            ->where(fn ($query) => $query->where('reminder_emails', true)->orWhereHas('pushSubscriptions'))
            ->each(function (User $user) use ($webPush, $remindOn, &$sent) {
                $household = $user->currentHousehold();

                if (! $household) {
                    return;
                }

                $events = collect(RealEstateLink::events($user, $household->id, today()->subDays(60), today()->addDays(7)))
                    ->filter(fn (array $event) => in_array($event['date'], $remindOn, true) || ($event['overdue'] && $event['kind'] === 'invoice' && today()->isMonday()))
                    ->values();

                if ($events->isEmpty()) {
                    return;
                }

                if ($user->reminder_emails) {
                    $user->notify(new RealEstateReminder($events->all()));
                }

                $webPush->send($user, trans_choice('🏠 :count date for your property|🏠 :count dates for your properties', $events->count()), $events->take(4)->pluck('title')->implode("\n"), $events->first()['url']);
                $sent++;
            });

        $this->info("Real estate reminders: {$sent}");

        return self::SUCCESS;
    }
}
