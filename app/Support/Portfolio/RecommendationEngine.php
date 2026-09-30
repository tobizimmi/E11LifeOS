<?php

namespace App\Support\Portfolio;

use App\Models\PortfolioPosition;
use App\Models\PortfolioRecommendation;
use App\Models\PortfolioSetting;
use App\Models\Security;
use App\Models\SecurityPrice;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Buy candidates after a price drop ("buy the dip" with quality filters), ported from the old
 * portfolio manager: the price must have fallen by the threshold from the high of the period,
 * the company must be stable enough (market cap, P/E, debt, growth, margins) and the score
 * combines drop, RSI and stability (Thieme strategy: plus 200-day line and dividend).
 */
class RecommendationEngine
{
    public const PRESETS = [
        'conservative' => ['drop_threshold' => 20.0, 'drop_period_days' => 90, 'min_stability_score' => 55.0, 'min_market_cap_bn' => 2.0, 'rsi_threshold' => 35.0, 'max_pe_ratio' => 35.0, 'max_debt_equity' => 1.5, 'check_200ma' => false, 'prefer_dividends' => false],
        'moderate' => ['drop_threshold' => 10.0, 'drop_period_days' => 60, 'min_stability_score' => 40.0, 'min_market_cap_bn' => 0.5, 'rsi_threshold' => 45.0, 'max_pe_ratio' => 60.0, 'max_debt_equity' => 2.5, 'check_200ma' => false, 'prefer_dividends' => false],
        'aggressive' => ['drop_threshold' => 5.0, 'drop_period_days' => 30, 'min_stability_score' => 20.0, 'min_market_cap_bn' => 0.1, 'rsi_threshold' => 55.0, 'max_pe_ratio' => 200.0, 'max_debt_equity' => 5.0, 'check_200ma' => false, 'prefer_dividends' => false],
        'thieme' => ['drop_threshold' => 20.0, 'drop_period_days' => 90, 'min_stability_score' => 40.0, 'min_market_cap_bn' => 1.0, 'rsi_threshold' => 40.0, 'max_pe_ratio' => 40.0, 'max_debt_equity' => 2.0, 'check_200ma' => true, 'prefer_dividends' => true],
    ];

    /** @var array{drop_threshold: float, drop_period_days: int, min_stability_score: float, min_market_cap_bn: float, rsi_threshold: float, max_pe_ratio: float, max_debt_equity: float, check_200ma: bool, prefer_dividends: bool} */
    private array $config;

    private string $strategy;

    public function __construct(?PortfolioSetting $settings = null)
    {
        $this->strategy = $settings?->strategy ?? 'moderate';

        // Presets decide for the named strategies; "custom" uses the user's own values.
        $this->config = $this->strategy === 'custom' && $settings
            ? [
                'drop_threshold' => (float) $settings->drop_threshold,
                'drop_period_days' => (int) $settings->drop_period_days,
                'min_stability_score' => (float) $settings->min_stability_score,
                'min_market_cap_bn' => (float) $settings->min_market_cap_bn,
                'rsi_threshold' => (float) $settings->rsi_threshold,
                'max_pe_ratio' => (float) $settings->max_pe_ratio,
                'max_debt_equity' => (float) $settings->max_debt_equity,
                'check_200ma' => (bool) $settings->check_200ma,
                'prefer_dividends' => (bool) $settings->prefer_dividends,
            ]
            : self::PRESETS[$this->strategy] ?? self::PRESETS['moderate'];
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config + ['strategy' => $this->strategy];
    }

    /**
     * @param  Collection<int, Security>  $securities
     * @return list<array<string, mixed>> best first
     */
    public function analyze(Collection $securities): array
    {
        $closes = SecurityPrice::whereIn('symbol', $securities->pluck('symbol'))->where('date', '>=', now()->subYear()->toDateString())
            ->orderBy('date')->get(['symbol', 'date', 'close'])->groupBy('symbol');

        return $securities
            ->map(fn (Security $security) => $this->analyzeSecurity($security, $closes[$security->symbol] ?? collect()))
            ->filter()->sortByDesc('score')->values()->all();
    }

    /**
     * @param  Collection<int, SecurityPrice>  $prices  daily closes of the last year, oldest first
     * @return array<string, mixed>|null
     */
    public function analyzeSecurity(Security $security, Collection $prices): ?array
    {
        if ($prices->count() < 20 || in_array($security->asset_type, ['other', 'crypto'], true) || str_starts_with($security->symbol, '^')) {
            return null;
        }

        $closes = $prices->pluck('close')->map(fn ($close) => (float) $close)->values()->all();

        // A fresh quote is more current than the last daily close.
        if ($security->last_price > 0 && $security->price_updated_at?->gt(now()->subDays(2))) {
            $closes[] = $security->last_price;
        }

        $price = end($closes);
        $cutoff = now()->subDays($this->config['drop_period_days'])->toDateString();
        $periodHigh = (float) $prices->filter(fn (SecurityPrice $row) => $row->date->toDateString() >= $cutoff)->max('close');

        if ($price <= 0 || $periodHigh <= 0) {
            return null;
        }

        $drop = ($periodHigh - $price) / $periodHigh * 100;

        if ($drop < $this->config['drop_threshold']) {
            return null;
        }

        $capBn = $security->market_cap ? $security->market_cap / 1_000_000_000 : 0.0;

        if ($capBn > 0 && $capBn < $this->config['min_market_cap_bn']) {
            return null;
        }

        $fundamentals = $security->fundamentals;
        $stability = $this->stability($capBn, $fundamentals);
        $minimum = $fundamentals === null ? $this->config['min_stability_score'] * 0.5 : $this->config['min_stability_score'];

        if ($stability['score'] < $minimum) {
            return null;
        }

        $pe = $fundamentals['pe_ratio'] ?? null;
        $debt = $fundamentals['debt_to_equity'] ?? null;

        if (($pe !== null && $pe > 0 && $pe > $this->config['max_pe_ratio']) || ($debt !== null && $debt > $this->config['max_debt_equity'])) {
            return null;
        }

        $rsi = self::rsi($closes);

        // Only oversold or weak prices: the RSI must be below the strategy's limit.
        if ($rsi > $this->config['rsi_threshold']) {
            return null;
        }

        $ma200 = count($closes) >= 50 ? array_sum(array_slice($closes, -200)) / count(array_slice($closes, -200)) : null;
        $belowMa200 = $ma200 !== null && $price < $ma200;
        $hasDividend = ($fundamentals['dividend_yield'] ?? 0) > 0 || ($security->dividend_yield ?? 0) > 0;

        if ($this->config['check_200ma'] && $ma200 !== null && ! $belowMa200 && $this->strategy !== 'thieme') {
            return null;
        }

        $analystBuy = in_array($fundamentals['recommendation'] ?? null, ['strong_buy', 'buy'], true);
        $score = $this->strategy === 'thieme'
            ? $this->thiemeScore($drop, $rsi, $stability['score'], $belowMa200, $hasDividend, $analystBuy)
            : min(100, min(100, $drop / 60 * 100) * 0.35 + ($rsi <= 20 ? 100 : ($rsi <= 50 ? (50 - $rsi) / 30 * 100 : 0)) * 0.25 + $stability['score'] * 0.40 + ($analystBuy ? 10 : 0) + ($this->config['prefer_dividends'] && $hasDividend ? 5 : 0));

        return [
            'symbol' => $security->symbol,
            'name' => $security->name,
            'price' => round($price, 4),
            'currency' => $security->currency,
            'period_high' => round($periodHigh, 4),
            'drop_percent' => round($drop, 2),
            'rsi' => round($rsi, 1),
            'stability' => round($stability['score'], 1),
            'score' => round($score, 1),
            'rating' => match (true) {
                $score >= 75 => 'strong_buy',
                $score >= 50 => 'moderate_buy',
                default => 'watchlist_buy',
            },
            'below_ma200' => $belowMa200,
            'has_dividend' => $hasDividend,
            'reasons' => $this->reasons($drop, $rsi, $stability['factors'], $belowMa200, $hasDividend, $analystBuy),
            'data' => [
                'market_cap_bn' => round($capBn, 2),
                'pe_ratio' => $pe,
                'debt_to_equity' => $debt,
                'ma200' => $ma200 !== null ? round($ma200, 2) : null,
                'analyst_target' => $fundamentals['analyst_target'] ?? null,
                'analyst_rating' => $fundamentals['recommendation'] ?? null,
                'sector' => $security->sector,
                'history_days' => count($closes),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $fundamentals
     * @return array{score: float, factors: list<string>}
     */
    private function stability(float $capBn, ?array $fundamentals): array
    {
        if ($fundamentals === null) {
            return ['score' => 55.0, 'factors' => []];
        }

        $factors = [];
        $score = match (true) {
            $capBn >= 10 => 20,
            $capBn >= 2 => 15,
            $capBn >= 0.3 => 10,
            $capBn > 0 => 5,
            default => 0,
        };

        if ($score >= 15) {
            $factors[] = __('Large market capitalisation');
        }

        $filled = 0;
        $pe = $fundamentals['pe_ratio'] ?? null;

        if ($pe !== null) {
            $filled++;
            $points = match (true) {
                $pe > 0 && $pe <= 15 => 20,
                $pe > 15 && $pe <= 25 => 15,
                $pe > 25 && $pe <= 40 => 10,
                $pe > 40 => 5,
                default => 0,
            };
            $score += $points;

            if ($points >= 15) {
                $factors[] = __('Attractive P/E ratio (:value)', ['value' => number_format($pe, 1, ',', '.')]);
            }
        }

        $debt = $fundamentals['debt_to_equity'] ?? null;

        if ($debt !== null) {
            $filled++;
            $points = match (true) {
                $debt <= 0.5 => 20,
                $debt <= 1.0 => 15,
                $debt <= 2.0 => 10,
                $debt <= 3.0 => 5,
                default => 0,
            };
            $score += $points;

            if ($points >= 15) {
                $factors[] = __('Solid balance sheet (debt/equity :value)', ['value' => number_format($debt, 2, ',', '.')]);
            }
        }

        $growth = $fundamentals['revenue_growth'] ?? null;

        if ($growth !== null) {
            $filled++;
            $points = match (true) {
                $growth >= 0.10 => 20,
                $growth >= 0.05 => 15,
                $growth >= 0.00 => 10,
                $growth >= -0.05 => 5,
                default => 0,
            };
            $score += $points;

            if ($points >= 10) {
                $factors[] = __('Revenue growth of :value %', ['value' => number_format($growth * 100, 1, ',', '.')]);
            }
        }

        $margin = $fundamentals['operating_margins'] ?? null;

        if ($margin !== null) {
            $filled++;
            $points = match (true) {
                $margin >= 0.20 => 20,
                $margin >= 0.10 => 15,
                $margin >= 0.05 => 10,
                $margin > 0 => 5,
                default => 0,
            };
            $score += $points;

            if ($points >= 10) {
                $factors[] = __('Good operating margin of :value %', ['value' => number_format($margin * 100, 1, ',', '.')]);
            }
        }

        // Missing figures do not count against the company: scale to what is known.
        if ($filled > 0 && $filled < 4) {
            $score = $score / (20 + $filled * 20) * 100;
        }

        return ['score' => (float) min(100, $score), 'factors' => $factors];
    }

    /**
     * Relative strength index (14 days, Wilder smoothing).
     *
     * @param  list<float>  $closes
     */
    public static function rsi(array $closes): float
    {
        $count = count($closes);

        if ($count < 15) {
            return 50.0;
        }

        $gains = $losses = [];

        for ($i = 1; $i < $count; $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            $gains[] = max(0, $change);
            $losses[] = max(0, -$change);
        }

        $averageGain = array_sum(array_slice($gains, 0, 14)) / 14;
        $averageLoss = array_sum(array_slice($losses, 0, 14)) / 14;

        for ($i = 14; $i < count($gains); $i++) {
            $averageGain = ($averageGain * 13 + $gains[$i]) / 14;
            $averageLoss = ($averageLoss * 13 + $losses[$i]) / 14;
        }

        return $averageLoss == 0 ? 100.0 : 100 - 100 / (1 + $averageGain / $averageLoss);
    }

    private function thiemeScore(float $drop, float $rsi, float $stability, bool $belowMa200, bool $hasDividend, bool $analystBuy): float
    {
        $score = match (true) {
            $drop >= 35 => 35,
            $drop >= 25 => 25 + ($drop - 25),
            $drop >= 15 => 10,
            default => 0,
        };

        $score += match (true) {
            $rsi <= 25 => 25,
            $rsi <= 30 => 22,
            $rsi <= 35 => 18,
            $rsi <= 40 => 12,
            $rsi <= 45 => 6,
            default => 0,
        };

        return min(100, $score + ($belowMa200 ? 15 : 0) + $stability / 100 * 15 + ($hasDividend ? 5 : 0) + ($analystBuy ? 5 : 0));
    }

    /**
     * @param  list<string>  $factors
     * @return list<string>
     */
    private function reasons(float $drop, float $rsi, array $factors, bool $belowMa200, bool $hasDividend, bool $analystBuy): array
    {
        $reasons = [__('Price down :value % from the high of the last :days days', ['value' => number_format($drop, 1, ',', '.'), 'days' => $this->config['drop_period_days']])];

        if ($rsi <= 30) {
            $reasons[] = __('RSI of :value – oversold', ['value' => number_format($rsi, 1, ',', '.')]);
        } elseif ($rsi <= 40) {
            $reasons[] = __('RSI of :value – low', ['value' => number_format($rsi, 1, ',', '.')]);
        }

        if ($belowMa200) {
            $reasons[] = __('Below the 200-day line');
        }

        if ($hasDividend) {
            $reasons[] = __('Pays a dividend');
        }

        if ($analystBuy) {
            $reasons[] = __('Analyst consensus: buy');
        }

        return [...$reasons, ...$factors];
    }

    /**
     * Historical test of the drop strategy: every day the price is at least $threshold % below
     * the high of the previous $dropDays days counts as a buy signal, sold after $holdDays.
     *
     * @param  Collection<string, Collection<int, SecurityPrice>>  $pricesBySymbol
     * @return array{signals: int, win_rate: ?float, average: ?float, median: ?float, best: ?float, worst: ?float, symbols: int}
     */
    public static function backtest(Collection $pricesBySymbol, float $threshold, int $dropDays, int $holdDays): array
    {
        $returns = [];
        $symbols = 0;

        foreach ($pricesBySymbol as $prices) {
            $closes = $prices->pluck('close')->map(fn ($close) => (float) $close)->values()->all();
            $count = count($closes);

            if ($count < $dropDays + $holdDays + 5) {
                continue;
            }

            $symbols++;
            $cooldown = 0;

            for ($i = $dropDays; $i < $count - $holdDays; $i++) {
                if ($cooldown > 0) {
                    $cooldown--;

                    continue;
                }

                $high = max(array_slice($closes, $i - $dropDays, $dropDays));

                if ($high > 0 && ($high - $closes[$i]) / $high * 100 >= $threshold) {
                    $returns[] = ($closes[$i + $holdDays] / $closes[$i] - 1) * 100;
                    // One signal per holding period, like a real investor.
                    $cooldown = $holdDays;
                }
            }
        }

        if ($returns === []) {
            return ['signals' => 0, 'win_rate' => null, 'average' => null, 'median' => null, 'best' => null, 'worst' => null, 'symbols' => $symbols];
        }

        sort($returns);

        return [
            'signals' => count($returns),
            'win_rate' => round(count(array_filter($returns, fn ($return) => $return > 0)) / count($returns) * 100, 1),
            'average' => round(array_sum($returns) / count($returns), 2),
            'median' => round($returns[intdiv(count($returns), 2)], 2),
            'best' => round(max($returns), 2),
            'worst' => round(min($returns), 2),
            'symbols' => $symbols,
        ];
    }

    /**
     * Securities the engine looks at for a user: the market scan of the last week plus their
     * holdings and watchlist.
     *
     * @return Collection<int, Security>
     */
    public static function universe(User $user): Collection
    {
        $own = PortfolioPosition::whereHas('portfolio', fn ($query) => $query->where('user_id', $user->id))->pluck('symbol')
            ->merge(WatchlistItem::where('user_id', $user->id)->pluck('symbol'));

        return Security::where('scanned_at', '>=', now()->subWeek())->orWhereIn('symbol', $own)->get();
    }

    /**
     * Recomputes and stores the recommendations of a user (from stored prices, no requests).
     */
    public static function refreshFor(User $user): int
    {
        $engine = new self(PortfolioSetting::where('user_id', $user->id)->first());
        $results = $engine->analyze(self::universe($user));

        DB::transaction(function () use ($user, $results) {
            PortfolioRecommendation::where('user_id', $user->id)->delete();

            foreach ($results as $result) {
                PortfolioRecommendation::create(['user_id' => $user->id, 'computed_at' => now()] + $result);
            }
        });

        return count($results);
    }
}
