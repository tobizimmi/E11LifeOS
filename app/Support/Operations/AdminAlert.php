<?php

namespace App\Support\Operations;

use App\Models\User;
use App\Support\WebPush;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the administrators about a problem of the server – as push (and Pushover) to every
 * admin and by e-mail to the error report address. The same alert at most once per period.
 */
class AdminAlert
{
    public static function send(string $key, string $title, string $body, int $minutes = 60, bool $mail = true): bool
    {
        if (! Cache::add('admin-alert:'.$key, true, now()->addMinutes($minutes))) {
            return false;
        }

        try {
            foreach (User::where('is_admin', true)->get() as $admin) {
                app(WebPush::class)->send($admin, $title, $body, route('admin.system'), urgent: true);
            }

            if ($mail && filled($to = config('security.error_report_email'))) {
                Mail::raw($body, fn ($message) => $message->to($to)->subject('['.config('app.name').'] '.$title));
            }
        } catch (Throwable) {
            // Alerting must never break what triggered it.
        }

        return true;
    }
}
