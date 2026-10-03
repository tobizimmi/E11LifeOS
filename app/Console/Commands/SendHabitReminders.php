<?php

namespace App\Console\Commands;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('habits:remind')]
#[Description('Push a reminder for habits still open at their reminder time')]
class SendHabitReminders extends Command
{
    public function handle(WebPush $webPush): int
    {
        $today = today();
        $sent = 0;

        Habit::query()->whereNull('archived_at')->where('reminder_at', now()->format('H:i'))->with('user')
            ->each(function (Habit $habit) use ($webPush, $today, &$sent) {
                $value = (int) HabitLog::where('habit_id', $habit->id)->whereDate('date', $today->toDateString())->value('value');

                if (! $habit->user || ! $habit->isDueOn($today) || $habit->isFulfilledBy($value)) {
                    return;
                }

                $webPush->send(
                    $habit->user,
                    $habit->icon.' '.__('Time for: :habit', ['habit' => $habit->name]),
                    $habit->type === Habit::COUNT ? __(':done of :target :unit so far', ['done' => $value, 'target' => $habit->target, 'unit' => (string) $habit->unit]) : __('Tick it off in your journal.'),
                    route('journal.index'),
                );
                $sent++;
            });

        $this->info("Habit reminders: {$sent}");

        return self::SUCCESS;
    }
}
