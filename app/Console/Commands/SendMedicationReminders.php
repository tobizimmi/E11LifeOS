<?php

namespace App\Console\Commands;

use App\Models\HealthEntry;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('health:medication-reminders')]
#[Description('Push a reminder at the times a medication is to be taken')]
class SendMedicationReminders extends Command
{
    public function handle(WebPush $webPush): int
    {
        $now = now()->format('H:i');
        $sent = 0;

        HealthEntry::query()->where('kind', HealthEntry::MEDICATION)->where('active', true)->whereNotNull('times')->with('profile.user')
            ->each(function (HealthEntry $medication) use ($webPush, $now, &$sent) {
                if (! in_array($now, (array) $medication->times, true) || ! $medication->profile?->user) {
                    return;
                }

                $webPush->send(
                    $medication->profile->user,
                    '💊 '.__('Time for :medication', ['medication' => $medication->title]),
                    trim($medication->profile->name.($medication->dosage ? ' · '.$medication->dosage : '')),
                    route('health.index', ['profile' => $medication->health_profile_id, 'tab' => 'medications']),
                );
                $sent++;
            });

        $this->info("Medication reminders: {$sent}");

        return self::SUCCESS;
    }
}
