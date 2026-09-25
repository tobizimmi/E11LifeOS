<?php

namespace App\Support;

/**
 * Scales ingredient amounts for a different number of servings.
 * Understands "200", "1,5", "1.5", "1/2", "1 1/2", "½" and ranges "2-3";
 * anything else (e.g. "etwas", "n. B.") is returned unchanged.
 */
class IngredientAmount
{
    private const UNICODE_FRACTIONS = ['½' => '1/2', '⅓' => '1/3', '⅔' => '2/3', '¼' => '1/4', '¾' => '3/4', '⅛' => '1/8'];

    public static function scale(?string $amount, float $factor): string
    {
        $amount = trim((string) $amount);

        if ($amount === '' || abs($factor - 1.0) < 0.0001) {
            return $amount;
        }

        $normalized = trim(strtr($amount, array_map(fn (string $fraction) => ' '.$fraction, self::UNICODE_FRACTIONS)));

        if (preg_match('/^(.+?)\s*[-–]\s*(.+)$/u', $normalized, $range)) {
            $from = self::parse($range[1]);
            $to = self::parse($range[2]);

            return $from !== null && $to !== null
                ? self::format($from * $factor).'–'.self::format($to * $factor)
                : $amount;
        }

        $value = self::parse($normalized);

        return $value === null ? $amount : self::format($value * $factor);
    }

    public static function parse(string $value): ?float
    {
        $value = trim($value);

        if (preg_match('/^(\d+)\s+(\d+)\s*\/\s*(\d+)$/', $value, $m) && (int) $m[3] > 0) {
            return (int) $m[1] + (int) $m[2] / (int) $m[3];
        }

        if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $value, $m) && (int) $m[2] > 0) {
            return (int) $m[1] / (int) $m[2];
        }

        if (preg_match('/^\d+(?:[.,]\d+)?$/', $value)) {
            return (float) str_replace(',', '.', $value);
        }

        return null;
    }

    /**
     * German notation, at most two decimals: 1.5 → "1,5", 0.333 → "0,33", 2.0 → "2".
     */
    public static function format(float $value): string
    {
        $rounded = round($value, $value >= 10 ? 0 : 2);

        return rtrim(rtrim(number_format($rounded, 2, ',', ''), '0'), ',');
    }
}
