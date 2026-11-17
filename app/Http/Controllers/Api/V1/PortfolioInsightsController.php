<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\NewsArticle;
use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioRecommendation;
use App\Models\Security;
use App\Models\WatchlistItem;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\NewsFeed;
use App\Support\Portfolio\StockInsights;
use App\Support\Portfolio\Valuation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Insights" for the app: the own positions at a glance (analysts, insiders, own strategy, next
 * figures, latest news) and the insider barometer of the own shares or the market scan.
 */
class PortfolioInsightsController extends ApiController
{
    public function index(Request $request, Valuation $valuation): JsonResponse
    {
        $scope = $request->query('scope') === 'market' ? 'market' : 'mine';
        $user = $this->user();
        $own = $this->ownSymbols();

        $positions = PortfolioPosition::whereIn('portfolio_id', Portfolio::where('user_id', $user->id)->select('id'))->where('quantity', '>', 0)->with('portfolio')->get();
        $rows = collect($valuation->rows($positions))->groupBy(fn (array $row): string => $row['position']->symbol)->map(function ($group): array {
            $first = $group->first();

            return [
                'security' => $first['security'],
                'symbol' => $first['position']->symbol,
                'name' => $first['security']->name ?? $first['position']->name ?? $first['position']->symbol,
                'value' => (float) $group->sum('value'),
                'gain_percent' => $group->sum('cost') > 0 ? round(($group->sum('value') - $group->sum('cost')) / $group->sum('cost') * 100, 2) : null,
            ];
        })->sortByDesc('value')->values();

        $symbols = $rows->pluck('symbol');
        $news = NewsArticle::whereIn('symbol', $symbols)->orderByDesc('published_at')->get()->groupBy('symbol');
        $strategy = PortfolioRecommendation::where('user_id', $user->id)->whereIn('symbol', $symbols)->get()->keyBy('symbol');

        $checks = $rows->map(function (array $row) use ($news, $strategy): array {
            $figures = $row['security']->fundamentals ?? [];
            $symbolNews = $news->get($row['symbol'], collect());
            $latest = $symbolNews->first();
            $plan = StockInsights::plans($symbolNews)->first();
            $rec = $strategy->get($row['symbol']);

            return [
                'symbol' => $row['symbol'],
                'name' => $row['name'],
                'value' => $row['value'],
                'gain_percent' => $row['gain_percent'],
                'consensus' => StockInsights::consensus($figures, $row['security']?->last_price !== null ? (float) $row['security']->last_price : null),
                'insider' => StockInsights::insiderBarometer($figures),
                'strategy' => $rec ? ['rating' => $rec->rating, 'score' => $rec->score] : null,
                'next_earnings' => $figures['next_earnings'] ?? null,
                'news' => $latest ? ['title' => $latest->title, 'url' => $latest->url, 'source' => $latest->source, 'published_at' => $latest->published_at?->toIso8601String()] : null,
                'plan' => $plan ? ['title' => $plan->title, 'url' => $plan->url] : null,
            ];
        })->all();

        $barometer = Security::query()->whereNotNull('fundamentals')
            ->when($scope === 'mine', fn ($query) => $query->whereIn('symbol', $own), fn ($query) => $query->whereNotIn('symbol', $own))
            ->get()
            ->map(fn (Security $security): array => ['security' => $security, 'insider' => StockInsights::insiderBarometer($security->fundamentals ?? [])])
            ->filter(fn (array $entry): bool => $entry['insider'] !== null && $entry['insider']['buys'] + $entry['insider']['sells'] > 0)
            ->sortByDesc(fn (array $entry): array => [$entry['insider']['score'], $entry['insider']['buys']])
            ->values();

        $present = fn (array $entry): array => ['symbol' => $entry['security']->symbol, 'name' => $entry['security']->name] + (array) $entry['insider'];

        return $this->ok([
            'scope' => $scope,
            'checks' => $checks,
            'buying' => $barometer->filter(fn (array $entry): bool => ($entry['insider']['score']) > 0)->take(25)->map($present)->values()->all(),
            'selling' => $barometer->filter(fn (array $entry): bool => ($entry['insider']['score']) < 0)->sortBy(fn (array $entry): int => $entry['insider']['score'])->take(15)->map($present)->values()->all(),
            'updated_at' => Security::whereIn('symbol', $symbols)->max('fundamentals_updated_at'),
        ]);
    }

    /**
     * Loads key figures, insider trades and news of the own positions again.
     */
    public function refresh(MarketData $market, NewsFeed $feed): JsonResponse
    {
        $updated = 0;

        foreach (Security::whereIn('symbol', $this->ownSymbols())->limit(40)->get() as $security) {
            if ($market->fundamentals($security, refresh: true)) {
                $updated++;
            }
            $feed->fetchForSecurity($security, 5);
        }

        return $this->ok(['updated' => $updated]);
    }

    /**
     * @return list<string>
     */
    private function ownSymbols(): array
    {
        $user = $this->user();

        return PortfolioPosition::whereIn('portfolio_id', Portfolio::where('user_id', $user->id)->select('id'))->where('quantity', '>', 0)->pluck('symbol')
            ->merge(WatchlistItem::where('user_id', $user->id)->pluck('symbol'))->unique()->values()->all();
    }
}
