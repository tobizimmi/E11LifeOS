<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Support\Calendar\GoogleCalendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * OAuth connect/callback for Google Calendar. Dormant (404) until the Google credentials are
 * configured; the tokens are stored encrypted on the user's CalendarConnection.
 */
class GoogleCalendarController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        abort_unless(GoogleCalendar::configured(), 404);

        $state = Str::random(40);
        $request->session()->put('google_calendar_state', $state);

        return redirect()->away(GoogleCalendar::authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(GoogleCalendar::configured(), 404);

        if ($request->filled('error') || ! $request->filled('code')
            || ! hash_equals((string) $request->session()->pull('google_calendar_state'), (string) $request->query('state'))) {
            return redirect()->route('profile')->with('status', __('Could not connect the calendar.'));
        }

        $tokens = GoogleCalendar::exchangeCode((string) $request->query('code'));

        $connection = CalendarConnection::updateOrCreate(
            ['user_id' => Auth::id(), 'provider' => 'google'],
            [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'token_expires_at' => $tokens['expires_at'],
                'calendar_id' => 'primary',
            ],
        );

        GoogleCalendar::pull($connection);

        return redirect()->route('profile')->with('status', __('Google Calendar connected.'));
    }

    public function disconnect(): RedirectResponse
    {
        CalendarConnection::where('user_id', Auth::id())->where('provider', 'google')->delete();

        return redirect()->route('profile')->with('status', __('Google Calendar disconnected.'));
    }
}
