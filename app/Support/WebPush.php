<?php

namespace App\Support;

use App\Enums\NotificationCategory;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Web push without extra packages: VAPID signature (RFC 8292) and the text encrypted for the
 * device (RFC 8291, aes128gcm) with PHP's OpenSSL. The service worker shows the text from the
 * push right away, even without a signed-in session. Devices without keys (and pushes too big
 * for the payload) get an empty push; the service worker then fetches the user's undelivered
 * PushMessages from the app.
 */
class WebPush
{
    /**
     * How long a push service keeps a push for an offline device (seconds).
     */
    private const TTL = 86400;

    /**
     * Largest JSON text sent in a push (push services accept 4096 bytes including encryption).
     */
    private const MAX_PAYLOAD = 3000;

    public function __construct(private ?Pushover $pushover = null) {}

    public static function isConfigured(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    public static function publicKey(): ?string
    {
        return config('services.webpush.public_key');
    }

    /**
     * Adds the message to the bell, queues it and wakes all of the user's devices; returns how many
     * were reached. During the user's quiet hours the push waits and comes bundled afterwards –
     * except urgent ones (medication, reminders at a chosen time), which also go out with high
     * priority so the phone shows them at once. With "all notifications via Pushover" in the
     * profile they also go to Pushover ($pushover = false when the caller sends there itself).
     */
    /**
     * @param  list<array{label: string, url: string}>  $actions  buttons in the notification (answered by a POST to the url)
     * @param  ?string  $category  the lane (see {@see NotificationCategory}); defaults to "important" for
     *                             urgent pushes and "general" otherwise, so mails keep their own lane
     */
    public function send(User $user, string $title, string $body, ?string $url = null, bool $urgent = false, bool $pushover = true, array $actions = [], ?string $category = null): int
    {
        $lane = NotificationCategory::fromValue($category ?? ($urgent ? NotificationCategory::Important->value : NotificationCategory::General->value));

        InAppNotifications::record($user, $title, $body, $url, $lane);

        $quiet = ! $urgent && self::inQuietHours($user);

        if ($pushover && $user->pushover_all && ! $quiet) {
            ($this->pushover ?? app(Pushover::class))->send($user, $title, $body, $url, $urgent);
        }

        $subscriptions = $user->pushSubscriptions()->get();

        if (! self::isConfigured() || $subscriptions->isEmpty()) {
            return 0;
        }

        $message = PushMessage::create(['user_id' => $user->id, 'category' => $lane->value, 'title' => mb_substr($title, 0, 255), 'body' => $body, 'url' => $url, 'actions' => $actions ?: null]);

        if ($quiet) {
            // Queued for the devices; "push:release-quiet" wakes them when the quiet hours end.
            return $subscriptions->count();
        }

        return $this->deliver($subscriptions, collect([$message]), $urgent ? 'high' : 'normal', self::present($user, collect([$message]), bundle: false));
    }

    /**
     * Pushes the shown messages to the devices (encrypted where possible); marks the messages as
     * delivered when every device got the text with the push.
     *
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  Collection<int, PushMessage>  $messages
     * @param  list<array{title: string, body: string, url: string, tag: string, actions?: list<array{label: string, url: string}>}>  $shown
     */
    private function deliver(Collection $subscriptions, Collection $messages, string $urgency, array $shown): int
    {
        $payload = self::payload($shown);
        $reached = 0;
        $allWithText = true;

        foreach ($subscriptions as $subscription) {
            $withText = $payload !== null && $subscription->canEncrypt();
            $allWithText = $allWithText && $withText;

            if ($this->wake($subscription, $withText ? $payload : null, $urgency)) {
                $reached++;
            } elseif ($subscription->exists) {
                // Not reached (a removed, expired device does not count): it fetches the texts later.
                $allWithText = false;
            }
        }

        if ($allWithText && $reached > 0) {
            PushMessage::whereIn('id', $messages->pluck('id'))->update(['delivered_at' => now()]);
        }

        return $reached;
    }

    /**
     * The JSON the service worker reads from the push, or null when it is too big (the service
     * worker then fetches the texts).
     *
     * @param  list<array{title: string, body: string, url: string, tag: string, actions?: list<array{label: string, url: string}>}>  $shown
     */
    public static function payload(array $shown): ?string
    {
        $shown = array_map(fn (array $message) => [...$message, 'body' => mb_strimwidth($message['body'], 0, 1000, '…')], $shown);
        $json = json_encode(['messages' => $shown], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false && strlen($json) <= self::MAX_PAYLOAD ? $json : null;
    }

    /**
     * Whether the user's quiet hours (e.g. 22 to 7 o'clock) include this moment.
     */
    public static function inQuietHours(User $user, ?Carbon $at = null): bool
    {
        $from = $user->push_quiet_from;
        $until = $user->push_quiet_until;

        if ($from === null || $until === null || $from === $until) {
            return false;
        }

        $hour = ($at ?? now())->hour;

        return $from < $until ? $hour >= $from && $hour < $until : $hour >= $from || $hour < $until;
    }

    /**
     * Wakes the devices of users whose quiet hours are over and who have pushes waiting (once a day).
     */
    public function releaseQuiet(): int
    {
        $released = 0;

        User::whereNotNull('push_quiet_from')->whereNotNull('push_quiet_until')
            ->whereHas('pushSubscriptions')
            ->get()
            ->each(function (User $user) use (&$released) {
                $key = 'push:released:'.$user->id.':'.today()->toDateString();

                if (self::inQuietHours($user) || Cache::has($key)
                    || ! PushMessage::where('user_id', $user->id)->whereNull('delivered_at')->where('created_at', '>=', now()->subDay())->exists()) {
                    return;
                }

                Cache::put($key, true, now()->endOfDay());
                $messages = PushMessage::where('user_id', $user->id)->whereNull('delivered_at')
                    ->where('created_at', '>=', now()->subDay())->oldest()->limit(20)->get();
                $this->deliver(PushSubscription::where('user_id', $user->id)->get(), $messages, 'normal', self::present($user, $messages));
                $released++;
            });

        return $released;
    }

    /**
     * What the service worker shows. Messages are grouped by their lane ({@see NotificationCategory}),
     * so new mails never get folded into – or bundled together with – the other notifications: each
     * lane with three or more waiting messages becomes its own summary (unless the user wants every
     * message on its own), and each summary carries the lane's own tag so it shows as a separate
     * notification on the device.
     *
     * @param  Collection<int, PushMessage>  $messages
     * @return list<array{title: string, body: string, url: string, tag: string, actions?: list<array{label: string, url: string}>}>
     */
    public static function present(User $user, Collection $messages, bool $bundle = true): array
    {
        $bundling = $bundle && $user->push_bundle !== false;

        return $messages
            ->groupBy(fn (PushMessage $message) => NotificationCategory::fromValue($message->category)->value)
            ->flatMap(function (Collection $group, string $value) use ($bundling): array {
                $category = NotificationCategory::from($value);

                if ($bundling && $group->count() >= 3) {
                    return [[
                        'title' => $category->bundleTitle($group->count()),
                        'body' => $group->map(fn (PushMessage $message) => '• '.$message->title)->implode("\n"),
                        'url' => $category === NotificationCategory::Mail ? route('mail.index') : route('notifications.index'),
                        'tag' => $category->bundleTag(),
                    ]];
                }

                return $group->map(fn (PushMessage $message) => self::card($message))->all();
            })
            ->values()
            ->all();
    }

    /**
     * One notification for a single message.
     *
     * @return array{title: string, body: string, url: string, tag: string, actions?: list<array{label: string, url: string}>}
     */
    private static function card(PushMessage $message): array
    {
        $shown = [
            'title' => $message->title,
            'body' => (string) $message->body,
            'url' => $message->url ?: route('dashboard'),
            'tag' => 'e11-'.$message->id,
        ];

        if ($message->actions) {
            $shown['actions'] = $message->actions;
        }

        return $shown;
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
            ->where('created_at', '>=', now()->subDay())->oldest()->limit(20)->get();

        PushMessage::whereIn('id', $messages->pluck('id'))->update(['delivered_at' => now()]);

        return $messages;
    }

    /**
     * Sends the push (with the encrypted text, or empty) and notes the outcome on the device for the
     * diagnosis in the profile; subscriptions the push service no longer knows are removed.
     */
    private function wake(PushSubscription $subscription, ?string $payload = null, string $urgency = 'normal'): bool
    {
        $body = '';
        $headers = [
            'TTL' => (string) self::TTL,
            'Urgency' => $urgency,
            'Authorization' => $this->vapidAuthorization($subscription->endpoint),
        ];

        try {
            if ($payload !== null) {
                $body = self::encrypt($payload, self::base64UrlDecode((string) $subscription->public_key), self::base64UrlDecode((string) $subscription->auth_token));
                $headers['Content-Encoding'] = 'aes128gcm';
            }

            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->withBody($body, 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (Throwable $exception) {
            report($exception);
            $subscription->forceFill(['last_failure_at' => now(), 'last_error' => mb_substr($exception->getMessage(), 0, 255)])->save();

            return false;
        }

        if (in_array($response->status(), [404, 410], true)) {
            // Expired or unsubscribed on the device: the browser registers a new one when opened.
            $subscription->delete();

            return false;
        }

        if ($response->successful()) {
            $subscription->forceFill(['last_success_at' => now(), 'last_error' => null])->save();

            return true;
        }

        $reason = $response->json('reason') ?? mb_substr(trim(strip_tags($response->body())), 0, 120);
        $subscription->forceFill(['last_failure_at' => now(), 'last_error' => mb_substr('HTTP '.$response->status().($reason ? ': '.$reason : ''), 0, 255)])->save();

        return false;
    }

    /**
     * Encrypts the text for one device (RFC 8291 with the aes128gcm content coding of RFC 8188):
     * ECDH with a fresh key pair against the device key, HKDF with the device's auth secret,
     * AES-128-GCM in a single record. $keyPair and $salt only for the test vectors.
     *
     * @param  string  $devicePublicKey  uncompressed P-256 point (65 bytes)
     * @param  string  $authSecret  16 bytes
     * @param  array{publicKey: string, privateKey: string}|null  $keyPair  URL-safe base64 as from generateKeys()
     */
    public static function encrypt(string $payload, string $devicePublicKey, string $authSecret, ?array $keyPair = null, ?string $salt = null): string
    {
        if (strlen($devicePublicKey) !== 65 || strlen($authSecret) !== 16) {
            throw new RuntimeException('Invalid push subscription keys.');
        }

        $keyPair ??= self::generateKeys();
        $salt ??= random_bytes(16);
        $serverPublicKey = self::base64UrlDecode($keyPair['publicKey']);
        $serverPrivateKey = openssl_pkey_get_private(self::ecPrivateKeyPem(self::base64UrlDecode($keyPair['privateKey']), $serverPublicKey));
        $devicePem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$devicePublicKey), 64, "\n")."-----END PUBLIC KEY-----\n";
        $sharedSecret = $serverPrivateKey ? openssl_pkey_derive(openssl_pkey_get_public($devicePem), $serverPrivateKey, 32) : false;

        if ($sharedSecret === false) {
            throw new RuntimeException('Could not derive the push encryption key.');
        }

        $inputKey = hash_hkdf('sha256', $sharedSecret, 32, "WebPush: info\x00".$devicePublicKey.$serverPublicKey, $authSecret);
        $contentKey = hash_hkdf('sha256', $inputKey, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $inputKey, 12, "Content-Encoding: nonce\x00", $salt);

        // A single record: the text, then the delimiter 0x02 of the last record.
        $cipherText = openssl_encrypt($payload."\x02", 'aes-128-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($cipherText === false) {
            throw new RuntimeException('Could not encrypt the push.');
        }

        return $salt.pack('N', 4096).chr(65).$serverPublicKey.$cipherText.$tag;
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
        return self::ecPrivateKeyPem(self::base64UrlDecode((string) config('services.webpush.private_key')), self::base64UrlDecode((string) self::publicKey()));
    }

    /**
     * SEC1 "EC PRIVATE KEY" for P-256 from the raw private value d and the uncompressed public point.
     */
    private static function ecPrivateKeyPem(string $privateKey, string $publicKey): string
    {
        $der = "\x30\x77\x02\x01\x01\x04\x20".self::pad32($privateKey)
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            ."\xa1\x44\x03\x42\x00".$publicKey;

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
