<?php

use App\Models\KnowledgeFavorite;
use App\Support\Knowledge\FinanceNews;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Knowledge area: curated tips on financing, stock market, taxes and wealth, current news
 * and the favourites marked to read again later.
 */
new #[Layout('layouts.app')] class extends Component
{
    /** tips, news or favorites */
    #[Url(except: 'tips')]
    public string $tab = 'tips';

    #[Url(except: '')]
    public string $category = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'taxes')]
    public string $topic = 'taxes';

    public function mount(): void
    {
        if (! in_array($this->tab, ['tips', 'news', 'favorites', 'theses'], true)) {
            $this->tab = 'tips';
        }

        if (! isset(FinanceNews::TOPICS[$this->topic])) {
            $this->topic = 'taxes';
        }
    }

    public function toggleTip(string $key): void
    {
        $tip = $this->tips()[$key] ?? null;
        abort_unless($tip, 404);

        $this->toggle(KnowledgeFavorite::TIP, $key, ['title' => $tip['title'], 'summary' => $tip['summary'], 'category' => $tip['category']]);
    }

    public function toggleNews(string $key): void
    {
        $existing = KnowledgeFavorite::where('user_id', Auth::id())->where('kind', KnowledgeFavorite::NEWS)->where('key', $key)->first();

        if ($existing) {
            $existing->delete();

            return;
        }

        $article = collect(app(FinanceNews::class)->for($this->topic))->firstWhere('key', $key);
        abort_unless($article, 404);

        $this->toggle(KnowledgeFavorite::NEWS, $key, [
            'title' => mb_substr($article['title'], 0, 255),
            'url' => mb_substr($article['url'], 0, 2000),
            'summary' => $article['summary'],
            'source' => $article['source'] ? mb_substr($article['source'], 0, 255) : null,
            'category' => $this->topic,
            'published_at' => $article['published_at'],
        ]);
    }

    public function removeFavorite(int $id): void
    {
        KnowledgeFavorite::where('user_id', Auth::id())->whereKey($id)->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function toggle(string $kind, string $key, array $attributes): void
    {
        $existing = KnowledgeFavorite::where('user_id', Auth::id())->where('kind', $kind)->where('key', $key)->first();

        if ($existing) {
            $existing->delete();

            return;
        }

        KnowledgeFavorite::create(['user_id' => Auth::id(), 'kind' => $kind, 'key' => $key] + $attributes);
        $this->dispatch('toast', message: __('Saved to favourites.'));
    }

    /**
     * @return array<string, array{category: string, title: string, summary: string, body: list<string>}>
     */
    private function tips(): array
    {
        $tips = trans('finance-tips.items', [], 'de');

        return is_array($tips) ? $tips : [];
    }

    public function with(): array
    {
        $favorites = KnowledgeFavorite::where('user_id', Auth::id())->latest()->get();
        $tips = collect($this->tips());

        if ($this->category !== '') {
            $tips = $tips->where('category', $this->category);
        }

        if (trim($this->search) !== '') {
            $needle = mb_strtolower(trim($this->search));
            $tips = $tips->filter(fn (array $tip) => str_contains(mb_strtolower($tip['title'].' '.$tip['summary'].' '.implode(' ', $tip['body'])), $needle));
        }

        return [
            'categories' => trans('finance-tips.categories', [], 'de'),
            'tips' => $tips,
            'allTips' => $this->tips(),
            'news' => $this->tab === 'news' ? app(FinanceNews::class)->for($this->topic) : [],
            'topics' => FinanceNews::labels(),
            'favorites' => $favorites,
            'favoriteKeys' => $favorites->map(fn (KnowledgeFavorite $favorite) => $favorite->kind.':'.$favorite->key)->flip()->all(),
            'theses' => \App\Models\InvestmentThesis::where('user_id', Auth::id())->orderByRaw('review_on IS NULL')->orderBy('review_on')->get(),
            'securityNames' => \App\Models\Security::whereIn('symbol', \App\Models\InvestmentThesis::where('user_id', Auth::id())->pluck('symbol'))->pluck('name', 'symbol'),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Money knowledge') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-4xl space-y-5 px-4 sm:px-6 lg:px-8">
            <nav class="flex gap-1 rounded-xl bg-gray-100 p-1 text-sm dark:bg-gray-800" aria-label="{{ __('Sections') }}">
                @foreach (['tips' => '💡 '.__('Tips'), 'news' => '📰 '.__('What\'s new'), 'theses' => '📝 '.__('My theses').' ('.$theses->count().')', 'favorites' => '⭐ '.__('Favourites').' ('.$favorites->count().')'] as $key => $label)
                    <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['flex-1 rounded-lg px-3 py-2 font-medium transition pointer-coarse:min-h-11', 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100' => $tab === $key, 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100' => $tab !== $key]) aria-pressed="{{ $tab === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </nav>

            @if ($tab === 'theses')
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Your investment theses – why you own a security and when you would sell. Write them on the page of each security.') }}</p>
                <ul class="space-y-3">
                    @forelse ($theses as $item)
                        <li wire:key="thesis-{{ $item->id }}" class="rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <a href="{{ route('portfolio.security', $item->symbol) }}" wire:navigate class="font-semibold text-gray-900 hover:underline dark:text-gray-100">{{ $securityNames[$item->symbol] ?? $item->symbol }} <span class="text-sm font-normal text-gray-500">{{ $item->symbol }}</span></a>
                                @if ($item->isDueForReview())
                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">⏰ {{ __('Review due') }}</span>
                                @elseif ($item->review_on)
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('Review on :date', ['date' => $item->review_on->format('d.m.Y')]) }}</span>
                                @endif
                            </div>
                            @if ($item->thesis)
                                <p class="mt-1 line-clamp-3 text-sm text-gray-700 dark:text-gray-300">{{ $item->thesis }}</p>
                            @endif
                            @if ($item->sell_if)
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('I sell if …') }} {{ $item->sell_if }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="rounded-2xl bg-white p-6 text-center text-sm text-gray-500 shadow-sm dark:bg-gray-800 dark:text-gray-400">{{ __('No theses yet. Open a security in the portfolio and write down why you own it.') }}</li>
                    @endforelse
                </ul>
            @endif

            @if ($tab === 'tips')
                <div class="space-y-3">
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search tips…') }}" class="w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <div class="flex flex-wrap gap-2 text-sm">
                        <button type="button" wire:click="$set('category', '')" @class(['rounded-full px-3 py-1.5 pointer-coarse:min-h-11', 'bg-indigo-600 text-white' => $category === '', 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700' => $category !== ''])>{{ __('All') }}</button>
                        @foreach ($categories as $key => $info)
                            <button type="button" wire:click="$set('category', '{{ $key }}')" @class(['rounded-full px-3 py-1.5 pointer-coarse:min-h-11', 'bg-indigo-600 text-white' => $category === $key, 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700' => $category !== $key])>{{ $info['icon'] }} {{ $info['label'] }}</button>
                        @endforeach
                    </div>
                </div>

                <ul class="space-y-3">
                    @forelse ($tips as $key => $tip)
                        @php($isFavorite = isset($favoriteKeys['tip:'.$key]))
                        <li wire:key="tip-{{ $key }}" x-data="{ open: false }" class="rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                            <div class="flex items-start gap-3 p-4">
                                <button type="button" @click="open = ! open" class="min-w-0 flex-1 text-left" :aria-expanded="open">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $categories[$tip['category']]['icon'] ?? '' }} {{ $categories[$tip['category']]['label'] ?? '' }}</span>
                                    <span class="mt-0.5 block font-semibold text-gray-900 dark:text-gray-100">{{ $tip['title'] }}</span>
                                    <span class="mt-1 block text-sm text-gray-600 dark:text-gray-300">{{ $tip['summary'] }}</span>
                                </button>
                                <button type="button" wire:click="toggleTip('{{ $key }}')" @class(['rounded-full p-2 transition pointer-coarse:min-h-11 pointer-coarse:min-w-11', 'text-amber-500' => $isFavorite, 'text-gray-400 hover:text-amber-500' => ! $isFavorite]) title="{{ $isFavorite ? __('Remove from favourites') : __('Mark as favourite') }}" aria-pressed="{{ $isFavorite ? 'true' : 'false' }}">
                                    <x-icon name="star" @class(['h-6 w-6', 'fill-current' => $isFavorite]) />
                                    <span class="sr-only">{{ $isFavorite ? __('Remove from favourites') : __('Mark as favourite') }}</span>
                                </button>
                            </div>
                            <div x-show="open" x-transition.opacity x-cloak class="space-y-2 border-t border-gray-100 px-4 py-3 text-sm leading-relaxed text-gray-700 dark:border-gray-700 dark:text-gray-200">
                                @foreach ($tip['body'] as $paragraph)
                                    <p>{{ $paragraph }}</p>
                                @endforeach
                            </div>
                        </li>
                    @empty
                        <li class="rounded-2xl bg-white p-6 text-center text-sm text-gray-500 shadow-sm dark:bg-gray-800 dark:text-gray-400">{{ __('No tips match your search.') }}</li>
                    @endforelse
                </ul>
            @elseif ($tab === 'news')
                <div class="flex flex-wrap gap-2 text-sm">
                    @foreach ($topics as $key => $label)
                        <button type="button" wire:click="$set('topic', '{{ $key }}')" @class(['rounded-full px-3 py-1.5 pointer-coarse:min-h-11', 'bg-indigo-600 text-white' => $topic === $key, 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700' => $topic !== $key])>{{ \App\Support\Knowledge\FinanceNews::TOPICS[$key]['icon'] }} {{ $label }}</button>
                    @endforeach
                </div>

                <ul class="space-y-3" wire:loading.class="opacity-60">
                    @forelse ($news as $article)
                        @php($isFavorite = isset($favoriteKeys['news:'.$article['key']]))
                        <li wire:key="news-{{ $article['key'] }}" class="flex items-start gap-3 rounded-2xl bg-white p-4 shadow-sm dark:bg-gray-800">
                            <div class="min-w-0 flex-1">
                                <a href="{{ $article['url'] }}" target="_blank" rel="noopener noreferrer" class="font-medium text-gray-900 hover:underline dark:text-gray-100">{{ $article['title'] }}</a>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $article['source'] ?? 'Google News' }}@if ($article['published_at']) · {{ $article['published_at']->diffForHumans() }}@endif</p>
                            </div>
                            <button type="button" wire:click="toggleNews('{{ $article['key'] }}')" @class(['rounded-full p-2 transition pointer-coarse:min-h-11 pointer-coarse:min-w-11', 'text-amber-500' => $isFavorite, 'text-gray-400 hover:text-amber-500' => ! $isFavorite]) title="{{ $isFavorite ? __('Remove from favourites') : __('Mark as favourite') }}" aria-pressed="{{ $isFavorite ? 'true' : 'false' }}">
                                <x-icon name="star" @class(['h-6 w-6', 'fill-current' => $isFavorite]) />
                                <span class="sr-only">{{ $isFavorite ? __('Remove from favourites') : __('Mark as favourite') }}</span>
                            </button>
                        </li>
                    @empty
                        <li class="rounded-2xl bg-white p-6 text-center text-sm text-gray-500 shadow-sm dark:bg-gray-800 dark:text-gray-400">{{ __('No news could be loaded right now. Please try again later.') }}</li>
                    @endforelse
                </ul>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Headlines from Google News of the last 30 days, updated every few hours.') }}</p>
            @else
                <ul class="space-y-3">
                    @forelse ($favorites as $favorite)
                        <li wire:key="favorite-{{ $favorite->id }}" x-data="{ open: false }" class="rounded-2xl bg-white shadow-sm dark:bg-gray-800">
                            <div class="flex items-start gap-3 p-4">
                                <div class="min-w-0 flex-1">
                                    @if ($favorite->kind === \App\Models\KnowledgeFavorite::NEWS)
                                        <span class="text-xs text-gray-500 dark:text-gray-400">📰 {{ $topics[$favorite->category] ?? __('News') }} · {{ $favorite->source ?? 'Google News' }}@if ($favorite->published_at) · {{ $favorite->published_at->format('d.m.Y') }}@endif</span>
                                        <a href="{{ $favorite->url }}" target="_blank" rel="noopener noreferrer" class="mt-0.5 block font-semibold text-gray-900 hover:underline dark:text-gray-100">{{ $favorite->title }}</a>
                                    @else
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $categories[$favorite->category]['icon'] ?? '💡' }} {{ $categories[$favorite->category]['label'] ?? __('Tip') }}</span>
                                        <button type="button" @click="open = ! open" class="mt-0.5 block text-left font-semibold text-gray-900 dark:text-gray-100" :aria-expanded="open">{{ $allTips[$favorite->key]['title'] ?? $favorite->title }}</button>
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $allTips[$favorite->key]['summary'] ?? $favorite->summary }}</p>
                                    @endif
                                </div>
                                <button type="button" wire:click="removeFavorite({{ $favorite->id }})" class="rounded-full p-2 text-amber-500 transition hover:text-gray-400 pointer-coarse:min-h-11 pointer-coarse:min-w-11" title="{{ __('Remove from favourites') }}">
                                    <x-icon name="star" class="h-6 w-6 fill-current" />
                                    <span class="sr-only">{{ __('Remove from favourites') }}</span>
                                </button>
                            </div>
                            @if ($favorite->kind === \App\Models\KnowledgeFavorite::TIP && isset($allTips[$favorite->key]))
                                <div x-show="open" x-transition.opacity x-cloak class="space-y-2 border-t border-gray-100 px-4 py-3 text-sm leading-relaxed text-gray-700 dark:border-gray-700 dark:text-gray-200">
                                    @foreach ($allTips[$favorite->key]['body'] as $paragraph)
                                        <p>{{ $paragraph }}</p>
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @empty
                        <li class="rounded-2xl bg-white p-6 text-center shadow-sm dark:bg-gray-800">
                            <p class="text-gray-700 dark:text-gray-200">{{ __('No favourites yet.') }}</p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Tap the star on a tip or a news article to find it here again later.') }}</p>
                        </li>
                    @endforelse
                </ul>
            @endif

            <p class="text-xs text-gray-500 dark:text-gray-400">{{ trans('finance-tips.disclaimer', [], 'de') }}</p>
        </div>
    </div>
</div>
