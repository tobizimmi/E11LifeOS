<?php

namespace App\Support;

use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Web push without extra packages: pushes carry no payload (so nothing needs to be
 * encrypted), only a VAPID signature. On a push the service worker fetches the
 * user's undelivered PushMessages from the app and shows them.
 */
class WebPush
{
    /**
     * How long a push service keeps a push for an offline device (seconds).
     */
    private const TTL = 86400;

    public static function isConfigured(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    public static function publicKey(): ?string
    {
        return config('services.webpush.public_key');
    }

    /**
     * Queues the message and wakes all of the user's devices; returns how many were reached.
     */
    public function send(User $user, string $title, string $body, ?string $url = null): int
    {
        $subscriptions = $user->pushSubscriptions()->get();

        if (! self::isConfigured() || $subscriptions->isEmpty()) {
            return 0;
        }

        PushMessage::create(['user_id' => $user->id, 'title' => mb_substr($title, 0, 255), 'body' => $body, 'url' => $url]);

        return $subscriptions->filter(fn (PushSubscription $subscription) => $this->wake($subscription))->count();
    }

    /**
     * Messages the service worker should show now (marked as delivered); older ones are cleaned up.
     *
     * @return Collection<int, PushMessage>
     */
    public function takePending(User $user): Collection
    {
        PushMessage::where('user_id', $user->id)->where('created_at', '<', now()->subWeek())->delete();

        $messages = PushMessage::where('user_id', $user->id)->whereNull('delivered_at')
            ->where('created_at', '>=', now()->subDay())->oldest()->limit(5)->get();

        PushMessage::whereIn('id', $messages->pluck('id'))->update(['delivered_at' => now()]);

        return $messages;
    }

    /**
     * Sends an empty push; subscriptions the push service no longer knows are removed.
     */
    private function wake(PushSubscription $subscription): bool
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'TTL' => (string) self::TTL,
                    'Urgency' => 'normal',
                    'Authorization' => $this->vapidAuthorization($subscription->endpoint),
                ])
                ->withBody('', 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();
        }

        return $response->successful();
    }

    /**
     * "vapid t=<JWT signed with ES256>, k=<public key>" for the push service of the endpoint.
     */
    public function vapidAuthorization(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $subject = config('services.webpush.subject') ?: config('app.url');

        $unsigned = self::base64Url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']))
            .'.'.self::base64Url(json_encode(['aud' => $audience, 'exp' => now()->addHours(12)->timestamp, 'sub' => $subject], JSON_UNESCAPED_SLASHES));

        if (! openssl_sign($unsigned, $derSignature, $this->privateKeyPem(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the VAPID token.');
        }

        return 'vapid t='.$unsigned.'.'.self::base64Url(self::derToRawSignature($derSignature)).', k='.self::publicKey();
    }

    /**
     * A new P-256 key pair as URL-safe base64 (public key: uncompressed point, private key: d).
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];

        return [
            'publicKey' => self::base64Url("\x04".self::pad32($ec['x']).self::pad32($ec['y'])),
            'privateKey' => self::base64Url(self::pad32($ec['d'])),
        ];
    }

    public static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    /**
     * SEC1 "EC PRIVATE KEY" for P-256 built from the raw keys in the configuration.
     */
    private function privateKeyPem(): string
    {
        $der = "\x30\x77\x02\x01\x01\x04\x20".self::pad32(self::base64UrlDecode((string) config('services.webpush.private_key')))
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            ."\xa1\x44\x03\x42\x00".self::base64UrlDecode((string) self::publicKey());

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /**
     * OpenSSL returns an ASN.1 SEQUENCE of r and s; JWT wants r || s with 32 bytes each.
     */
    private static function derToRawSignature(string $der): string
    {
        $offset = 2;
        $values = [];

        for ($index = 0; $index < 2; $index++) {
            $length = ord($der[$offset + 1]);
            $values[] = self::pad32(ltrim(substr($der, $offset + 2, $length), "\x00"));
            $offset += 2 + $length;
        }

        return $values[0].$values[1];
    }

    private static function pad32(string $bytes): string
    {
        return str_pad($bytes, 32, "\x00", STR_PAD_LEFT);
    }
}
