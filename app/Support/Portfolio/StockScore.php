<?php

namespace App\Support\Portfolio;

/**
 * Points system for choosing shares, following the top-down approach of the "Aktien- und
 * Börsenführerschein": company figures (valuation, profitability, growth, balance sheet, dividend),
 * the chart and the environment (sector and global market) give 0–2 points each. Criteria without
 * data are left out; the share of the possible points gives the verdict.
 */
class StockScore
{
    /**
     * @param  array<string, mixed>  $figures  fundamentals
     * @param  ?array{trend: string, momentum_6m: ?float}  $technical
     * @return array{rows: list<array{group: string, label: string, value: string, points: int}>, points: int, max: int, percent: int, verdict: string}
     */
    public static function rate(array $figures, ?array $technical = null, ?string $sectorStanding = null, ?string $marketLight = null): array
    {
        $num = fn (string $key) => is_numeric($figures[$key] ?? null) ? (float) $figures[$key] : null;
        $percent = fn (float $value) => number_format($value * 100, 1, ',', '.').' %';
        $decimal = fn (float $value) => number_format($value, 1, ',', '.');
        $rows = [];
        $add = function (string $group, string $label, ?float $value, string $shown, callable $points) use (&$rows) {
            if ($value !== null) {
                $rows[] = ['group' => $group, 'label' => $label, 'value' => $shown, 'points' => (int) $points($value)];
            }
        };

        $pe = $num('pe_ratio');
        $add('company', __('P/E ratio'), $pe, $pe !== null ? $decimal($pe) : '', fn (float $v) => $v > 0 && $v < 15 ? 2 : ($v > 0 && $v <= 25 ? 1 : 0));
        $pcf = $num('price_to_cashflow');
        $add('company', __('Price/cash flow'), $pcf, $pcf !== null ? $decimal($pcf) : '', fn (float $v) => $v > 0 && $v < 10 ? 2 : ($v > 0 && $v <= 20 ? 1 : 0));
        $margin = $num('operating_margins');
        $add('company', __('EBIT margin'), $margin, $margin !== null ? $percent($margin) : '', fn (float $v) => $v >= 0.15 ? 2 : ($v >= 0.08 ? 1 : 0));
        $roe = $num('return_on_equity');
        $add('company', __('Return on equity'), $roe, $roe !== null ? $percent($roe) : '', fn (float $v) => $v >= 0.15 ? 2 : ($v >= 0.08 ? 1 : 0));
        $growth = $num('revenue_growth');
        $add('company', __('Revenue growth'), $growth, $growth !== null ? $percent($growth) : '', fn (float $v) => $v >= 0.10 ? 2 : ($v > 0 ? 1 : 0));
        $earnings = $num('earnings_growth');
        $add('company', __('Earnings growth'), $earnings, $earnings !== null ? $percent($earnings) : '', fn (float $v) => $v >= 0.10 ? 2 : ($v > 0 ? 1 : 0));
        $debt = $num('debt_to_equity');
        $add('company', __('Debt/equity'), $debt, $debt !== null ? number_format($debt, 2, ',', '.') : '', fn (float $v) => $v < 0.5 ? 2 : ($v < 1 ? 1 : 0));
        $yield = $num('dividend_yield');
        $add('company', __('Dividend yield'), $yield, $yield !== null ? $percent($yield) : '', fn (float $v) => $v >= 0.02 && $v <= 0.07 ? 2 : ($v > 0 ? 1 : 0));

        if ($technical && $technical['trend'] !== 'unknown') {
            $trendLabels = ['up' => __('Uptrend'), 'down' => __('Downtrend'), 'sideways' => __('Sideways')];
            $rows[] = ['group' => 'chart', 'label' => __('Trend (200-day line)'), 'value' => $trendLabels[$technical['trend']], 'points' => ['up' => 2, 'sideways' => 1, 'down' => 0][$technical['trend']]];
        }

        if ($technical && $technical['momentum_6m'] !== null) {
            $momentum = $technical['momentum_6m'];
            $rows[] = ['group' => 'chart', 'label' => __('Momentum 6 months'), 'value' => ($momentum >= 0 ? '+' : '').$percent($momentum), 'points' => $momentum >= 0.10 ? 2 : ($momentum > 0 ? 1 : 0)];
        }

        if ($sectorStanding) {
            $rows[] = ['group' => 'market', 'label' => __('Sector'), 'value' => ['top' => __('among the strongest'), 'middle' => __('average'), 'bottom' => __('among the weakest')][$sectorStanding], 'points' => ['top' => 2, 'middle' => 1, 'bottom' => 0][$sectorStanding]];
        }

        if ($marketLight && $marketLight !== 'unknown') {
            $rows[] = ['group' => 'market', 'label' => __('Global market'), 'value' => ['green' => __('favourable'), 'yellow' => __('mixed'), 'red' => __('difficult')][$marketLight], 'points' => ['green' => 2, 'yellow' => 1, 'red' => 0][$marketLight]];
        }

        $points = array_sum(array_column($rows, 'points'));
        $max = count($rows) * 2;
        $share = $max > 0 ? (int) round($points / $max * 100) : 0;

        return [
            'rows' => $rows,
            'points' => $points,
            'max' => $max,
            'percent' => $share,
            'verdict' => $max < 8 ? 'unknown' : ($share >= 70 ? 'buy' : ($share >= 50 ? 'watch' : 'avoid')),
        ];
    }

    /**
     * @return array<string, array{label: string, color: string}>
     */
    public static function verdicts(): array
    {
        return [
            'buy' => ['label' => __('Attractive – candidate for buying'), 'color' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200'],
            'watch' => ['label' => __('Mixed – keep watching'), 'color' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200'],
            'avoid' => ['label' => __('Weak – rather not'), 'color' => 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'],
            'unknown' => ['label' => __('Too little data'), 'color' => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'],
        ];
    }
}
