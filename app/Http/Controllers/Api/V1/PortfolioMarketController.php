<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\NewsArticle;
use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSetting;
use App\Models\Security;
use App\Models\StockRating;
use App\Models\WatchlistItem;
use App\Support\Portfolio\MarketAnalysis;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\NewsFeed;
use App\Support\Portfolio\StockScore;
use App\Support\Portfolio\TechnicalAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Mobile API for the market analysis (global situation, sectors, own shares rated with the points
 * system) and the news about the own securities.
 */
class PortfolioMarketController extends ApiController
{
    public function market(MarketData $market): JsonResponse
    {
        $user = $this->user();
        $portfolioIds = Portfolio::where('user_id', $user->id)->select('id');
        $held = PortfolioPosition::whereIn('portfolio_id', $portfolioIds)->where('quantity', '>', 0)->pluck('symbol');
        $symbols = $held->merge(WatchlistItem::where('user_id', $user->id)->pluck('symbol'))->unique()->take(40);

        $ratings = StockRating::where('user_id', $user->id)->whereIn('symbol', $symbols)->pluck('points', 'symbol');
        $sectorShares = StockScore::sectorShares($user);
        $weights = PortfolioSetting::for($user)->score_weights;
        $verdicts = StockScore::verdicts();

        $scores = Security::whereIn('symbol', $symbols)->get()
            ->reject(fn (Security $security) => str_starts_with($security->symbol, '^') || in_array($security->asset_type, ['etf', 'fund', 'crypto'], true))
            ->map(function (Security $security) use ($market, $held, $ratings, $sectorShares, $weights, $verdicts): array {
                $technical = TechnicalAnalysis::analyse($market->history($security->symbol, now()->subMonths(14))->map(fn ($price) => (float) $price->close)->values()->all());
                $score = StockScore::rate($security->fundamentals ?? [], $technical, StockScore::context($security, $sectorShares, $ratings[$security->symbol] ?? [], $weights));

                return [
                    'symbol' => $security->symbol,
                    'name' => $security->name,
                    'sector' => $security->sector,
                    'held' => $held->contains($security->symbol),
                    'points' => $score['points'],
                    'max' => $score['max'],
                    'percent' => $score['percent'],
                    'open' => $score['open'],
                    'verdict' => $score['verdict'],
                    'verdict_label' => $verdicts[$score['verdict']]['label'] ?? $score['verdict'],
                ];
            })
            ->sortByDesc(fn (array $row) => [$row['percent'], $row['max']])->values()->all();

        return $this->ok(['global' => MarketAnalysis::global($market), 'sectors' => MarketAnalysis::sectorRanking($market), 'scores' => $scores]);
    }

    public function news(Request $request): JsonResponse
    {
        $data = $request->validate(['filter' => ['nullable', 'in:mine,market,all'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $filter = $data['filter'] ?? 'mine';
        $symbols = $this->ownSymbols();
        $term = mb_strtolower(trim((string) ($data['q'] ?? '')));

        $page = NewsArticle::query()
            ->when($filter === 'mine', fn ($query) => $query->whereIn('symbol', $symbols))
            ->when($filter === 'market', fn ($query) => $query->whereNull('symbol'))
            ->when($filter === 'all', fn ($query) => $query->where(fn ($inner) => $inner->whereNull('symbol')->orWhereIn('symbol', $symbols)))
            ->when($term !== '', fn ($query) => $query->whereRaw('LOWER(title) LIKE ?', ['%'.$term.'%']))
            ->orderByDesc('published_at')->paginate(30);

        $names = Security::whereIn('symbol', $symbols)->pluck('name', 'symbol');

        return $this->ok([
            'articles' => collect($page->items())->map(fn (NewsArticle $article): array => [
                'id' => $article->id,
                'symbol' => $article->symbol,
                'name' => $article->symbol !== null ? ($names[$article->symbol] ?? $article->symbol) : null,
                'title' => $article->title,
                'url' => $article->url,
                'source' => $article->source,
                'summary' => $article->summary,
                'published_at' => $article->published_at?->toIso8601String(),
            ])->all(),
            'has_more' => $page->hasMorePages(),
        ]);
    }

    public function refreshNews(NewsFeed $feed): JsonResponse
    {
        $count = 0;

        foreach (Security::whereIn('symbol', $this->ownSymbols())->where('symbol', 'not like', '^%')->limit(15)->get() as $security) {
            $count += $feed->fetchForSecurity($security);
        }

        return $this->ok(['new' => $count]);
    }

    /**
     * @return Collection<int, string>
     */
    private function ownSymbols(): Collection
    {
        $user = $this->user();

        return PortfolioPosition::whereIn('portfolio_id', Portfolio::where('user_id', $user->id)->select('id'))->pluck('symbol')
            ->merge(WatchlistItem::where('user_id', $user->id)->pluck('symbol'))->unique()->values();
    }
}
