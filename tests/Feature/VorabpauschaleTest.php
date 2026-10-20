<?php

namespace Tests\Feature;

use App\Support\Portfolio\Vorabpauschale;
use Tests\TestCase;

class VorabpauschaleTest extends TestCase
{
    public function test_base_return_and_tax_for_an_accumulating_equity_etf(): void
    {
        $result = Vorabpauschale::estimate(10000, 11000, 0, 2025, 'equity');

        // 10,000 × 2.53 % × 0.7 = 177.10
        $this->assertEqualsWithDelta(177.10, $result['base_return'], 0.01);
        $this->assertEqualsWithDelta(177.10, $result['gross'], 0.01);
        // 30 % Teilfreistellung → 123.97 taxable
        $this->assertEqualsWithDelta(123.97, $result['taxable'], 0.01);
        // 25 % + 5.5 % Soli
        $this->assertEqualsWithDelta(123.97 * 0.25 * 1.055, $result['tax'], 0.01);
    }

    public function test_no_vorabpauschale_when_the_fund_lost_value(): void
    {
        $result = Vorabpauschale::estimate(10000, 9000, 0, 2025, 'equity');

        $this->assertSame(0.0, $result['gross']);
        $this->assertSame(0.0, $result['tax']);
    }

    public function test_distributions_reduce_the_vorabpauschale(): void
    {
        $result = Vorabpauschale::estimate(10000, 11000, 500, 2025, 'equity');

        // Base return 177.10 minus 500 distributions is negative → zero.
        $this->assertSame(0.0, $result['gross']);
    }

    public function test_the_gain_caps_the_vorabpauschale(): void
    {
        // Base return would be 177.10, but the fund only gained 50.
        $result = Vorabpauschale::estimate(10000, 10050, 0, 2025, 'none');

        $this->assertEqualsWithDelta(50.0, $result['gross'], 0.01);
        $this->assertEqualsWithDelta(50.0, $result['taxable'], 0.01);
    }
}
