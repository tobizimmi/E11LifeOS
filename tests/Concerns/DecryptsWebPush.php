<?php

namespace Tests\Concerns;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\WebPush;

/**
 * Plays the browser for encrypted pushes (RFC 8291): a device key pair and the decryption.
 */
trait DecryptsWebPush
{
    /**
     * A push subscription whose private key the test knows.
     *
     * @return array{0: PushSubscription, 1: array{publicKey: string, privateKey: string, auth: string}}
     */
    protected function deviceWithKeys(User $user, string $endpoint): array
    {
        $keys = [...WebPush::generateKeys(), 'auth' => WebPush::base64Url(random_bytes(16))];
        $subscription = PushSubscription::factory()->for($user)->create(['endpoint' => $endpoint, 'public_key' => $keys['publicKey'], 'auth_token' => $keys['auth']]);

        return [$subscription, $keys];
    }

    /**
     * Decrypts an aes128gcm push body with the device keys, as the browser does.
     *
     * @param  array{publicKey: string, privateKey: string, auth: string}  $keys
     */
    protected function decryptPush(string $body, array $keys): string
    {
        $salt = substr($body, 0, 16);
        $keyIdLength = ord($body[20]);
        $serverPublicKey = substr($body, 21, $keyIdLength);
        $record = substr($body, 21 + $keyIdLength);
        $devicePublicKey = WebPush::base64UrlDecode($keys['publicKey']);

        $der = "\x30\x77\x02\x01\x01\x04\x20".WebPush::base64UrlDecode($keys['privateKey'])."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\xa1\x44\x03\x42\x00".$devicePublicKey;
        $privatePem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
        $publicPem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$serverPublicKey), 64, "\n")."-----END PUBLIC KEY-----\n";
        $sharedSecret = openssl_pkey_derive(openssl_pkey_get_public($publicPem), openssl_pkey_get_private($privatePem), 32);

        $inputKey = hash_hkdf('sha256', $sharedSecret, 32, "WebPush: info\x00".$devicePublicKey.$serverPublicKey, WebPush::base64UrlDecode($keys['auth']));
        $contentKey = hash_hkdf('sha256', $inputKey, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $inputKey, 12, "Content-Encoding: nonce\x00", $salt);
        $plain = openssl_decrypt(substr($record, 0, -16), 'aes-128-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, substr($record, -16));

        return rtrim((string) $plain, "\x00") === '' ? '' : substr(rtrim((string) $plain, "\x00"), 0, -1);
    }
}
