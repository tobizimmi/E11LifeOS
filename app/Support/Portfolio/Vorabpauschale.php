<?php

namespace App\Support\Portfolio;

/**
 * Estimates the German "Vorabpauschale" – the advance lump sum that accumulating (thesaurierende)
 * funds and ETFs are taxed on each year. It is the base return (fund value at the start of the year
 * × the Bundesbank base rate × 0.7) minus the distributions already made, capped at the actual gain
 * in value, and reduced by the partial exemption (Teilfreistellung) for the fund type. This is an
 * estimate for planning; the bank's tax certificate is binding.
 */
class Vorabpauschale
{
    public const CAPITAL_GAINS_TAX = 0.25;

    public const SOLIDARITY_SURCHARGE = 0.055;

    /** 70 % of the base return counts (§ 18 InvStG). */
    public const BASE_FACTOR = 0.7;

    /**
     * Bundesbank base rate per year (the "Basiszins" published each January).
     *
     * @var array<int, float>
     */
    public const BASE_RATES = [
        2023 => 0.0255,
        2024 => 0.0229,
        2025 => 0.0253,
        2026 => 0.0253,
    ];

    /**
     * Partial exemption (Teilfreistellung) by fund type.
     *
     * @var array<string, float>
     */
    public const PARTIAL_EXEMPTIONS = [
        'equity' => 0.30,
        'mixed' => 0.15,
        'real_estate' => 0.60,
        'none' => 0.0,
    ];

    /**
     * @return array{base_rate: float, base_return: float, gross: float, taxable: float, tax: float, partial_exemption: float}
     */
    public static function estimate(float $startValue, float $endValue, float $distributions, int $year, string $fundType = 'equity', float $churchTaxRate = 0.0): array
    {
        $baseRate = self::BASE_RATES[$year] ?? self::BASE_RATES[max(array_keys(self::BASE_RATES))];
        $partialExemption = self::PARTIAL_EXEMPTIONS[$fundType] ?? 0.0;

        $baseReturn = max(0.0, $startValue * $baseRate * self::BASE_FACTOR);
        $gain = max(0.0, $endValue - $startValue);

        // Vorabpauschale: base return minus distributions, never below zero, capped at the real gain.
        $gross = max(0.0, min($baseReturn - max(0.0, $distributions), $gain));
        $taxable = $gross * (1 - $partialExemption);
        $tax = $taxable * self::CAPITAL_GAINS_TAX * (1 + self::SOLIDARITY_SURCHARGE + max(0.0, $churchTaxRate));

        return [
            'base_rate' => $baseRate,
            'base_return' => round($baseReturn, 2),
            'gross' => round($gross, 2),
            'taxable' => round($taxable, 2),
            'tax' => round($tax, 2),
            'partial_exemption' => $partialExemption,
        ];
    }
}
