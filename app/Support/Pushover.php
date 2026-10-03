<?php

namespace App\Support;

use App\Models\PortfolioSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pushover as a second channel next to web push: with the user key saved in the portfolio
 * settings (and an app token there or on the server).
 */
class Pushover
{
    private const URL = 'https://api.pushover.net/1/messages.json';

    /**
     * User key and app token, or null when Pushover is not set up or switched off.
     *
     * @return array{user: string, token: string}|null
     */
    public static function credentials(User $user): ?array
    {
        $settings = PortfolioSetting::where('user_id', $user->id)->first();
        $token = $settings?->pushover_app_token ?: config('services.pushover.token');

        if (! $settings?->pushover_user_key || ! $token || $settings->notifications_enabled === false) {
            return null;
        }

        return ['user' => $settings->pushover_user_key, 'token' => $token];
    }

    public static function isConfigured(User $user): bool
    {
        return self::credentials($user) !== null;
    }

    /**
     * Sends the message; priority 1 makes it ring even in Pushover's own quiet hours.
     */
    public function send(User $user, string $title, string $body, ?string $url = null, bool $important = false): bool
    {
        $credentials = self::credentials($user);

        if ($credentials === null) {
            return false;
        }

        try {
            return Http::asForm()->timeout(10)->post(self::URL, array_filter([
                'token' => $credentials['token'],
                'user' => $credentials['user'],
                'title' => mb_substr($title, 0, 250),
                'message' => mb_substr($body, 0, 1024) ?: $title,
                'url' => $url,
                'priority' => $important ? 1 : 0,
            ], fn ($value) => $value !== null))->successful();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
