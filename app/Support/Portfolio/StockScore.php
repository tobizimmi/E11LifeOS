<?php

namespace App\Support\Portfolio;

use App\Models\PortfolioPosition;
use App\Models\Security;
use App\Models\User;

/**
 * Points system for choosing shares after the "Aktien- und Börsenführerschein": every factor gets
 * 0–3 points and counts with its weighting (double for the hard figures, normal for the soft
 * ones). Figures are rated from the market data; business model, reputation and the like are
 * rated by the member, who can also overrule any point and change the weighting. Factors without
 * data or rating are left out; the share of the possible points gives the verdict.
 */
class StockScore
{
    /**
     * @return array<string, array{label: string, weight: int, auto: bool, hint: string}>
     */
    public static function factors(): array
    {
        return [
            'pe' => ['label' => __('Price/earnings ratio'), 'weight' => 2, 'auto' => true, 'hint' => __('3 below 12, 2 below 18, 1 below 25')],
            'index' => ['label' => __('Market segment/index'), 'weight' => 2, 'auto' => true, 'hint' => __('By market value: 3 from 100 bn (leading index), 2 from 10 bn, 1 from 2 bn')],
            'high_low' => ['label' => __('52-week high/low'), 'weight' => 2, 'auto' => true, 'hint' => __('3 at 10–30 % below the high, 2 at 30–50 %, 1 close to the high')],
            'diversification' => ['label' => __('Portfolio diversification'), 'weight' => 2, 'auto' => true, 'hint' => __('Share of the sector in your portfolio: 3 below 10 %, 2 below 20 %, 1 below 30 %')],
            'debt' => ['label' => __('Equity/debt'), 'weight' => 2, 'auto' => true, 'hint' => __('Debt/equity: 3 below 0.3, 2 below 0.7, 1 below 1.5')],
            'book' => ['label' => __('Book value or P/B'), 'weight' => 2, 'auto' => true, 'hint' => __('3 below 1, 2 below 2, 1 below 4')],
            'dividend' => ['label' => __('Dividend yield'), 'weight' => 2, 'auto' => true, 'hint' => __('3 at 4–9 %, 2 from 2.5 %, 1 with any dividend')],
            'revenue_earnings' => ['label' => __('Revenue and earnings'), 'weight' => 2, 'auto' => true, 'hint' => __('3 when both grow by 10 % and more, 2 when both grow, 1 when one grows')],
            'sustainability' => ['label' => __('Sustainability/ethics'), 'weight' => 2, 'auto' => false, 'hint' => __('Your rating: environment, social standards, corporate culture')],
            'value' => ['label' => __('Value, financial strength'), 'weight' => 2, 'auto' => true, 'hint' => __('Return on equity (or margin): 3 from 20 %, 2 from 12 %, 1 from 5 %')],
            'growth' => ['label' => __('Growth'), 'weight' => 2, 'auto' => true, 'hint' => __('Revenue growth per year: 3 from 15 %, 2 from 7 %, 1 above 0 %')],
            'technical' => ['label' => __('Technical analysis'), 'weight' => 2, 'auto' => true, 'hint' => __('3 uptrend with momentum, 2 uptrend, 1 sideways, 0 downtrend')],
            'business_model' => ['label' => __('Business model'), 'weight' => 1, 'auto' => false, 'hint' => __('Your rating: understandable, future-proof, with recurring revenue?')],
            'market_position' => ['label' => __('Market position/competition'), 'weight' => 1, 'auto' => false, 'hint' => __('Your rating: market leader, moat, pricing power?')],
            'international' => ['label' => __('Export share/internationality'), 'weight' => 1, 'auto' => false, 'hint' => __('Your rating: how broadly is the revenue spread over countries?')],
            'reputation' => ['label' => __('Brand and reputation'), 'weight' => 1, 'auto' => false, 'hint' => __('Your rating: well-known brand, good reputation?')],
            'news' => ['label' => __('News/communication'), 'weight' => 1, 'auto' => false, 'hint' => __('Your rating: current news and how openly the company reports')],
            'analysts' => ['label' => __('Analyses, rating/ranking'), 'weight' => 1, 'auto' => true, 'hint' => __('Analyst consensus: 3 strong buy, 2 buy, 1 hold, 0 sell')],
            'tradability' => ['label' => __('Tradability'), 'weight' => 1, 'auto' => true, 'hint' => __('By market value: 3 from 2 bn, 2 from 500 m, 1 from 100 m')],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function weightLabels(): array
    {
        return [
            3 => __('Triple weighting: every point counts three times.'),
            2 => __('Double weighting: every point counts twice.'),
            1 => __('Normal weighting: all results count once.'),
        ];
    }

    /**
     * Weighting per factor: the default of the book, changed by the member (0 = left out).
     *
     * @param  array<string, mixed>|null  $own
     * @return array<string, int>
     */
    public static function weights(?array $own = null): array
    {
        return collect(self::factors())
            ->map(fn (array $factor, string $key) => isset($own[$key]) && in_array((int) $own[$key], [0, 1, 2, 3], true) ? (int) $own[$key] : $factor['weight'])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $figures  fundamentals
     * @param  ?array<string, mixed>  $technical  result of TechnicalAnalysis::analyse()
     * @param  array{ratings?: array<string, int>, weights?: array<string, mixed>|null, sector_share?: ?float, sector?: ?string}  $context
     * @return array{rows: list<array{key: string, label: string, hint: string, weight: int, value: string, auto: ?int, points: ?int, own: bool, manual: bool, score: int}>, points: int, max: int, percent: int, rated: int, open: int, verdict: string}
     */
    public static function rate(array $figures, ?array $technical = null, array $context = []): array
    {
        $auto = self::fromFigures($figures, $technical, $context);
        $ratings = array_filter($context['ratings'] ?? [], fn ($value) => in_array($value, [0, 1, 2, 3], true));
        $weights = self::weights($context['weights'] ?? null);
        $rows = [];

        foreach (self::factors() as $key => $factor) {
            if ($weights[$key] === 0) {
                continue;
            }

            $automatic = $auto[$key]['points'] ?? null;
            $points = $ratings[$key] ?? $automatic;
            $rows[] = [
                'key' => $key,
                'label' => $factor['label'],
                'hint' => $factor['hint'],
                'weight' => $weights[$key],
                'value' => $auto[$key]['value'] ?? '',
                'auto' => $automatic,
                'points' => $points,
                'own' => isset($ratings[$key]) && $automatic !== null && $ratings[$key] !== $automatic,
                'manual' => ! $factor['auto'],
                'score' => (int) $points * $weights[$key],
            ];
        }

        $rated = array_values(array_filter($rows, fn (array $row) => $row['points'] !== null));
        $points = array_sum(array_column($rated, 'score'));
        $max = array_sum(array_map(fn (array $row) => 3 * $row['weight'], $rated));
        $share = $max > 0 ? (int) round($points / $max * 100) : 0;

        return [
            'rows' => $rows,
            'points' => $points,
            'max' => $max,
            'percent' => $share,
            'rated' => count($rated),
            'open' => count(array_filter($rows, fn (array $row) => $row['points'] === null && $row['manual'])),
            'verdict' => count($rated) < 8 ? 'unknown' : ($share >= 70 ? 'buy' : ($share >= 50 ? 'watch' : 'avoid')),
        ];
    }

    /**
     * Points from the market data, with the value they are based on.
     *
     * @param  array<string, mixed>  $figures
     * @param  ?array<string, mixed>  $technical
     * @param  array<string, mixed>  $context
     * @return array<string, array{points: int, value: string}>
     */
    private static function fromFigures(array $figures, ?array $technical, array $context): array
    {
        $num = fn (string $key) => is_numeric($figures[$key] ?? null) ? (float) $figures[$key] : null;
        $percent = fn (float $value) => number_format($value * 100, 1, ',', '.').' %';
        $decimal = fn (float $value, int $digits = 1) => number_format($value, $digits, ',', '.');
        $billions = fn (float $value) => $value >= 1e9 ? $decimal($value / 1e9).' '.__('bn') : $decimal($value / 1e6, 0).' '.__('m');
        $out = [];

        if (($pe = $num('pe_ratio')) !== null) {
            $out['pe'] = ['points' => $pe <= 0 ? 0 : ($pe < 12 ? 3 : ($pe < 18 ? 2 : ($pe < 25 ? 1 : 0))), 'value' => $pe <= 0 ? __('loss') : $decimal($pe)];
        }

        if (($cap = $num('market_cap')) !== null && $cap > 0) {
            $out['index'] = ['points' => $cap >= 100e9 ? 3 : ($cap >= 10e9 ? 2 : ($cap >= 2e9 ? 1 : 0)), 'value' => $billions($cap)];
            $out['tradability'] = ['points' => $cap >= 2e9 ? 3 : ($cap >= 500e6 ? 2 : ($cap >= 100e6 ? 1 : 0)), 'value' => $billions($cap)];
        }

        $price = is_numeric($technical['price'] ?? null) ? (float) $technical['price'] : null;
        $high = is_numeric($technical['high_52w'] ?? null) ? (float) $technical['high_52w'] : null;

        if ($price !== null && $high !== null && $high > 0) {
            $below = max(0.0, ($high - $price) / $high);
            $out['high_low'] = ['points' => $below >= 0.5 ? 0 : ($below >= 0.3 ? 2 : ($below >= 0.1 ? 3 : 1)), 'value' => __(':percent below the high', ['percent' => $percent($below)])];
        }

        if (array_key_exists('sector_share', $context) && $context['sector_share'] !== null) {
            $share = (float) $context['sector_share'];
            $out['diversification'] = ['points' => $share < 0.1 ? 3 : ($share < 0.2 ? 2 : ($share < 0.3 ? 1 : 0)), 'value' => trim(($context['sector'] ?? '').' '.$percent($share))];
        }

        if (($debt = $num('debt_to_equity')) !== null) {
            $out['debt'] = ['points' => $debt < 0.3 ? 3 : ($debt < 0.7 ? 2 : ($debt < 1.5 ? 1 : 0)), 'value' => $decimal($debt, 2)];
        }

        if (($book = $num('price_to_book')) !== null) {
            $out['book'] = ['points' => $book <= 0 ? 0 : ($book < 1 ? 3 : ($book < 2 ? 2 : ($book < 4 ? 1 : 0))), 'value' => $decimal($book)];
        }

        if (($yield = $num('dividend_yield')) !== null) {
            $out['dividend'] = ['points' => $yield >= 0.04 && $yield <= 0.09 ? 3 : ($yield >= 0.025 ? 2 : ($yield > 0 ? 1 : 0)), 'value' => $percent($yield)];
        }

        $revenue = $num('revenue_growth');
        $earnings = $num('earnings_growth');

        if ($revenue !== null || $earnings !== null) {
            $growing = array_filter([$revenue, $earnings], fn (?float $value) => $value !== null && $value > 0);
            $strong = $revenue !== null && $earnings !== null && $revenue >= 0.1 && $earnings >= 0.1;
            $out['revenue_earnings'] = [
                'points' => $strong ? 3 : ($revenue > 0 && $earnings > 0 ? 2 : ($growing !== [] ? 1 : 0)),
                'value' => collect([$revenue !== null ? __('Revenue').' '.$percent($revenue) : null, $earnings !== null ? __('Earnings').' '.$percent($earnings) : null])->filter()->implode(' · '),
            ];
        }

        $strength = $num('return_on_equity') ?? $num('operating_margins');

        if ($strength !== null) {
            $out['value'] = ['points' => $strength >= 0.2 ? 3 : ($strength >= 0.12 ? 2 : ($strength >= 0.05 ? 1 : 0)), 'value' => $percent($strength)];
        }

        $cagr = StockInsights::financials($figures)['revenue_cagr'];
        $growth = $cagr !== null ? $cagr / 100 : $revenue;

        if ($growth !== null) {
            $out['growth'] = ['points' => $growth >= 0.15 ? 3 : ($growth >= 0.07 ? 2 : ($growth > 0 ? 1 : 0)), 'value' => $percent($growth).($cagr !== null ? ' '.__('p.a.') : '')];
        }

        if ($technical && ($technical['trend'] ?? 'unknown') !== 'unknown') {
            $trend = $technical['trend'];
            $momentum = is_numeric($technical['momentum_6m'] ?? null) ? (float) $technical['momentum_6m'] : null;
            $labels = ['up' => __('Uptrend'), 'down' => __('Downtrend'), 'sideways' => __('Sideways')];
            $out['technical'] = [
                'points' => $trend === 'up' ? ($momentum !== null && $momentum >= 0.1 ? 3 : 2) : ($trend === 'sideways' ? 1 : 0),
                'value' => ($labels[$trend] ?? $trend).($momentum !== null ? ' · '.($momentum >= 0 ? '+' : '').$percent($momentum) : ''),
            ];
        }

        $consensus = StockInsights::consensus($figures, $price);

        if ($consensus) {
            $out['analysts'] = ['points' => ['strong_buy' => 3, 'buy' => 2, 'hold' => 1, 'sell' => 0][$consensus['level']], 'value' => $consensus['label']];
        }

        return $out;
    }

    /**
     * Share of each sector in the member's portfolios (by purchase value).
     *
     * @return array<string, float>
     */
    public static function sectorShares(User $user): array
    {
        $positions = PortfolioPosition::whereHas('portfolio', fn ($query) => $query->where('user_id', $user->id))->where('quantity', '>', 0)->get(['symbol', 'quantity', 'avg_buy_price']);
        $sectors = Security::whereIn('symbol', $positions->pluck('symbol'))->pluck('sector', 'symbol');
        $total = $positions->sum(fn (PortfolioPosition $position) => $position->quantity * $position->avg_buy_price);

        if ($total <= 0) {
            return [];
        }

        return $positions->groupBy(fn (PortfolioPosition $position) => (string) ($sectors[$position->symbol] ?? ''))
            ->map(fn ($group) => round($group->sum(fn (PortfolioPosition $position) => $position->quantity * $position->avg_buy_price) / $total, 4))
            ->all();
    }

    /**
     * The context for rating one share of the member: own points, weighting and sector share.
     *
     * @param  array<string, float>  $sectorShares
     * @param  array<string, int>  $ratings
     * @param  array<string, mixed>|null  $weights
     * @return array{ratings: array<string, int>, weights: array<string, mixed>|null, sector_share: ?float, sector: ?string}
     */
    public static function context(?Security $security, array $sectorShares, array $ratings, ?array $weights): array
    {
        $sector = $security?->sector;

        return [
            'ratings' => $ratings,
            'weights' => $weights,
            'sector_share' => $sectorShares === [] || ! $sector ? null : ($sectorShares[$sector] ?? 0.0),
            'sector' => $sector,
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
