<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Secret link tokens (shared recipes, lists, collections, invoice portal, tenant portal, applicant
 * form, calendar subscription). The database only holds an HMAC with the application key for the
 * lookup and an encrypted copy for showing the link again – a leaked database reveals no valid link.
 */
class ShareToken
{
    public static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public static function encrypt(string $token): string
    {
        return Crypt::encryptString($token);
    }

    public static function decrypt(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }
}
