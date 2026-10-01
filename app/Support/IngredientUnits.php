<?php

namespace App\Support;

/**
 * Units and names of ingredients for adding up: "500 g" + "0,5 kg" = "1 kg", "1 EL" + "1 TL" = "4 TL",
 * "Zwiebel" and "Zwiebeln (rot)" are the same item. Units without a conversion (Bund, Dose …) are only
 * added to the same unit.
 */
class IngredientUnits
{
    /**
     * Unit families with the factor to their smallest unit.
     *
     * @var array<string, array{family: string, factor: int|float}>
     */
    private const UNITS = [
        'mg' => ['family' => 'mass', 'factor' => 0.001],
        'g' => ['family' => 'mass', 'factor' => 1],
        'kg' => ['family' => 'mass', 'factor' => 1000],
        'ml' => ['family' => 'volume', 'factor' => 1],
        'cl' => ['family' => 'volume', 'factor' => 10],
        'dl' => ['family' => 'volume', 'factor' => 100],
        'l' => ['family' => 'volume', 'factor' => 1000],
        'TL' => ['family' => 'spoon', 'factor' => 1],
        'EL' => ['family' => 'spoon', 'factor' => 3],
        '' => ['family' => 'piece', 'factor' => 1],
    ];

    /** Spellings of the same unit. */
    private const ALIASES = [
        'gr' => 'g', 'gramm' => 'g', 'gram' => 'g', 'grams' => 'g', 'g.' => 'g',
        'kilo' => 'kg', 'kilogramm' => 'kg', 'kilogram' => 'kg',
        'milligramm' => 'mg',
        'liter' => 'l', 'litre' => 'l', 'ltr' => 'l', 'ltr.' => 'l',
        'milliliter' => 'ml', 'millilitre' => 'ml',
        'el' => 'EL', 'essl.' => 'EL', 'esslöffel' => 'EL', 'eßlöffel' => 'EL', 'tbsp' => 'EL', 'tablespoon' => 'EL',
        'tl' => 'TL', 'teel.' => 'TL', 'teelöffel' => 'TL', 'tsp' => 'TL', 'teaspoon' => 'TL',
        'stk' => '', 'stk.' => '', 'stück' => '', 'st.' => '', 'st' => '', 'pc' => '', 'pcs' => '', 'piece' => '', 'pieces' => '',
    ];

    /**
     * The usual spelling of a unit ("Esslöffel" → "EL", "Stück" → ""), others unchanged.
     */
    public static function normalize(?string $unit): string
    {
        $unit = trim((string) $unit);
        $lower = mb_strtolower($unit);

        if (array_key_exists($lower, self::ALIASES)) {
            return self::ALIASES[$lower];
        }

        foreach (array_keys(self::UNITS) as $known) {
            if ($known !== '' && mb_strtolower($known) === $lower) {
                return $known;
            }
        }

        return $unit;
    }

    /**
     * Key for grouping units that can be added ("mass", "volume", "spoon", "piece" or the unit itself).
     */
    public static function family(?string $unit): string
    {
        $unit = self::normalize($unit);

        return self::UNITS[$unit]['family'] ?? 'unit:'.mb_strtolower($unit);
    }

    /**
     * The amount in the smallest unit of its family (500 g → 500, 0,5 kg → 500); null without a number.
     */
    public static function toBase(?float $amount, ?string $unit): ?float
    {
        if ($amount === null) {
            return null;
        }

        return $amount * (self::UNITS[self::normalize($unit)]['factor'] ?? 1);
    }

    /**
     * A base amount written in a readable unit: 1500 g → "1,5 kg", 250 ml → "250 ml", 4 TL → "1 EL + 1 TL" stays "4 TL".
     */
    public static function format(float $base, string $family, string $fallbackUnit = ''): string
    {
        [$value, $unit] = match ($family) {
            'mass' => $base >= 1000 ? [$base / 1000, 'kg'] : ($base < 1 && $base > 0 ? [$base * 1000, 'mg'] : [$base, 'g']),
            'volume' => $base >= 1000 ? [$base / 1000, 'l'] : [$base, 'ml'],
            'spoon' => fmod($base, 3.0) === 0.0 && $base >= 3 ? [$base / 3, 'EL'] : [$base, 'TL'],
            'piece' => [$base, ''],
            default => [$base, $fallbackUnit],
        };

        return trim(IngredientAmount::format($value).' '.$unit);
    }

    /**
     * Splits "1,5 kg" into [1.5, "kg"], "etwas" into [null, ""] and "2 Bund" into [2, "Bund"].
     *
     * @return array{0: ?float, 1: string}
     */
    public static function split(?string $quantity): array
    {
        $quantity = trim((string) $quantity);

        if (preg_match('/^(\d+(?:[.,]\d+)?(?:\s+\d+\/\d+)?|\d+\/\d+|[½⅓⅔¼¾⅛])\s*(.*)$/u', $quantity, $match)) {
            $amount = IngredientAmount::parse(strtr($match[1], ['½' => '1/2', '⅓' => '1/3', '⅔' => '2/3', '¼' => '1/4', '¾' => '3/4', '⅛' => '1/8']));

            return [$amount, self::normalize($match[2])];
        }

        return [null, $quantity];
    }

    /**
     * Adds two quantities ("500 g" + "0,5 kg" = "1 kg"); null when they cannot be added.
     */
    public static function add(?string $first, ?string $second): ?string
    {
        [$firstAmount, $firstUnit] = self::split($first);
        [$secondAmount, $secondUnit] = self::split($second);

        if ($firstAmount === null || $secondAmount === null || self::family($firstUnit) !== self::family($secondUnit)) {
            return null;
        }

        return self::format((float) self::toBase($firstAmount, $firstUnit) + (float) self::toBase($secondAmount, $secondUnit), self::family($firstUnit), $firstUnit);
    }

    /**
     * Comparable form of an ingredient name: lower case, without notes in brackets or after a comma,
     * simple German plurals reduced ("Zwiebeln" → "zwiebel", "Tomaten" → "tomate").
     */
    public static function nameKey(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = trim((string) preg_replace('/\s*\(.*?\)\s*/u', ' ', $name));
        $name = trim(explode(',', $name)[0]);
        $name = (string) preg_replace('/\s+/u', ' ', $name);

        return (string) preg_replace_callback('/\b(\p{L}{4,}?)(e|l|r)n\b/u', fn (array $match) => $match[1].$match[2], $name);
    }
}
