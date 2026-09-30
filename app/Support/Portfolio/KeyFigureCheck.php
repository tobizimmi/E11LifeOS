<?php

namespace App\Support\Portfolio;

/**
 * Plain-language reading of a security's key figures: each value with a traffic light and a
 * short explanation (rules of thumb – always compare within the industry).
 */
class KeyFigureCheck
{
    /**
     * @param  array<string, mixed>  $figures  fundamentals from MarketData
     * @return list<array{key: string, label: string, value: string, level: string, note: string}>
     */
    public static function assess(array $figures): array
    {
        $percent = fn (float $value) => number_format($value * 100, 1, ',', '.').' %';
        $number = fn (float $value, int $decimals = 1) => number_format($value, $decimals, ',', '.');
        $rows = [];

        if (is_numeric($figures['pe_ratio'] ?? null)) {
            $pe = (float) $figures['pe_ratio'];
            $rows[] = ['key' => 'pe_ratio', 'label' => __('P/E ratio'), 'value' => $number($pe),
                'level' => $pe <= 0 ? 'red' : ($pe < 15 ? 'green' : ($pe <= 25 ? 'yellow' : 'red')),
                'note' => $pe <= 0 ? __('Loss-making – the P/E is not meaningful.') : ($pe < 15 ? __('Cheap compared with the long-term market average of about 15–20.') : ($pe <= 25 ? __('Fairly valued to demanding.') : __('Expensive – high growth is already priced in.')))];
        }

        if (is_numeric($figures['forward_pe'] ?? null) && is_numeric($figures['pe_ratio'] ?? null) && (float) $figures['forward_pe'] > 0) {
            $rows[] = ['key' => 'forward_pe', 'label' => __('Forward P/E'), 'value' => $number((float) $figures['forward_pe']),
                'level' => (float) $figures['forward_pe'] < (float) $figures['pe_ratio'] ? 'green' : 'yellow',
                'note' => (float) $figures['forward_pe'] < (float) $figures['pe_ratio'] ? __('Analysts expect rising profits.') : __('Analysts expect flat or falling profits.')];
        }

        if (is_numeric($figures['price_to_book'] ?? null)) {
            $pb = (float) $figures['price_to_book'];
            $rows[] = ['key' => 'price_to_book', 'label' => __('Price/book'), 'value' => $number($pb),
                'level' => $pb < 1.5 ? 'green' : ($pb <= 4 ? 'yellow' : 'neutral'),
                'note' => $pb < 1 ? __('Valued below its book equity.') : __('Most meaningful for banks, insurers and asset-heavy companies.')];
        }

        if (is_numeric($figures['return_on_equity'] ?? null)) {
            $roe = (float) $figures['return_on_equity'];
            $rows[] = ['key' => 'return_on_equity', 'label' => __('Return on equity'), 'value' => $percent($roe),
                'level' => $roe >= 0.15 ? 'green' : ($roe >= 0.08 ? 'yellow' : 'red'),
                'note' => $roe >= 0.15 ? __('Strong profitability – check that it is not driven by high debt.') : ($roe >= 0.08 ? __('Average profitability.') : __('Weak profitability.'))];
        }

        if (is_numeric($figures['operating_margins'] ?? null)) {
            $margin = (float) $figures['operating_margins'];
            $rows[] = ['key' => 'operating_margins', 'label' => __('Operating margin'), 'value' => $percent($margin),
                'level' => $margin >= 0.15 ? 'green' : ($margin >= 0.05 ? 'yellow' : 'red'),
                'note' => $margin >= 0.15 ? __('High margins often point to pricing power or a moat.') : __('Low margins leave little room when costs rise.')];
        }

        if (is_numeric($figures['revenue_growth'] ?? null)) {
            $growth = (float) $figures['revenue_growth'];
            $rows[] = ['key' => 'revenue_growth', 'label' => __('Revenue growth'), 'value' => $percent($growth),
                'level' => $growth >= 0.08 ? 'green' : ($growth >= 0 ? 'yellow' : 'red'),
                'note' => $growth < 0 ? __('Revenue is shrinking.') : __('Growth compared with the previous year.')];
        }

        if (is_numeric($figures['debt_to_equity'] ?? null)) {
            $debt = (float) $figures['debt_to_equity'];
            $rows[] = ['key' => 'debt_to_equity', 'label' => __('Debt/equity'), 'value' => $number($debt, 2),
                'level' => $debt < 1 ? 'green' : ($debt <= 2 ? 'yellow' : 'red'),
                'note' => $debt < 1 ? __('Solid balance sheet.') : __('High debt – a risk in crises and with rising interest (not meaningful for banks).')];
        }

        if (is_numeric($figures['dividend_yield'] ?? null)) {
            $yield = (float) $figures['dividend_yield'];
            $rows[] = ['key' => 'dividend_yield', 'label' => __('Dividend yield'), 'value' => $percent($yield),
                'level' => $yield > 0.07 ? 'yellow' : 'neutral',
                'note' => $yield > 0.07 ? __('Unusually high – check whether the dividend is covered by free cash flow.') : ($yield > 0 ? __('Paid from profits – check the payout ratio.') : __('No dividend – profits are reinvested.'))];
        }

        return $rows;
    }

    /**
     * How many of the checked figures are green, out of all rated ones.
     *
     * @param  list<array{level: string}>  $rows
     * @return array{green: int, rated: int}
     */
    public static function score(array $rows): array
    {
        $rated = array_filter($rows, fn (array $row) => $row['level'] !== 'neutral');

        return ['green' => count(array_filter($rated, fn (array $row) => $row['level'] === 'green')), 'rated' => count($rated)];
    }
}
