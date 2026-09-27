<?php

namespace App\Support\Portfolio;

use App\Models\PortfolioSetting;
use App\Models\User;
use App\Support\WebPush;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Portfolio notifications (price alerts, stop loss, savings plans, news): web push in LifeOS
 * and, when the user saved a Pushover user key, additionally Pushover.
 */
class Notifier
{
    private const PUSHOVER_URL = 'https://api.pushover.net/1/messages.json';

    public function __construct(private WebPush $webPush) {}

    public function send(User $user, string $title, string $body, ?string $url = null, bool $important = false): void
    {
        $this->webPush->send($user, $title, $body, $url);

        $settings = PortfolioSetting::where('user_id', $user->id)->first();
        $token = $settings?->pushover_app_token ?: config('services.pushover.token');

        if (! $settings?->pushover_user_key || ! $token || $settings->notifications_enabled === false) {
            return;
        }

        try {
            Http::asForm()->timeout(10)->post(self::PUSHOVER_URL, array_filter([
                'token' => $token,
                'user' => $settings->pushover_user_key,
                'title' => mb_substr($title, 0, 250),
                'message' => mb_substr($body, 0, 1024) ?: $title,
                'url' => $url,
                'priority' => $important ? 1 : 0,
            ], fn ($value) => $value !== null));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
