<?php

namespace App\Support;

use App\Models\DevicePushToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Pushes to the native iOS app through Apple's push service (token-based authentication with the
 * key from the Apple developer account: key id, team id, bundle id and the .p8 key).
 */
class ApnsClient
{
    public static function isConfigured(): bool
    {
        return filled(config('services.apns.key_id')) && filled(config('services.apns.team_id')) && filled(config('services.apns.bundle_id')) && self::privateKey() !== null;
    }

    private static function privateKey(): ?string
    {
        $key = (string) config('services.apns.key');

        if ($key === '') {
            $path = (string) config('services.apns.key_path');
            $key = $path !== '' && is_file($path) ? (string) file_get_contents($path) : '';
        }

        return $key !== '' ? str_replace('\n', "\n", $key) : null;
    }

    /**
     * Sends one notification to a device; removes tokens Apple no longer accepts. Returns whether it arrived.
     */
    public function send(DevicePushToken $device, string $title, string $body, ?string $url = null, bool $urgent = false, ?string $thread = null): bool
    {
        if (! self::isConfigured()) {
            return false;
        }

        $host = $device->environment === 'sandbox' ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
        $payload = ['aps' => array_filter([
            'alert' => ['title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 400)],
            'sound' => 'default',
            'thread-id' => $thread,
            'interruption-level' => $urgent ? 'time-sensitive' : 'active',
        ])];

        if ($url) {
            $payload['url'] = $url;
        }

        try {
            $response = Http::withOptions(['version' => 2.0])->timeout(10)->withHeaders([
                'authorization' => 'bearer '.$this->jwt(),
                'apns-topic' => (string) config('services.apns.bundle_id'),
                'apns-push-type' => 'alert',
                'apns-priority' => $urgent ? '10' : '5',
            ])->withBody((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json')->post($host.'/3/device/'.$device->token);
        } catch (Throwable $exception) {
            $device->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 300)])->save();

            return false;
        }

        if ($response->successful()) {
            $device->forceFill(['last_success_at' => now(), 'last_error' => null])->save();

            return true;
        }

        $reason = (string) ($response->json('reason') ?? $response->status());

        if ($response->status() === 410 || in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
            $device->delete();
        } else {
            $device->forceFill(['last_error' => mb_substr($reason, 0, 300)])->save();
        }

        return false;
    }

    /**
     * The signed token Apple wants (valid for an hour; reused for 50 minutes).
     */
    private function jwt(): string
    {
        return Cache::remember('apns:jwt', now()->addMinutes(50), function (): string {
            $b64 = fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
            $header = $b64((string) json_encode(['alg' => 'ES256', 'kid' => (string) config('services.apns.key_id')]));
            $claims = $b64((string) json_encode(['iss' => (string) config('services.apns.team_id'), 'iat' => time()]));
            openssl_sign($header.'.'.$claims, $der, (string) self::privateKey(), OPENSSL_ALGO_SHA256);

            return $header.'.'.$claims.'.'.$b64(self::derToRaw((string) $der));
        });
    }

    /**
     * ES256 wants the two 32-byte numbers (R and S) one after another; OpenSSL returns them as DER.
     */
    private static function derToRaw(string $der): string
    {
        $offset = 3;
        $length = ord($der[$offset]);
        $r = substr($der, $offset + 1, $length);
        $offset += $length + 2;
        $length = ord($der[$offset]);
        $s = substr($der, $offset + 1, $length);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT).str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }
}
