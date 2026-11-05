<?php

namespace App\Support\Collections;

use App\Models\CollectionProperty;
use App\Models\Item;

/**
 * Evaluates a collection formula column's expression safely (no eval) for one entry.
 *
 * The expression is plain arithmetic — "+", "-", "*", "/", parentheses and unary minus — over
 * numeric column references written as {Column Name}. References resolve against the entry's
 * number, rating and progress columns (by name, case-insensitive); an empty or unknown reference
 * counts as 0. A malformed expression or a division by zero yields null (shown as empty), so a
 * bad formula never throws in a view or report.
 *
 * Example: "{Preis} * {Menge}" or "({Einnahmen} - {Ausgaben}) / 12".
 */
class FormulaEvaluator
{
    /**
     * Column types a formula may reference as a number.
     */
    private const NUMERIC_TYPES = [
        CollectionProperty::TYPE_NUMBER,
        CollectionProperty::TYPE_RATING,
        CollectionProperty::TYPE_PROGRESS,
    ];

    /**
     * @var list<array{type: string, value: string}>
     */
    private array $tokens = [];

    private int $pos = 0;

    /**
     * @param  array<string, float>  $values  Numeric values by lower-cased, trimmed column name.
     */
    private function __construct(private array $values) {}

    /**
     * Computes the formula of a property for one entry, or null when it cannot be evaluated.
     *
     * @param  iterable<int, CollectionProperty>  $properties  The entry's collection's columns.
     */
    public static function forItem(CollectionProperty $formula, Item $item, iterable $properties): ?float
    {
        $expression = data_get($formula->options, 'formula');

        if (! is_string($expression) || trim($expression) === '') {
            return null;
        }

        return self::evaluate($expression, self::valueMap($properties, $item));
    }

    /**
     * The numeric value of each referenceable column of the entry, keyed by lower-cased name.
     *
     * @param  iterable<int, CollectionProperty>  $properties
     * @return array<string, float>
     */
    public static function valueMap(iterable $properties, Item $item): array
    {
        $map = [];

        foreach ($properties as $property) {
            if (! in_array($property->type, self::NUMERIC_TYPES, true)) {
                continue;
            }

            $value = $item->propertyValue($property);
            $map[mb_strtolower(trim($property->name))] = is_numeric($value) ? (float) $value : 0.0;
        }

        return $map;
    }

    /**
     * Evaluates an arithmetic expression with {name} references, or null when it is malformed.
     *
     * @param  array<string, float>  $values
     */
    public static function evaluate(string $expression, array $values): ?float
    {
        try {
            $evaluator = new self($values);
            $evaluator->tokens = $evaluator->tokenize($expression);
            $result = $evaluator->parseExpression();

            // Trailing tokens mean the expression did not parse cleanly.
            if ($evaluator->pos !== count($evaluator->tokens)) {
                return null;
            }

            return is_finite($result) ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{type: string, value: string}>
     */
    private function tokenize(string $expression): array
    {
        $tokens = [];
        $length = mb_strlen($expression);
        $i = 0;

        while ($i < $length) {
            $char = mb_substr($expression, $i, 1);

            if (trim($char) === '') {
                $i++;

                continue;
            }

            if ($char === '{') {
                $end = mb_strpos($expression, '}', $i);

                if ($end === false) {
                    throw new \RuntimeException('Unterminated reference');
                }

                $name = mb_substr($expression, $i + 1, $end - $i - 1);
                $tokens[] = ['type' => 'ref', 'value' => mb_strtolower(trim($name))];
                $i = $end + 1;

                continue;
            }

            if (in_array($char, ['+', '-', '*', '/', '(', ')'], true)) {
                $tokens[] = ['type' => $char, 'value' => $char];
                $i++;

                continue;
            }

            if (ctype_digit($char) || $char === '.') {
                $number = '';

                while ($i < $length) {
                    $c = mb_substr($expression, $i, 1);

                    if (! ctype_digit($c) && $c !== '.') {
                        break;
                    }

                    $number .= $c;
                    $i++;
                }

                if (! is_numeric($number)) {
                    throw new \RuntimeException('Invalid number');
                }

                $tokens[] = ['type' => 'num', 'value' => $number];

                continue;
            }

            throw new \RuntimeException('Unexpected character');
        }

        return $tokens;
    }

    private function parseExpression(): float
    {
        $value = $this->parseTerm();

        while (($token = $this->peek()) !== null && in_array($token['type'], ['+', '-'], true)) {
            $this->pos++;
            $right = $this->parseTerm();
            $value = $token['type'] === '+' ? $value + $right : $value - $right;
        }

        return $value;
    }

    private function parseTerm(): float
    {
        $value = $this->parseFactor();

        while (($token = $this->peek()) !== null && in_array($token['type'], ['*', '/'], true)) {
            $this->pos++;
            $right = $this->parseFactor();

            if ($token['type'] === '/') {
                if ($right === 0.0) {
                    throw new \RuntimeException('Division by zero');
                }

                $value /= $right;
            } else {
                $value *= $right;
            }
        }

        return $value;
    }

    private function parseFactor(): float
    {
        $token = $this->peek();

        if ($token === null) {
            throw new \RuntimeException('Unexpected end');
        }

        if ($token['type'] === '-') {
            $this->pos++;

            return -$this->parseFactor();
        }

        if ($token['type'] === '+') {
            $this->pos++;

            return $this->parseFactor();
        }

        if ($token['type'] === '(') {
            $this->pos++;
            $value = $this->parseExpression();

            if (($close = $this->peek()) === null || $close['type'] !== ')') {
                throw new \RuntimeException('Missing closing parenthesis');
            }

            $this->pos++;

            return $value;
        }

        if ($token['type'] === 'num') {
            $this->pos++;

            return (float) $token['value'];
        }

        if ($token['type'] === 'ref') {
            $this->pos++;

            return $this->values[$token['value']] ?? 0.0;
        }

        throw new \RuntimeException('Unexpected token');
    }

    /**
     * @return array{type: string, value: string}|null
     */
    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }
}
