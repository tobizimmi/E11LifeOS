<?php

namespace App\Support\Security;

use App\Models\ApiToken;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers for 15 minutes that the password was confirmed for an app token (kept in the cache, not
 * in the database, so a lost cache simply asks for the password again).
 */
class ApiConfirmation
{
    public const SECONDS = 900;

    public static function confirm(ApiToken $token): void
    {
        Cache::put(self::key($token), true, self::SECONDS);
    }

    public static function isConfirmed(ApiToken $token): bool
    {
        return Cache::get(self::key($token)) === true;
    }

    public static function forget(ApiToken $token): void
    {
        Cache::forget(self::key($token));
    }

    private static function key(ApiToken $token): string
    {
        return 'api-confirmed:'.$token->getKey();
    }
}
