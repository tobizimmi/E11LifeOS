<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\InstagramPost;
use App\Models\PriceAlert;
use App\Models\WatchlistItem;
use App\Support\Portfolio\InstagramFeed;
use App\Support\Portfolio\MarketData;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;
    use WithPagination;

    #[Url(except: '')]
    public string $sentiment = '';

    #[Url(except: false)]
    public bool $ideasOnly = false;

    public function updated(string $property): void
    {
        if (in_array($property, ['sentiment', 'ideasOnly'], true)) {
            $this->resetPage();
        }
    }

    public function watch(int $postId): void
    {
        $post = InstagramPost::find($postId) ?? abort(404);
        abort_unless($post->mainTicker(), 422);
        WatchlistItem::firstOrCreate(['user_id' => $this->currentUser()->id, 'symbol' => $post->mainTicker()], ['name' => $post->stock_name ?: $post->mainTicker(), 'notes' => '@'.$post->profile.': '.mb_strimwidth((string) $post->summary, 0, 200, '…')]);
        $this->dispatch('toast', message: __('Added to the watchlist.'));
    }

    /**
     * Price alerts at the entry price (and each tranche) and at the stop loss.
     */
    public function alerts(int $postId): void
    {
        $post = InstagramPost::find($postId) ?? abort(404);
        abort_unless($post->mainTicker() && ($post->entry_price || $post->tranches), 422);
        $prices = collect($post->tranches ?: [$post->entry_price])->filter();

        foreach ($prices as $price) {
            PriceAlert::firstOrCreate(
                ['user_id' => $this->currentUser()->id, 'symbol' => $post->mainTicker(), 'condition' => 'below', 'target_price' => $price],
                ['name' => $post->stock_name ?: $post->mainTicker(), 'currency' => $post->currency, 'active' => true],
            );
        }

        $this->dispatch('toast', message: trans_choice(':count price alert created.|:count price alerts created.', $prices->count()));
    }

    public function togglePush(): void
    {
        $settings = $this->portfolioSettings();
        $settings->instagram_push = ! $settings->instagram_push;
        $settings->save();
    }

    public function with(MarketData $market): array
    {
        $posts = InstagramPost::query()
            ->when($this->sentiment !== '', fn ($query) => $query->where('sentiment', $this->sentiment))
            ->when($this->ideasOnly, fn ($query) => $query->whereNotNull('entry_price'))
            ->orderByDesc('published_at')->paginate(12);
        $symbols = collect($posts->items())->map->mainTicker()->filter()->unique()->values()->all();

        return [
            'posts' => $posts,
            'quotes' => $market->quotes($symbols),
            'counts' => InstagramPost::whereNotNull('sentiment')->selectRaw('sentiment, COUNT(*) as total')->groupBy('sentiment')->pluck('total', 'sentiment'),
            'total' => InstagramPost::count(),
            'profile' => (string) config('services.instagram.profile'),
            'configured' => InstagramFeed::isConfigured(),
            'push' => (bool) $this->portfolioSettings()->instagram_push,
            'watched' => $this->watchlist()->pluck('symbol')->flip(),
        ];
    }
}; ?>

@php
    $sentiments = [
        'bullish' => ['📈 '.__('Bullish'), 'bg-emerald-50 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200'],
        'bearish' => ['📉 '.__('Bearish'), 'bg-orange-50 text-orange-800 dark:bg-orange-900/40 dark:text-orange-200'],
        'neutral' => ['➖ '.__('Neutral'), 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'],
        'mixed' => ['↕ '.__('Mixed'), 'bg-sky-50 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200'],
    ];
    $money = fn ($value, $currency = 'EUR') => number_format((float) $value, 2, ',', '.').' '.$currency;
@endphp

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => 'portfolio.instagram'])

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">📸 {{ __('Instagram analysis of @:profile', ['profile' => $profile]) }}</h3>
                        <p class="mt-1 max-w-2xl text-sm text-gray-600 dark:text-gray-400">{{ __('New posts are fetched every 6 hours and read by the AI: which stock, entry price, stop loss and tranches. The current price shows how far away the marks are.') }} {{ __('No investment advice.') }}</p>
                    </div>
                    <button type="button" wire:click="togglePush" @class(['rounded-lg px-3 py-2 text-sm ring-1', 'bg-indigo-600 text-white ring-indigo-600' => $push, 'text-gray-700 ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-gray-700' => ! $push])>🔔 {{ $push ? __('Push for new ideas: on') : __('Push for new ideas: off') }}</button>
                </div>
                @unless ($configured)
                    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100">{{ __('Fetching needs an Apify token (APIFY_API_KEY). Videos can also be pushed from another machine to /api/instagram/push.') }}</p>
                @endunless
                <div class="mt-4 flex flex-wrap items-center gap-1.5">
                    <button type="button" wire:click="$set('sentiment', '')" @class(['rounded-full px-3 py-1 text-sm', 'bg-indigo-600 text-white dark:bg-indigo-500' => $sentiment === '', 'ring-1 ring-gray-200 text-gray-700 hover:bg-gray-50 dark:ring-gray-700 dark:text-gray-300' => $sentiment !== ''])>{{ __('All') }} ({{ $total }})</button>
                    @foreach ($sentiments as $key => [$label, $classes])
                        <button type="button" wire:click="$set('sentiment', '{{ $key }}')" @class(['rounded-full px-3 py-1 text-sm', 'bg-indigo-600 text-white dark:bg-indigo-500' => $sentiment === $key, 'ring-1 ring-gray-200 text-gray-700 hover:bg-gray-50 dark:ring-gray-700 dark:text-gray-300' => $sentiment !== $key])>{{ $label }} ({{ $counts[$key] ?? 0 }})</button>
                    @endforeach
                    <label class="ms-2 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="checkbox" wire:model.live="ideasOnly" class="rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">{{ __('Only posts with entry price') }}</label>
                </div>
            </section>

            @if ($posts->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center text-sm text-gray-600 shadow-sm dark:bg-gray-800 dark:text-gray-400">
                    <p class="text-4xl" aria-hidden="true">📸</p>
                    <p class="mt-2">{{ __('No posts yet.') }}</p>
                </div>
            @else
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($posts as $post)
                        @php
                            $ticker = $post->mainTicker();
                            $quote = $ticker ? ($quotes[$ticker] ?? null) : null;
                            $price = $quote?->last_price;
                            $distance = $price && $post->entry_price ? ($price / $post->entry_price - 1) * 100 : null;
                        @endphp
                        <article class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-gray-800" wire:key="post-{{ $post->id }}">
                            <div class="flex gap-3 p-4">
                                @if ($post->thumbnail_url)
                                    <a href="{{ $post->post_url }}" target="_blank" rel="noopener noreferrer" class="shrink-0"><img src="{{ $post->thumbnail_url }}" alt="" loading="lazy" referrerpolicy="no-referrer" class="h-20 w-20 rounded-lg bg-gray-100 object-cover dark:bg-gray-700"></a>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                        <span>{{ $post->published_at?->format('d.m.Y') }}</span>
                                        @if ($post->sentiment)
                                            <span class="rounded-full px-2 py-0.5 {{ $sentiments[$post->sentiment][1] }}">{{ $sentiments[$post->sentiment][0] }}</span>
                                        @endif
                                        @if ($post->duration_sec)
                                            <span>▶ {{ gmdate('i:s', $post->duration_sec) }}</span>
                                        @endif
                                    </div>
                                    @if ($post->stock_name || $ticker)
                                        <p class="mt-1 font-semibold text-gray-900 dark:text-gray-100">
                                            @if ($ticker)
                                                <a href="{{ route('portfolio.security', ['symbol' => $ticker]) }}" wire:navigate class="hover:underline">{{ $post->stock_name ?: $ticker }}</a> <span class="text-xs font-normal text-gray-500">{{ $ticker }}</span>
                                            @else
                                                {{ $post->stock_name }}
                                            @endif
                                        </p>
                                    @endif
                                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $post->summary ?: mb_strimwidth((string) $post->caption, 0, 220, '…') }}</p>
                                </div>
                            </div>

                            @if ($post->entry_price || $post->stop_loss || $post->tranches)
                                <dl class="grid grid-cols-3 gap-2 border-t border-gray-100 px-4 py-3 text-xs dark:border-gray-700">
                                    <div><dt class="text-gray-500 dark:text-gray-400">{{ $post->entry_type ?: __('Entry') }}</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ $post->entry_price ? $money($post->entry_price, $post->currency) : '—' }}</dd></div>
                                    <div><dt class="text-gray-500 dark:text-gray-400">{{ __('Stop loss') }}</dt><dd class="tabular-nums text-gray-900 dark:text-gray-100">{{ $post->stop_loss ? $money($post->stop_loss, $post->currency) : '—' }}</dd></div>
                                    <div>
                                        <dt class="text-gray-500 dark:text-gray-400">{{ __('Now') }}</dt>
                                        <dd class="tabular-nums text-gray-900 dark:text-gray-100">
                                            {{ $price ? $money($price, $quote->currency) : '—' }}
                                            @if ($distance !== null)
                                                <span @class(['block', 'text-emerald-700 dark:text-emerald-400' => $distance <= 0, 'text-gray-500 dark:text-gray-400' => $distance > 0])>{{ $distance <= 0 ? __('in the buy zone') : __(':value % above entry', ['value' => number_format($distance, 1, ',', '.')]) }}</span>
                                            @endif
                                        </dd>
                                    </div>
                                    @if ($post->tranches)
                                        <div class="col-span-3"><dt class="inline text-gray-500 dark:text-gray-400">{{ __('Tranches') }}:</dt> <dd class="inline tabular-nums text-gray-900 dark:text-gray-100">{{ collect($post->tranches)->map(fn ($tranche) => $money($tranche, $post->currency))->implode(' · ') }}</dd></div>
                                    @endif
                                </dl>
                            @endif

                            @if ($post->key_points)
                                <ul class="space-y-0.5 border-t border-gray-100 px-4 py-3 text-xs text-gray-600 dark:border-gray-700 dark:text-gray-300">
                                    @foreach ($post->key_points as $point)
                                        <li>• {{ $point }}</li>
                                    @endforeach
                                </ul>
                            @endif

                            <div class="mt-auto flex flex-wrap gap-2 border-t border-gray-100 px-4 py-2 dark:border-gray-700">
                                <a href="{{ $post->post_url }}" target="_blank" rel="noopener noreferrer" class="rounded-lg px-2 py-1 text-xs text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">↗ {{ __('Open on Instagram') }}</a>
                                @if ($ticker)
                                    @if ($watched->has($ticker))
                                        <span class="rounded-lg px-2 py-1 text-xs text-gray-400">★ {{ __('On the watchlist') }}</span>
                                    @else
                                        <button type="button" wire:click="watch({{ $post->id }})" class="rounded-lg px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-gray-700">☆ {{ __('Add to watchlist') }}</button>
                                    @endif
                                    @if ($post->entry_price || $post->tranches)
                                        <button type="button" wire:click="alerts({{ $post->id }})" class="rounded-lg px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-gray-700">🔔 {{ __('Alert at entry') }}</button>
                                    @endif
                                    <button type="button" wire:click="createTask(@js($ticker), @js(__('Check idea: :name', ['name' => $post->stock_name ?: $ticker])))" class="rounded-lg px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700">✅ {{ __('Task') }}</button>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
                {{ $posts->links() }}
            @endif
        </div>
    </div>
</div>
