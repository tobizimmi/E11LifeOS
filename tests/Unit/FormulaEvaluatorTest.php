<?php

namespace Tests\Unit;

use App\Support\Collections\FormulaEvaluator;
use PHPUnit\Framework\TestCase;

class FormulaEvaluatorTest extends TestCase
{
    public function test_basic_arithmetic_and_references(): void
    {
        $v = ['a' => 2.0, 'b' => 3.0];

        $this->assertSame(5.0, FormulaEvaluator::evaluate('{a} + {b}', $v));
        $this->assertSame(-1.0, FormulaEvaluator::evaluate('{a} - {b}', $v));
        $this->assertSame(6.0, FormulaEvaluator::evaluate('{a} * {b}', $v));
        $this->assertSame(2.5, FormulaEvaluator::evaluate('{a} * {b} - 1 / {a}', ['a' => 2.0, 'b' => 1.5]));
    }

    public function test_operator_precedence_and_parentheses(): void
    {
        $v = ['a' => 1.0, 'b' => 3.0];

        $this->assertSame(7.0, FormulaEvaluator::evaluate('{a} + {b} * 2', $v));
        $this->assertSame(8.0, FormulaEvaluator::evaluate('({a} + {b}) * 2', $v));
        $this->assertSame(2.5, FormulaEvaluator::evaluate('10 / 4', []));
    }

    public function test_unary_minus_and_decimals(): void
    {
        $this->assertSame(2.0, FormulaEvaluator::evaluate('-{a} + 5', ['a' => 3.0]));
        $this->assertSame(3.0, FormulaEvaluator::evaluate('1.5 * 2', []));
        $this->assertSame(-6.0, FormulaEvaluator::evaluate('-(2 * 3)', []));
    }

    public function test_unknown_references_count_as_zero(): void
    {
        $this->assertSame(5.0, FormulaEvaluator::evaluate('{missing} + 5', []));
        $this->assertSame(0.0, FormulaEvaluator::evaluate('{missing}', []));
    }

    public function test_reference_names_are_case_insensitive_and_allow_parentheses(): void
    {
        $this->assertSame(9.99, FormulaEvaluator::evaluate('{monatliche kosten (€)}', ['monatliche kosten (€)' => 9.99]));
    }

    public function test_division_by_zero_returns_null(): void
    {
        $this->assertNull(FormulaEvaluator::evaluate('{a} / 0', ['a' => 5.0]));
        $this->assertNull(FormulaEvaluator::evaluate('1 / ({a} - {a})', ['a' => 4.0]));
    }

    public function test_malformed_expressions_return_null(): void
    {
        $this->assertNull(FormulaEvaluator::evaluate('', []));
        $this->assertNull(FormulaEvaluator::evaluate('{a} +', ['a' => 1.0]));
        $this->assertNull(FormulaEvaluator::evaluate('2 2', []));
        $this->assertNull(FormulaEvaluator::evaluate('(1 + 2', []));
        $this->assertNull(FormulaEvaluator::evaluate('1 + )', []));
        $this->assertNull(FormulaEvaluator::evaluate('{a} & {b}', ['a' => 1.0, 'b' => 2.0]));
        $this->assertNull(FormulaEvaluator::evaluate('{unterminated', []));
    }
}
