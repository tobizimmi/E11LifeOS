<?php

namespace App\Console\Commands;

use App\Models\TrustedPerson;
use App\Notifications\EmergencyAccessUpdate;
use App\Support\WebPush;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('emergency:grant-access')]
#[Description('Open the emergency folder to trusted people whose waiting period passed without the owner declining')]
class GrantEmergencyAccess extends Command
{
    public function handle(WebPush $webPush): int
    {
        $granted = 0;

        TrustedPerson::query()->whereNotNull('requested_at')->whereNull('granted_at')->with('user')->each(function (TrustedPerson $person) use ($webPush, &$granted) {
            if ($person->grantsOn()->isFuture() || ! $person->user) {
                return;
            }

            $person->update(['granted_at' => now()]);
            Notification::route('mail', $person->email)->notify(new EmergencyAccessUpdate(EmergencyAccessUpdate::GRANTED, $person->user->name, $person->name));
            $webPush->send($person->user, '🔓 '.__(':name can now open your emergency folder', ['name' => $person->name]), __('The waiting period has passed. You can withdraw the access at any time.'), route('emergency.index', ['section' => 'access']));
            $granted++;
        });

        $this->info("Emergency access granted: {$granted}");

        return self::SUCCESS;
    }
}
