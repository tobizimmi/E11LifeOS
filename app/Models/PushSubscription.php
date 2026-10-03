<?php

namespace App\Models;

use App\Support\WebPush;
use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser/device that receives web push notifications for a user.
 */
#[Fillable(['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'user_agent', 'last_success_at', 'last_failure_at', 'last_error'])]
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PushSubscription $subscription) {
            $subscription->endpoint_hash = hash('sha256', $subscription->endpoint);
        });
    }

    /**
     * Whether pushes to this device can carry the encrypted text (the browser sent its keys).
     */
    public function canEncrypt(): bool
    {
        return strlen(WebPush::base64UrlDecode((string) $this->public_key)) === 65
            && strlen(WebPush::base64UrlDecode((string) $this->auth_token)) === 16;
    }

    /**
     * The push service behind the endpoint (Apple for iPhone, iPad and Safari on the Mac).
     */
    public function service(): string
    {
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);

        return match (true) {
            str_ends_with($host, 'push.apple.com') => 'Apple',
            str_ends_with($host, 'googleapis.com') => 'Google',
            str_ends_with($host, 'mozilla.com') => 'Mozilla',
            str_ends_with($host, 'notify.windows.com') => 'Microsoft',
            default => $host,
        };
    }

    /**
     * A readable device name from the user agent ("iPhone · Safari").
     */
    public function deviceName(): string
    {
        $agent = (string) $this->user_agent;
        $device = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => __('Device'),
        };
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') || str_contains($agent, 'AppleWebKit') => 'Safari',
            default => null,
        };

        return $browser ? $device.' · '.$browser : $device;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
