<?php

namespace App\Support\Life;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Euro reference rates of the European Central Bank (free, updated on working days around 16:00).
 */
class ExchangeRates
{
    public const URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /** Currencies offered in forms (the ECB publishes about 30). */
    public const COMMON = ['EUR', 'USD', 'GBP', 'CHF', 'DKK', 'SEK', 'NOK', 'PLN', 'CZK', 'HUF', 'TRY', 'JPY', 'THB', 'AUD', 'CAD', 'NZD', 'ZAR', 'MXN', 'BRL', 'CNY', 'HKD', 'SGD', 'INR', 'IDR', 'KRW', 'ISK', 'RON', 'ILS', 'MYR', 'PHP'];

    /**
     * Units of each currency per euro (e.g. USD => 1.08); empty when the ECB is not reachable.
     *
     * @return array<string, float>
     */
    public static function perEuro(): array
    {
        $cached = Cache::get('fx:ecb');

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $xml = Http::timeout(8)->get(self::URL)->throw()->body();
        } catch (Throwable $exception) {
            report($exception);
            Cache::put('fx:ecb', [], now()->addMinutes(10));

            return [];
        }

        preg_match_all("/currency=['\"]([A-Z]{3})['\"]\s+rate=['\"]([0-9.]+)['\"]/", $xml, $matches, PREG_SET_ORDER);
        $rates = ['EUR' => 1.0];

        foreach ($matches as [, $currency, $rate]) {
            if ((float) $rate > 0) {
                $rates[$currency] = (float) $rate;
            }
        }

        Cache::put('fx:ecb', count($rates) > 1 ? $rates : [], count($rates) > 1 ? now()->addHours(6) : now()->addMinutes(10));

        return count($rates) > 1 ? $rates : [];
    }

    /**
     * Units of the currency per euro, or null if unknown.
     */
    public static function rate(string $currency): ?float
    {
        $currency = strtoupper($currency);

        return $currency === 'EUR' ? 1.0 : (self::perEuro()[$currency] ?? null);
    }

    /**
     * An amount in euros, or null if the rate is unknown.
     */
    public static function toEuro(float $amount, string $currency): ?float
    {
        $rate = self::rate($currency);

        return $rate ? round($amount / $rate, 2) : null;
    }
}
