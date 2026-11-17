<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\InstagramPost;
use App\Models\PortfolioSetting;
use App\Models\PriceAlert;
use App\Models\WatchlistItem;
use App\Support\Portfolio\InstagramFeed;
use App\Support\Portfolio\MarketData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The analysed Instagram posts of the stock profile for the app: filter by sentiment or ideas,
 * put the share on the watchlist, create price alerts at the entry price and switch the push.
 */
class PortfolioInstagramController extends ApiController
{
    public function index(Request $request, MarketData $market): JsonResponse
    {
        $request->validate(['sentiment' => ['nullable', 'in:'.implode(',', InstagramPost::SENTIMENTS)], 'ideas' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1']]);
        $sentiment = (string) $request->query('sentiment', '');

        $posts = InstagramPost::query()
            ->when($sentiment !== '', fn ($query) => $query->where('sentiment', $sentiment))
            ->when($request->boolean('ideas'), fn ($query) => $query->whereNotNull('entry_price'))
            ->orderByDesc('published_at')->paginate(12);

        $symbols = collect($posts->items())->map(fn (InstagramPost $post): ?string => $post->mainTicker())->filter()->unique()->values()->all();
        $quotes = $market->quotes($symbols);
        $watched = WatchlistItem::where('user_id', $this->user()->id)->pluck('symbol')->flip();

        return $this->ok([
            'configured' => InstagramFeed::isConfigured(),
            'profile' => (string) config('services.instagram.profile'),
            'push' => (bool) PortfolioSetting::for($this->user())->getAttribute('instagram_push'),
            'total' => InstagramPost::count(),
            'counts' => InstagramPost::whereNotNull('sentiment')->selectRaw('sentiment, COUNT(*) as total')->groupBy('sentiment')->pluck('total', 'sentiment'),
            'page' => $posts->currentPage(),
            'last_page' => $posts->lastPage(),
            'posts' => collect($posts->items())->map(function (InstagramPost $post) use ($quotes, $watched): array {
                $ticker = $post->mainTicker();
                $quote = $ticker !== null ? ($quotes[$ticker] ?? null) : null;

                return [
                    'id' => $post->id,
                    'profile' => $post->getAttribute('profile'),
                    'published_at' => $post->published_at?->toIso8601String(),
                    'post_url' => $post->getAttribute('post_url'),
                    'thumbnail_url' => $post->getAttribute('thumbnail_url'),
                    'summary' => $post->getAttribute('summary'),
                    'sentiment' => $post->getAttribute('sentiment'),
                    'stock_name' => $post->getAttribute('stock_name'),
                    'ticker' => $ticker,
                    'key_points' => $post->getAttribute('key_points') ?: [],
                    'entry_price' => $post->getAttribute('entry_price'),
                    'entry_type' => $post->getAttribute('entry_type'),
                    'stop_loss' => $post->getAttribute('stop_loss'),
                    'tranches' => $post->getAttribute('tranches') ?: [],
                    'currency' => $post->getAttribute('currency'),
                    'price' => $quote?->last_price !== null ? (float) $quote->last_price : null,
                    'watched' => $ticker !== null && $watched->has($ticker),
                ];
            })->all(),
        ]);
    }

    public function watch(int $post): JsonResponse
    {
        $model = InstagramPost::findOrFail($post);
        $ticker = $model->mainTicker();
        abort_unless($ticker !== null, 422);

        WatchlistItem::firstOrCreate(
            ['user_id' => $this->user()->id, 'symbol' => $ticker],
            ['name' => $model->getAttribute('stock_name') ?: $ticker, 'notes' => '@'.$model->getAttribute('profile').': '.mb_strimwidth((string) $model->getAttribute('summary'), 0, 200, '…')],
        );

        return $this->ok(['symbol' => $ticker]);
    }

    /**
     * Price alerts at the entry price (and each tranche).
     */
    public function alerts(int $post): JsonResponse
    {
        $model = InstagramPost::findOrFail($post);
        $ticker = $model->mainTicker();
        $tranches = $model->getAttribute('tranches');
        $entry = $model->getAttribute('entry_price');
        abort_unless($ticker !== null && ($entry || $tranches), 422);

        $prices = collect($tranches ?: [$entry])->filter();
        foreach ($prices as $price) {
            PriceAlert::firstOrCreate(
                ['user_id' => $this->user()->id, 'symbol' => $ticker, 'condition' => 'below', 'target_price' => $price],
                ['name' => $model->getAttribute('stock_name') ?: $ticker, 'currency' => $model->getAttribute('currency'), 'active' => true],
            );
        }

        return $this->ok(['created' => $prices->count()]);
    }

    public function togglePush(): JsonResponse
    {
        $settings = PortfolioSetting::for($this->user());
        $settings->fill(['instagram_push' => ! (bool) $settings->getAttribute('instagram_push')])->save();

        return $this->ok(['push' => (bool) $settings->getAttribute('instagram_push')]);
    }
}
