<?php

namespace App\Support\Portfolio;

use Illuminate\Support\Facades\Cache;

/**
 * Top-down analysis as taught in the "Aktien- und Börsenführerschein": first the global situation
 * (major indices, interest rates, volatility, currency, commodities), then the sectors (sector ETFs
 * compared with the S&P 500), then the single company (key figures, chart, points).
 */
class MarketAnalysis
{
    /**
     * Global indicators: label, symbol and how a rising value reads for shares (1 good, -1 bad, 0 neutral).
     *
     * @return array<string, array{label: string, direction: int}>
     */
    public static function indicators(): array
    {
        return [
            '^GDAXI' => ['label' => 'DAX', 'direction' => 1],
            '^STOXX50E' => ['label' => 'Euro Stoxx 50', 'direction' => 1],
            '^GSPC' => ['label' => 'S&P 500', 'direction' => 1],
            '^IXIC' => ['label' => 'Nasdaq', 'direction' => 1],
            '^N225' => ['label' => 'Nikkei 225', 'direction' => 1],
            '^TNX' => ['label' => __('US 10-year yield'), 'direction' => -1],
            '^VIX' => ['label' => __('VIX (fear index)'), 'direction' => -1],
            'EURUSD=X' => ['label' => 'EUR/USD', 'direction' => 0],
            'GC=F' => ['label' => __('Gold'), 'direction' => 0],
            'CL=F' => ['label' => __('Oil (WTI)'), 'direction' => 0],
        ];
    }

    /**
     * US sector ETFs with the Yahoo sector names they stand for.
     *
     * @return array<string, array{label: string, sector: string}>
     */
    public static function sectors(): array
    {
        return [
            'XLK' => ['label' => __('Technology'), 'sector' => 'Technology'],
            'XLV' => ['label' => __('Healthcare'), 'sector' => 'Healthcare'],
            'XLF' => ['label' => __('Financials'), 'sector' => 'Financial Services'],
            'XLY' => ['label' => __('Consumer cyclical'), 'sector' => 'Consumer Cyclical'],
            'XLP' => ['label' => __('Consumer staples'), 'sector' => 'Consumer Defensive'],
            'XLI' => ['label' => __('Industrials'), 'sector' => 'Industrials'],
            'XLE' => ['label' => __('Energy'), 'sector' => 'Energy'],
            'XLU' => ['label' => __('Utilities'), 'sector' => 'Utilities'],
            'XLB' => ['label' => __('Materials'), 'sector' => 'Basic Materials'],
            'XLRE' => ['label' => __('Real estate'), 'sector' => 'Real Estate'],
            'XLC' => ['label' => __('Communication'), 'sector' => 'Communication Services'],
        ];
    }

    /**
     * The global situation: each indicator with trend and changes, plus a market light.
     *
     * @return array{rows: list<array{symbol: string, label: string, price: ?float, change_3m: ?float, change_12m: ?float, trend: string, reading: int}>, light: string, positive: int, rated: int}
     */
    public static function global(MarketData $market): array
    {
        return Cache::remember('market-analysis:global:'.today()->toDateString(), now()->addHours(6), function () use ($market) {
            $rows = [];

            foreach (self::indicators() as $symbol => $indicator) {
                $row = self::row($market, $symbol);
                $reading = $row['trend'] === 'unknown' || $indicator['direction'] === 0 ? 0 : ($row['trend'] === 'up' ? $indicator['direction'] : ($row['trend'] === 'down' ? -$indicator['direction'] : 0));
                $rows[] = ['symbol' => $symbol, 'label' => $indicator['label'], ...$row, 'reading' => $reading];
            }

            $rated = count(array_filter($rows, fn (array $row) => $row['reading'] !== 0 || $row['trend'] === 'sideways'));
            $positive = count(array_filter($rows, fn (array $row) => $row['reading'] > 0));
            $negative = count(array_filter($rows, fn (array $row) => $row['reading'] < 0));

            return [
                'rows' => $rows,
                'light' => $rated === 0 ? 'unknown' : ($positive >= $negative * 2 ? 'green' : ($negative > $positive ? 'red' : 'yellow')),
                'positive' => $positive,
                'rated' => $rated,
            ];
        });
    }

    /**
     * Sector ETFs ranked by their performance over six months compared with the S&P 500.
     *
     * @return list<array{symbol: string, label: string, sector: string, change_3m: ?float, change_6m: ?float, change_12m: ?float, relative_6m: ?float, trend: string, rank: int}>
     */
    public static function sectorRanking(MarketData $market): array
    {
        return Cache::remember('market-analysis:sectors:'.today()->toDateString(), now()->addHours(6), function () use ($market) {
            $benchmark = self::row($market, '^GSPC');
            $rows = [];

            foreach (self::sectors() as $symbol => $sector) {
                $row = self::row($market, $symbol);
                $rows[] = [
                    'symbol' => $symbol,
                    'label' => $sector['label'],
                    'sector' => $sector['sector'],
                    'change_3m' => $row['change_3m'],
                    'change_6m' => $row['change_6m'],
                    'change_12m' => $row['change_12m'],
                    'relative_6m' => $row['change_6m'] !== null && $benchmark['change_6m'] !== null ? round($row['change_6m'] - $benchmark['change_6m'], 4) : null,
                    'trend' => $row['trend'],
                ];
            }

            usort($rows, fn (array $a, array $b) => ($b['relative_6m'] ?? -INF) <=> ($a['relative_6m'] ?? -INF));

            return array_map(fn (array $row, int $index) => $row + ['rank' => $index + 1], $rows, array_keys($rows));
        });
    }

    /**
     * The market light and sector ranking of today if they were already calculated (no fetching).
     *
     * @return array{light: ?string, sectors: list<array{symbol: string, label: string, sector: string, change_3m: ?float, change_6m: ?float, change_12m: ?float, relative_6m: ?float, trend: string, rank: int}>}
     */
    public static function cached(): array
    {
        $global = Cache::get('market-analysis:global:'.today()->toDateString());
        $sectors = Cache::get('market-analysis:sectors:'.today()->toDateString());

        return ['light' => is_array($global) ? $global['light'] : null, 'sectors' => is_array($sectors) ? $sectors : []];
    }

    /**
     * Where a Yahoo sector stands in the ranking: "top", "middle", "bottom" or null.
     *
     * @param  list<array{sector: string, rank: int, relative_6m: ?float}>  $ranking
     */
    public static function sectorStanding(array $ranking, ?string $sector): ?string
    {
        $rated = array_values(array_filter($ranking, fn (array $row) => $row['relative_6m'] !== null));
        $match = collect($rated)->firstWhere('sector', $sector);

        if (! $match || count($rated) < 3) {
            return null;
        }

        $position = array_search($match, $rated, true) / count($rated);

        return $position < 1 / 3 ? 'top' : ($position < 2 / 3 ? 'middle' : 'bottom');
    }

    /**
     * Price, changes and trend of one symbol from the daily closes.
     *
     * @return array{price: ?float, change_3m: ?float, change_6m: ?float, change_12m: ?float, trend: string}
     */
    private static function row(MarketData $market, string $symbol): array
    {
        $closes = $market->history($symbol, now()->subMonths(13))->map(fn ($price) => (float) $price->close)->filter(fn (float $close) => $close > 0)->values()->all();
        $count = count($closes);
        $change = fn (int $days) => $count > $days ? round($closes[$count - 1] / $closes[$count - 1 - $days] - 1, 4) : null;
        $sma200 = TechnicalAnalysis::sma($closes, 200);

        return [
            'price' => $count ? $closes[$count - 1] : null,
            'change_3m' => $change(63),
            'change_6m' => $change(126),
            'change_12m' => $change(250),
            'trend' => $sma200 === null ? 'unknown' : ($closes[$count - 1] > $sma200 * 1.02 ? 'up' : ($closes[$count - 1] < $sma200 * 0.98 ? 'down' : 'sideways')),
        ];
    }
}
