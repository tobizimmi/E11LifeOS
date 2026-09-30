<?php

namespace App\Support\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\SecurityAlert;
use App\Support\WebPush;
use Illuminate\Http\Request;
use Throwable;

/**
 * The member's security log: sign-ins (with an alert for new devices), failed attempts and lockouts,
 * changes to password and two-factor authentication, exports and access to sensitive areas.
 */
class SecurityLog
{
    /** A device counts as known when it signed in within this many days. */
    public const KNOWN_DEVICE_DAYS = 180;

    /** Sensitive areas are logged at most once per this many minutes. */
    public const SENSITIVE_REPEAT_MINUTES = 30;

    /**
     * @return array<string, array{label: string, icon: string, level: string}>
     */
    public static function events(): array
    {
        return [
            'login' => ['label' => __('Signed in'), 'icon' => '🔑', 'level' => 'info'],
            'login_new_device' => ['label' => __('Signed in on a new device'), 'icon' => '🆕', 'level' => 'warning'],
            'api_login' => ['label' => __('App signed in'), 'icon' => '📱', 'level' => 'info'],
            'login_failed' => ['label' => __('Wrong password'), 'icon' => '⛔', 'level' => 'warning'],
            'two_factor_failed' => ['label' => __('Wrong two-factor code'), 'icon' => '⛔', 'level' => 'warning'],
            'lockout' => ['label' => __('Sign-in blocked after repeated failures'), 'icon' => '🚫', 'level' => 'critical'],
            'logout_other_devices' => ['label' => __('Signed out all other devices'), 'icon' => '🚪', 'level' => 'info'],
            'session_revoked' => ['label' => __('Signed out a device'), 'icon' => '🚪', 'level' => 'info'],
            'idle_logout' => ['label' => __('Signed out after inactivity'), 'icon' => '⏱️', 'level' => 'info'],
            'password_changed' => ['label' => __('Password changed'), 'icon' => '🔒', 'level' => 'warning'],
            'two_factor_enabled' => ['label' => __('Two-factor authentication switched on'), 'icon' => '🛡️', 'level' => 'info'],
            'two_factor_disabled' => ['label' => __('Two-factor authentication switched off'), 'icon' => '⚠️', 'level' => 'critical'],
            'export' => ['label' => __('Data exported'), 'icon' => '📦', 'level' => 'warning'],
            'sensitive_area' => ['label' => __('Sensitive area opened'), 'icon' => '👁️', 'level' => 'info'],
            'trusted_access' => ['label' => __('Trusted person opened the emergency folder'), 'icon' => '🆘', 'level' => 'critical'],
            'virus_found' => ['label' => __('Upload with a virus blocked'), 'icon' => '🦠', 'level' => 'critical'],
        ];
    }

    public static function record(?User $user, string $event, ?Request $request = null, ?string $details = null): ?SecurityEvent
    {
        if (! $user) {
            return null;
        }

        $request ??= request();
        $agent = (string) $request->userAgent();

        try {
            return SecurityEvent::create([
                'user_id' => $user->id,
                'event' => $event,
                'ip_address' => $request->ip(),
                'device' => mb_substr(self::device($agent), 0, 120),
                'device_hash' => $agent !== '' ? hash('sha256', self::device($agent).'|'.$agent) : null,
                'details' => $details,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * A successful sign-in; on a device not seen for half a year the member is alerted by e-mail and push.
     */
    public static function login(User $user, ?Request $request = null, string $event = 'login'): void
    {
        $request ??= request();
        $agent = (string) $request->userAgent();
        $hash = $agent !== '' ? hash('sha256', self::device($agent).'|'.$agent) : null;
        $isKnown = $hash === null || SecurityEvent::where('user_id', $user->id)->whereIn('event', ['login', 'login_new_device', 'api_login'])
            ->where('device_hash', $hash)->where('created_at', '>=', now()->subDays(self::KNOWN_DEVICE_DAYS))->exists();
        $isFirst = ! SecurityEvent::where('user_id', $user->id)->whereIn('event', ['login', 'login_new_device', 'api_login'])->exists();

        if ($isKnown || $isFirst) {
            self::record($user, $event, $request);

            return;
        }

        self::record($user, 'login_new_device', $request);
        self::alert($user, __('New sign-in on your account'), [
            __('Device: :device', ['device' => self::device($agent)]),
            __('IP address: :ip', ['ip' => (string) $request->ip()]),
            __('Time: :time', ['time' => now()->translatedFormat('D, j. M Y H:i')]),
        ]);
    }

    /**
     * Too many failed attempts: logged and the member is alerted once per blocking period.
     */
    public static function lockout(User $user, ?Request $request = null): void
    {
        $recent = SecurityEvent::where('user_id', $user->id)->where('event', 'lockout')->where('created_at', '>=', now()->subMinutes(15))->exists();
        self::record($user, 'lockout', $request);

        if (! $recent) {
            self::alert($user, __('Repeated failed sign-ins'), [
                __('Someone entered a wrong password or code for your account several times. Sign-in is blocked for a few minutes.'),
                __('IP address: :ip', ['ip' => (string) ($request ?? request())->ip()]),
            ]);
        }
    }

    /**
     * Opening health, the emergency folder or online banking – at most once per half hour and area.
     */
    public static function sensitiveArea(User $user, string $area): void
    {
        $recent = SecurityEvent::where('user_id', $user->id)->where('event', 'sensitive_area')
            ->where('created_at', '>=', now()->subMinutes(self::SENSITIVE_REPEAT_MINUTES))->get()
            ->contains(fn (SecurityEvent $event) => $event->details === $area);

        if (! $recent) {
            self::record($user, 'sensitive_area', details: $area);
        }
    }

    /**
     * @param  list<string>  $lines
     */
    public static function alert(User $user, string $subject, array $lines): void
    {
        try {
            app(WebPush::class)->send($user, '🔐 '.$subject, implode(' · ', $lines), route('profile').'#security');
            $user->notify(new SecurityAlert($subject, $lines));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Short, readable device name from the browser signature, e.g. "Safari on iPhone".
     */
    public static function device(string $agent): string
    {
        if ($agent === '') {
            return __('Unknown device');
        }

        $system = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            str_contains($agent, 'okhttp') || str_contains($agent, 'Expo') || str_contains($agent, 'Dart') => __('App'),
            default => null,
        };

        return match (true) {
            $browser && $system => __(':browser on :system', ['browser' => $browser, 'system' => $system]),
            $browser !== null => $browser,
            $system !== null => $system,
            default => mb_substr($agent, 0, 60),
        };
    }

    /**
     * Removes entries older than the retention period; returns how many.
     */
    public static function prune(): int
    {
        return SecurityEvent::where('created_at', '<', now()->subDays(max(30, (int) config('security.log_days'))))->delete();
    }
}
