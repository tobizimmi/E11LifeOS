<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\CalendarFeed;
use Illuminate\Http\Response;

/**
 * Personal calendar subscription (/calendar/{token}.ics). Calendar apps cannot log in,
 * so the long random token in the URL is the key; it can be renewed or switched off in the profile.
 */
class CalendarFeedController extends Controller
{
    public function __invoke(string $token): Response
    {
        $user = strlen($token) === 40 ? User::whereShareToken('calendar_token', $token)->first() : null;

        abort_unless($user, 404);

        return response(CalendarFeed::forUser($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="e11lifeos.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }
}
