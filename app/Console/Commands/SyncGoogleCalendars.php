<?php

namespace App\Console\Commands;

use App\Models\CalendarConnection;
use App\Support\Calendar\GoogleCalendar;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('calendar:sync-google')]
#[Description('Pull the upcoming events of all connected Google calendars into their cache')]
class SyncGoogleCalendars extends Command
{
    public function handle(): int
    {
        if (! GoogleCalendar::configured()) {
            $this->info('Google Calendar is not configured – skipped.');

            return self::SUCCESS;
        }

        $synced = 0;

        CalendarConnection::where('provider', 'google')->each(function (CalendarConnection $connection) use (&$synced) {
            try {
                GoogleCalendar::pull($connection);
                $synced++;
            } catch (Throwable $exception) {
                $connection->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 250)])->save();
            }
        });

        $this->info("Google calendars synced: {$synced}");

        return self::SUCCESS;
    }
}
