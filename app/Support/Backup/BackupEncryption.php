<?php

namespace App\Support\Backup;

use RuntimeException;

/**
 * Encrypts backups for storage off the server: libsodium secretstream (XChaCha20-Poly1305) in
 * 1 MB chunks, key derived from a password with Argon2id. Format: "E11BAK1" + salt + stream
 * header + encrypted chunks. Decrypt with `php artisan backup:decrypt`.
 */
class BackupEncryption
{
    private const MAGIC = 'E11BAK1';

    private const CHUNK = 1048576;

    public static function encrypt(string $source, string $target, string $password): void
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = self::key($password, $salt);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $in = fopen($source, 'rb');
        $out = fopen($target, 'wb');

        if (! $in || ! $out) {
            throw new RuntimeException('Cannot open backup files for encryption.');
        }

        fwrite($out, self::MAGIC.$salt.$header);

        do {
            $chunk = (string) fread($in, self::CHUNK);
            $last = feof($in);
            fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : 0));
        } while (! $last);

        fclose($in);
        fclose($out);
        sodium_memzero($key);
    }

    public static function decrypt(string $source, string $target, string $password): void
    {
        $in = fopen($source, 'rb');

        if (! $in || fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new RuntimeException('This is not an encrypted E11LifeOS backup.');
        }

        $salt = (string) fread($in, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $key = self::key($password, $salt);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $out = fopen($target, 'wb');
        $finished = false;

        while (! feof($in)) {
            $chunk = (string) fread($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);

            if ($chunk === '') {
                break;
            }

            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);

            if ($result === false) {
                fclose($out);
                @unlink($target);

                throw new RuntimeException('Wrong password or damaged file.');
            }

            fwrite($out, $result[0]);
            $finished = $result[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }

        fclose($in);
        fclose($out);
        sodium_memzero($key);

        if (! $finished) {
            @unlink($target);

            throw new RuntimeException('The backup file is incomplete.');
        }
    }

    private static function key(string $password, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }
}
