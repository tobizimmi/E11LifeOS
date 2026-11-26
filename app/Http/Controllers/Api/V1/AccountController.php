<?php

namespace App\Http\Controllers\Api\V1;

use App\Console\Commands\SendMorningBriefings;
use App\Console\Commands\SendWeeklyDigests;
use App\Models\ApiToken;
use App\Models\CalendarConnection;
use App\Models\PaperlessInstance;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Calendar\GoogleCalendar;
use App\Support\CalendarFeed;
use App\Support\MorningBriefing;
use App\Support\Paperless\PaperlessClient;
use App\Support\Security\SecurityLog;
use App\Support\TwoFactor;
use App\Support\Weather;
use App\Support\WebPush;
use App\Support\WeeklyDigest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * "Account & settings" for the app: everything on the web profile page. Anything that changes how
 * the account is protected needs the password again (wrong ones are rate limited and logged).
 */
class AccountController extends ApiController
{
    public function show(Request $request): JsonResponse
    {
        $user = $this->user();
        $current = $request->attributes->get('api_token');
        $google = CalendarConnection::where('user_id', $user->id)->where('provider', 'google')->first();
        $feed = CalendarFeed::options($user);
        $token = $user->calendar_token;
        $labels = SecurityLog::events();

        $sessions = [];
        if (config('session.driver') === 'database') {
            $sessions = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->orderByDesc('last_activity')->limit(20)->get()
                ->map(fn (object $session) => [
                    'id' => $session->id,
                    'device' => SecurityLog::device((string) $session->user_agent),
                    'ip' => (string) $session->ip_address,
                    'active_at' => Carbon::createFromTimestamp($session->last_activity)->toIso8601String(),
                ])->all();
        }

        return $this->ok([
            'profile' => ['name' => $user->name, 'email' => $user->email],
            'reminder_emails' => (bool) $user->reminder_emails,
            'digest' => ['channel' => (string) ($user->weekly_digest ?: 'off'), 'day' => (int) ($user->weekly_digest_day ?? 7), 'hour' => (int) ($user->weekly_digest_hour ?? 18)],
            'briefing' => ['channel' => (string) ($user->morning_briefing ?: 'off'), 'hour' => (int) ($user->morning_briefing_hour ?? 7), 'place' => (string) $user->weather_place],
            'calendar_feed' => [
                'url' => $token ? route('calendar.feed', ['token' => $token]) : null,
                'sections' => $feed['sections'],
                'details' => (bool) $feed['details'],
                'all_sections' => CalendarFeed::SECTIONS,
            ],
            'google_calendar' => [
                'configured' => GoogleCalendar::configured(),
                'connected' => $google !== null,
                'email' => $google?->google_email,
                'synced_at' => $google?->last_synced_at?->toIso8601String(),
            ],
            'paperless' => PaperlessInstance::query()->availableTo($user)->with('user')->orderBy('name')->get()->map(fn (PaperlessInstance $instance) => [
                'id' => $instance->id,
                'name' => $instance->name,
                'url' => $instance->url,
                'shared' => (bool) $instance->shared,
                'own' => $instance->user_id === $user->id,
                'owner' => $instance->user?->name,
            ])->all(),
            'devices' => ApiToken::where('user_id', $user->id)->latest()->get()->map(fn (ApiToken $device) => [
                'id' => $device->id,
                'name' => $device->name,
                'created_at' => $device->created_at ? Carbon::parse($device->created_at)->toIso8601String() : null,
                'last_used_at' => $device->last_used_at ? Carbon::parse($device->last_used_at)->toIso8601String() : null,
                'current' => $current instanceof ApiToken && $current->id === $device->id,
            ])->all(),
            'sessions' => $sessions,
            'two_factor' => ['enabled' => $user->hasTwoFactor(), 'recovery_codes' => count((array) $user->two_factor_recovery_codes)],
            'security_log' => SecurityEvent::where('user_id', $user->id)->latest('created_at')->latest('id')->limit(30)->get()->map(function (SecurityEvent $event) use ($labels) {
                $meta = $labels[$event->event] ?? ['label' => $event->event, 'icon' => '•', 'level' => 'info'];

                return ['id' => $event->id, 'label' => $meta['label'], 'icon' => $meta['icon'], 'level' => $meta['level'], 'device' => $event->device, 'ip' => $event->ip_address, 'at' => $event->created_at->toIso8601String()];
            })->all(),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->user();
        $request->merge(['email' => Str::lower((string) $request->input('email'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ]);
        $emailChanges = $data['email'] !== Str::lower($user->email);

        // The e-mail address is the sign-in name and receives security warnings.
        if ($emailChanges) {
            $this->requirePassword($request);
        }

        $user->fill($data);

        if ($emailChanges) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $this->ok(['name' => $user->name, 'email' => $user->email]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $this->requirePassword($request, 'current_password');
        $data = $request->validate(['new_password' => ['required', 'string', Password::defaults(), 'confirmed']]);

        $this->user()->update(['password' => Hash::make($data['new_password'])]);
        SecurityLog::record($this->user(), 'password_changed');

        return $this->ok();
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reminder_emails' => ['sometimes', 'boolean'],
            'digest.channel' => ['sometimes', 'in:'.implode(',', WeeklyDigest::CHANNELS)],
            'digest.day' => ['sometimes', 'integer', 'between:1,7'],
            'digest.hour' => ['sometimes', 'integer', 'between:0,23'],
            'briefing.channel' => ['sometimes', 'in:'.implode(',', MorningBriefing::CHANNELS)],
            'briefing.hour' => ['sometimes', 'integer', 'between:0,23'],
            'briefing.place' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $user = $this->user();
        $attributes = [];

        if (array_key_exists('reminder_emails', $data)) {
            $attributes['reminder_emails'] = (bool) $data['reminder_emails'];
        }

        foreach (['channel' => 'weekly_digest', 'day' => 'weekly_digest_day', 'hour' => 'weekly_digest_hour'] as $key => $column) {
            if (isset($data['digest'][$key])) {
                $attributes[$column] = $key === 'channel' ? $data['digest'][$key] : (int) $data['digest'][$key];
            }
        }

        if (isset($data['briefing']['channel'])) {
            $attributes['morning_briefing'] = $data['briefing']['channel'];
        }

        if (isset($data['briefing']['hour'])) {
            $attributes['morning_briefing_hour'] = (int) $data['briefing']['hour'];
        }

        if (array_key_exists('place', $data['briefing'] ?? [])) {
            $place = trim((string) $data['briefing']['place']);

            if ($place === '') {
                $attributes += ['weather_place' => null, 'weather_latitude' => null, 'weather_longitude' => null];
            } elseif ($place !== $user->weather_place) {
                $location = Weather::geocode($place) ?? abort(422, __('This place could not be found.'));
                $attributes += ['weather_place' => $location['name'], 'weather_latitude' => $location['latitude'], 'weather_longitude' => $location['longitude']];
            }
        }

        if ($attributes !== []) {
            $user->update($attributes);
        }

        return $this->ok();
    }

    /**
     * What the morning briefing or the weekly summary would contain right now.
     */
    public function preview(string $kind): JsonResponse
    {
        $preview = $kind === 'digest' ? WeeklyDigest::for($this->user()) : MorningBriefing::for($this->user());

        return $this->ok([
            'title' => $kind === 'digest' ? ($preview['week'] ?? '') : ($preview['date'] ?? ''),
            'weather' => $preview['weather'] ?? null,
            'sections' => array_map(fn (array $section) => ['icon' => $section['icon'], 'title' => $section['title'], 'lines' => $section['lines']], $preview['sections']),
        ]);
    }

    public function sendNow(string $kind, WebPush $webPush): JsonResponse
    {
        $user = $this->user();

        if ($kind === 'digest') {
            SendWeeklyDigests::deliver($user, $webPush, ($user->weekly_digest ?: 'off') === 'off' ? 'email' : $user->weekly_digest);
        } else {
            SendMorningBriefings::deliver($user, $webPush, ($user->morning_briefing ?: 'off') === 'off' ? 'email' : $user->morning_briefing);
        }

        return $this->ok();
    }

    public function updateCalendarFeed(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['sometimes', 'in:create,disable'],
            'sections' => ['sometimes', 'array'],
            'sections.*' => ['in:'.implode(',', CalendarFeed::SECTIONS)],
            'details' => ['sometimes', 'boolean'],
        ]);
        $user = $this->user();

        if (($data['action'] ?? null) === 'create') {
            $user->forceFill(['calendar_token' => Str::random(40)])->save();
        } elseif (($data['action'] ?? null) === 'disable') {
            $user->forceFill(['calendar_token' => null])->save();
        }

        if (array_key_exists('sections', $data) || array_key_exists('details', $data)) {
            $current = CalendarFeed::options($user);
            $user->update(['calendar_feed_options' => [
                'sections' => array_values(array_unique($data['sections'] ?? $current['sections'])),
                'details' => (bool) ($data['details'] ?? $current['details']),
            ]]);
        }

        return $this->ok(['url' => $user->calendar_token ? route('calendar.feed', ['token' => $user->calendar_token]) : null]);
    }

    public function googleCalendar(string $action): JsonResponse
    {
        $connection = CalendarConnection::where('user_id', $this->user()->id)->where('provider', 'google')->first() ?? abort(404);

        if ($action === 'sync') {
            GoogleCalendar::pull($connection);
        } else {
            $connection->delete();
        }

        return $this->ok();
    }

    /**
     * Adds or changes a paperless connection after a test request; an empty token keeps the saved one.
     */
    public function savePaperless(Request $request, ?int $instance = null): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'url' => ['required', 'string', 'max:255'],
            'token' => ['nullable', 'string', 'max:255'],
            'shared' => ['sometimes', 'boolean'],
        ]);
        $user = $this->user();
        $household = $user->currentHousehold() ?? abort(403);
        $model = $instance !== null ? $this->ownPaperless($instance) : null;
        $url = PaperlessClient::normaliseUrl($data['url']) ?? '';
        abort_if($url === '', 422, __('Enter the web address of your paperless-ngx, e.g. https://paperless.example.com.'));

        $entered = (string) preg_replace('/\s+/', '', (string) preg_replace('/^\s*(token|bearer)\s+/i', '', (string) ($data['token'] ?? '')));
        // A saved token is never sent to a different address.
        abort_if($entered === '' && $model !== null && $url !== $model->url, 422, __('Create a token in paperless under your profile ("API Auth Token").'));
        $token = $entered !== '' ? $entered : (string) $model?->token;
        abort_if($token === '', 422, __('Create a token in paperless under your profile ("API Auth Token").'));

        try {
            $count = (new PaperlessClient($url, $token))->count();
        } catch (Throwable $exception) {
            abort(422, __('Connection failed – check address and token. (:message)', ['message' => Str::limit($exception->getMessage(), 120)]));
        }

        $attributes = [
            'name' => trim((string) ($data['name'] ?? '')) ?: (string) parse_url($url, PHP_URL_HOST),
            'url' => $url,
            'token' => $token,
            'shared' => (bool) ($data['shared'] ?? $model->shared ?? false),
        ];
        $model
            ? $model->update($attributes)
            : PaperlessInstance::create($attributes + ['household_id' => $household->id, 'user_id' => $user->id]);

        return $this->ok(['documents' => $count], $model ? 200 : 201);
    }

    public function destroyPaperless(int $instance): JsonResponse
    {
        $this->ownPaperless($instance)->delete();

        return $this->ok();
    }

    public function revokeDevice(int $device): JsonResponse
    {
        $token = ApiToken::where('user_id', $this->user()->id)->find($device) ?? abort(404);
        $token->delete();

        return $this->ok();
    }

    /**
     * A token for a Siri shortcut; it can add things to the household, shown once.
     */
    public function siriToken(Request $request): JsonResponse
    {
        $this->requirePassword($request);

        return $this->ok(['url' => url('api/v1/quick/auto').'?token='.ApiToken::issue($this->user(), 'Siri / '.__('Shortcut'), ApiToken::SCOPE_QUICK).'&text='], 201);
    }

    public function revokeSession(string $session): JsonResponse
    {
        abort_unless(config('session.driver') === 'database', 404);
        $deleted = DB::table(config('session.table', 'sessions'))->where('user_id', $this->user()->id)->where('id', $session)->delete();
        abort_unless($deleted > 0, 404);
        SecurityLog::record($this->user(), 'session_revoked');

        return $this->ok();
    }

    /**
     * Signs out every browser and every other app device.
     */
    public function logoutOtherDevices(Request $request): JsonResponse
    {
        $this->requirePassword($request);
        $user = $this->user();
        $current = $request->attributes->get('api_token');
        abort_unless($current instanceof ApiToken, 401);

        // A new remember token makes "stay signed in" cookies worthless.
        $user->forceFill(['password' => Hash::make((string) $request->input('password')), 'remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        ApiToken::where('user_id', $user->id)->whereKeyNot($current->id)->delete();
        SecurityLog::record($user, 'logout_other_devices');

        return $this->ok();
    }

    /**
     * First step of setting up two-factor authentication: the secret stays on the server until a
     * code from the authenticator app confirms it.
     */
    public function startTwoFactor(Request $request): JsonResponse
    {
        $this->requirePassword($request);
        abort_if($this->user()->hasTwoFactor(), 409);
        $secret = TwoFactor::generateSecret();
        Cache::put($this->pendingKey(), encrypt($secret), 600);

        return $this->ok(['secret' => $secret, 'otpauth' => TwoFactor::otpauthUrl($this->user(), $secret)]);
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $pending = Cache::get($this->pendingKey());
        abort_unless(is_string($pending), 422, __('The code is not valid.'));
        $secret = (string) decrypt($pending);
        abort_if(TwoFactor::matchingStep($secret, $data['code']) === null, 422, __('The code is not valid.'));

        $codes = TwoFactor::recoveryCodes();
        $this->user()->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => null,
        ])->save();
        Cache::forget($this->pendingKey());
        SecurityLog::record($this->user(), 'two_factor_enabled');

        return $this->ok(['recovery_codes' => $codes]);
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $this->requirePassword($request);
        abort_unless($this->user()->hasTwoFactor(), 409);
        $codes = TwoFactor::recoveryCodes();
        $this->user()->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $this->ok(['recovery_codes' => $codes]);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $this->requirePassword($request);
        $this->user()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        SecurityLog::record($this->user(), 'two_factor_disabled');
        SecurityLog::alert($this->user(), __('Two-factor authentication switched off'), [__('Two-factor authentication was switched off for your account.')]);

        return $this->ok();
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->requirePassword($request);
        $user = $this->user();
        ApiToken::where('user_id', $user->id)->delete();
        $user->delete();

        return $this->ok();
    }

    private function requirePassword(Request $request, string $field = 'password'): void
    {
        $password = (string) $request->input($field);
        abort_if($password === '', 422, __('The password is incorrect.'));
        $key = 'confirm-password:'.$this->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, __('Too many attempts. Please wait a moment.'));

        if (! Hash::check($password, $this->user()->password)) {
            RateLimiter::hit($key, 60);
            SecurityLog::record($this->user(), 'password_confirmation_failed');
            abort(422, __('The password is incorrect.'));
        }

        RateLimiter::clear($key);
    }

    private function ownPaperless(int $id): PaperlessInstance
    {
        return PaperlessInstance::where('user_id', $this->user()->id)->find($id) ?? abort(404);
    }

    private function pendingKey(): string
    {
        return 'two_factor_pending:'.$this->user()->id;
    }
}
