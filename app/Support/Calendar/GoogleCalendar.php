<?php

namespace App\Support\Calendar;

use App\Models\CalendarConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Google Calendar integration over the REST API (no SDK dependency). Handles the OAuth consent flow,
 * refreshing the access token, and pulling the upcoming events into the connection's cache so they
 * appear in the LifeOS calendar. Writing LifeOS events back to Google is the planned next step.
 *
 * It stays dormant until the Google OAuth credentials are configured (config/services.php).
 */
class GoogleCalendar
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/userinfo.email';

    public static function configured(): bool
    {
        return filled(config('services.google_calendar.client_id'))
            && filled(config('services.google_calendar.client_secret'))
            && filled(config('services.google_calendar.redirect'));
    }

    public static function authorizeUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google_calendar.client_id'),
            'redirect_uri' => config('services.google_calendar.redirect'),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Exchanges the OAuth code for tokens.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_at: Carbon}
     */
    public static function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'redirect_uri' => config('services.google_calendar.redirect'),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        return [
            'access_token' => (string) ($response['access_token'] ?? ''),
            'refresh_token' => $response['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
        ];
    }

    /**
     * A valid access token for the connection, refreshing it with the refresh token when expired.
     */
    public static function freshAccessToken(CalendarConnection $connection): ?string
    {
        if ($connection->token_expires_at && $connection->token_expires_at->isFuture()) {
            return $connection->access_token;
        }

        if (! $connection->refresh_token) {
            return null;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => config('services.google_calendar.client_id'),
            'client_secret' => config('services.google_calendar.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ])->throw()->json();

        $connection->forceFill([
            'access_token' => (string) ($response['access_token'] ?? ''),
            'token_expires_at' => now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
        ])->save();

        return $connection->access_token;
    }

    /**
     * Pulls the events of the next weeks into the connection cache. Returns how many were stored.
     */
    public static function pull(CalendarConnection $connection, int $days = 60): int
    {
        $token = self::freshAccessToken($connection);

        if (! $token) {
            return 0;
        }

        $response = Http::withToken($token)
            ->get('https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($connection->calendar_id).'/events', [
                'timeMin' => now()->startOfDay()->toRfc3339String(),
                'timeMax' => now()->addDays($days)->endOfDay()->toRfc3339String(),
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 250,
            ])->throw()->json();

        $events = collect($response['items'] ?? [])
            ->map(function (array $item) {
                $start = $item['start']['dateTime'] ?? $item['start']['date'] ?? null;

                if (! $start) {
                    return null;
                }

                $date = Carbon::parse($start);

                return [
                    'date' => $date->toDateString(),
                    'time' => isset($item['start']['dateTime']) ? $date->format('H:i') : null,
                    'title' => (string) ($item['summary'] ?? '—'),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $connection->forceFill([
            'cached_events' => $events,
            'last_synced_at' => now(),
            'last_error' => null,
        ])->save();

        return count($events);
    }
}
