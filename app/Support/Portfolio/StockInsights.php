<?php

namespace App\Support\Portfolio;

use App\Models\NewsArticle;
use App\Models\Security;
use Illuminate\Support\Collection;

/**
 * Plain readings of a security's data: insider barometer (do managers buy or sell their own
 * shares?), analyst consensus with upside to the price target, the growth of revenue and
 * earnings, and news about plans, products and deals. No AI – rules that can be explained.
 */
class StockInsights
{
    /** Words in headlines that point to plans, new products, deals or investments. */
    private const PLAN_PATTERN = '/\b(plant|plan(t|en|s)?|neue[nrs]?|launch(es|ed)?|startet|stellt .* vor|unveil|präsentiert|produkt|product|übernahme|übernimmt|acquisition|acquire[sd]?|kauft|merger|fusion|expansion|expandiert|investiert|invest(s|ment)|werk|fabrik|factory|strategie|strategy|partnerschaft|partnership|kooperation|patent|zulassung|approval|auftrag|contract|deal)\b/iu';

    /**
     * Insider barometer from the purchases and sales of the last months: +100 only purchases,
     * −100 only sales. Sales happen often (salary in shares), purchases are the stronger signal.
     *
     * @param  array<string, mixed>  $fundamentals
     * @return ?array{score: int, level: string, label: string, buys: int, sells: int, period: string}
     */
    public static function insiderBarometer(array $fundamentals): ?array
    {
        $insider = $fundamentals['insider'] ?? null;

        if (! is_array($insider)) {
            return null;
        }

        $buys = (int) ($insider['buys'] ?? 0);
        $sells = (int) ($insider['sells'] ?? 0);

        if ($buys + $sells === 0) {
            return ['score' => 0, 'level' => 'neutral', 'label' => __('No insider trades'), 'buys' => 0, 'sells' => 0, 'period' => (string) ($insider['period'] ?? '6m')];
        }

        $score = (int) round(($buys - $sells) / ($buys + $sells) * 100);
        [$level, $label] = match (true) {
            $score >= 50 => ['strong_buy', __('Insiders buy a lot')],
            $score >= 15 => ['buy', __('Insiders are buying')],
            $score <= -50 => ['strong_sell', __('Insiders sell a lot')],
            $score <= -15 => ['sell', __('Insiders are selling')],
            default => ['neutral', __('Balanced')],
        };

        return ['score' => $score, 'level' => $level, 'label' => $label, 'buys' => $buys, 'sells' => $sells, 'period' => (string) ($insider['period'] ?? '6m')];
    }

    /**
     * What the analysts say: votes (strong buy … strong sell) and the way to the mean price target.
     *
     * @param  array<string, mixed>  $fundamentals
     * @return ?array{level: string, label: string, buy: int, hold: int, sell: int, total: int, upside: ?float}
     */
    public static function consensus(array $fundamentals, ?float $price): ?array
    {
        $votes = $fundamentals['analyst_votes'] ?? null;
        $target = $fundamentals['analyst_target'] ?? null;
        $upside = $price && is_numeric($target) && $price > 0 ? round(((float) $target / $price - 1) * 100, 1) : null;

        if (is_array($votes) && array_sum($votes) > 0) {
            $total = array_sum($votes);
            $average = (2 * $votes['strong_buy'] + $votes['buy'] - $votes['sell'] - 2 * $votes['strong_sell']) / $total;
            $level = match (true) {
                $average >= 1.2 => 'strong_buy',
                $average >= 0.4 => 'buy',
                $average > -0.4 => 'hold',
                default => 'sell',
            };

            return [
                'level' => $level,
                'label' => self::levels()[$level],
                'buy' => $votes['strong_buy'] + $votes['buy'],
                'hold' => $votes['hold'],
                'sell' => $votes['sell'] + $votes['strong_sell'],
                'total' => $total,
                'upside' => $upside,
            ];
        }

        $level = match ($fundamentals['recommendation'] ?? null) {
            'strong_buy' => 'strong_buy',
            'buy' => 'buy',
            'hold' => 'hold',
            'underperform', 'sell' => 'sell',
            default => null,
        };

        return $level ? ['level' => $level, 'label' => self::levels()[$level], 'buy' => 0, 'hold' => 0, 'sell' => 0, 'total' => (int) ($fundamentals['analyst_count'] ?? 0), 'upside' => $upside] : null;
    }

    /**
     * @return array<string, string>
     */
    public static function levels(): array
    {
        return ['strong_buy' => __('Strong buy'), 'buy' => __('Buy'), 'hold' => __('Hold'), 'sell' => __('Sell')];
    }

    /**
     * Growth of revenue and earnings from the first to the last year shown, in percent per year.
     *
     * @param  array<string, mixed>  $fundamentals
     * @return array{years: list<array{year: string, revenue: ?float, earnings: ?float}>, revenue_cagr: ?float, earnings_cagr: ?float}
     */
    public static function financials(array $fundamentals): array
    {
        $years = array_values(array_filter((array) ($fundamentals['financials'] ?? []), 'is_array'));
        $cagr = function (string $key) use ($years): ?float {
            $first = $years[0][$key] ?? null;
            $last = $years[count($years) - 1][$key] ?? null;
            $span = count($years) - 1;

            return $span > 0 && $first > 0 && $last > 0 ? round((($last / $first) ** (1 / $span) - 1) * 100, 1) : null;
        };

        return ['years' => $years, 'revenue_cagr' => $cagr('revenue'), 'earnings_cagr' => $cagr('earnings')];
    }

    /**
     * Headlines about plans, products, deals and investments.
     *
     * @param  Collection<int, NewsArticle>  $news
     * @return Collection<int, NewsArticle>
     */
    public static function plans(Collection $news): Collection
    {
        return $news->filter(fn (NewsArticle $article) => (bool) preg_match(self::PLAN_PATTERN, (string) $article->title))->values();
    }

    /**
     * The investor relations page with annual reports, or a search for the latest report.
     */
    public static function reportUrl(Security $security): string
    {
        $website = $security->fundamentals['ir_website'] ?? null;

        return is_string($website) && $website !== ''
            ? $website
            : 'https://www.google.com/search?'.http_build_query(['q' => $security->name.' '.__('annual report').' investor relations']);
    }
}
