<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Two-factor authentication with time-based one-time passwords (RFC 6238, 30 s, 6 digits,
 * SHA-1 – what authenticator apps expect) and single-use recovery codes.
 */
class TwoFactor
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn (string $chunk) => self::ALPHABET[bindec(str_pad($chunk, 5, '0'))], str_split($bits, 5)));
    }

    public static function otpauthUrl(User $user, string $secret): string
    {
        $issuer = (string) config('app.name');

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$user->email).'?'.http_build_query(['secret' => $secret, 'issuer' => $issuer, 'digits' => 6, 'period' => 30]);
    }

    public static function code(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24 | ord($hash[$offset + 1]) << 16 | ord($hash[$offset + 2]) << 8 | ord($hash[$offset + 3])) % 1000000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    /**
     * The time step a code belongs to (±1 step for clock drift), or null.
     */
    public static function matchingStep(string $secret, string $code, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{6}$/', (string) $code)) {
            return null;
        }

        $current = intdiv($time ?? time(), 30);

        foreach ([$current, $current - 1, $current + 1] as $step) {
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Checks a code of the user's app (each code works only once) or a recovery code (which is
     * then used up).
     */
    public static function verify(User $user, string $input): bool
    {
        if (! $user->hasTwoFactor()) {
            return false;
        }

        $step = self::matchingStep((string) $user->two_factor_secret, $input);

        if ($step !== null && ($user->two_factor_last_step === null || $step > $user->two_factor_last_step)) {
            $user->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }

        $normalised = strtolower(trim($input));
        $codes = (array) $user->two_factor_recovery_codes;

        foreach ($codes as $index => $recoveryCode) {
            if (hash_equals((string) $recoveryCode, $normalised)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function recoveryCodes(): array
    {
        return array_map(fn () => strtolower(Str::random(5).'-'.Str::random(5)), range(1, 8));
    }

    private static function base32Decode(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper(rtrim($secret, '='))) as $character) {
            $position = strpos(self::ALPHABET, $character);

            if ($position !== false) {
                $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
            }
        }

        return implode('', array_map(fn (string $byte) => chr(bindec($byte)), array_filter(str_split($bits, 8), fn (string $byte) => strlen($byte) === 8)));
    }
}
