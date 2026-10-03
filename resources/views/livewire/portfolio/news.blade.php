<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\InteractsWithPortfolio;
use App\Models\NewsArticle;
use App\Models\PortfolioPosition;
use App\Models\Security;
use App\Support\Portfolio\NewsFeed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use InteractsWithPortfolio;
    use WithPagination;

    /** mine = held and watched securities, market = general news, all */
    #[Url(except: 'mine')]
    public string $filter = 'mine';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['filter', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function fetchNow(NewsFeed $feed): void
    {
        $count = 0;

        foreach (Security::whereIn('symbol', $this->ownSymbols())->where('symbol', 'not like', '^%')->limit(15)->get() as $security) {
            $count += $feed->fetchForSecurity($security);
        }

        $this->dispatch('toast', message: trans_choice(':count new article|:count new articles', $count));
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function ownSymbols()
    {
        return PortfolioPosition::whereIn('portfolio_id', $this->portfolios()->select('id'))->pluck('symbol')
            ->merge($this->watchlist()->pluck('symbol'))->unique()->values();
    }

    public function with(): array
    {
        $symbols = $this->ownSymbols();
        $term = mb_strtolower(trim($this->search));

        return [
            'articles' => NewsArticle::query()
                ->when($this->filter === 'mine', fn ($query) => $query->whereIn('symbol', $symbols))
                ->when($this->filter === 'market', fn ($query) => $query->whereNull('symbol'))
                ->when($this->filter === 'all', fn ($query) => $query->where(fn ($query) => $query->whereNull('symbol')->orWhereIn('symbol', $symbols)))
                ->when($term !== '', fn ($query) => $query->whereRaw('LOWER(title) LIKE ?', ['%'.$term.'%']))
                ->orderByDesc('published_at')->paginate(30),
            'names' => Security::whereIn('symbol', $symbols)->pluck('name', 'symbol'),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ __('Portfolio') }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">🔒 {{ __('Only you can see your portfolios.') }}</span>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
            @include('portfolio.partials.nav', ['active' => 'portfolio.news'])

            <div class="flex flex-wrap items-center gap-2">
                <div class="inline-flex rounded-lg bg-white p-0.5 text-sm shadow-xs ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    @foreach (['mine' => __('My securities'), 'market' => __('Market'), 'all' => __('All')] as $key => $label)
                        <button type="button" wire:click="$set('filter', '{{ $key }}')" @class(['rounded-md px-3 py-1.5', 'bg-indigo-600 text-white' => $filter === $key, 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $filter !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search headlines …') }}" aria-label="{{ __('Search') }}" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 sm:w-64">
                <button type="button" wire:click="fetchNow" wire:loading.attr="disabled" class="ms-auto rounded-lg px-3 py-2 text-sm text-gray-600 hover:bg-white dark:text-gray-300 dark:hover:bg-gray-800">↻ {{ __('Fetch news') }}</button>
            </div>

            @if ($articles->isEmpty())
                <div class="rounded-2xl bg-white p-8 text-center text-sm text-gray-600 shadow-xs dark:bg-gray-800 dark:text-gray-400">
                    <p class="text-4xl" aria-hidden="true">📰</p>
                    <p class="mt-2">{{ __('No news yet. Headlines are fetched every hour for your securities and the market.') }}</p>
                </div>
            @else
                <ul class="divide-y divide-gray-100 overflow-hidden rounded-2xl bg-white shadow-xs dark:divide-gray-700 dark:bg-gray-800">
                    @foreach ($articles as $article)
                        <li wire:key="news-{{ $article->id }}">
                            <a href="{{ $article->url }}" target="_blank" rel="noopener noreferrer" class="flex gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $article->title }}</span>
                                    @if ($article->summary)
                                        <span class="mt-0.5 line-clamp-2 block text-xs text-gray-600 dark:text-gray-300">{{ $article->summary }}</span>
                                    @endif
                                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                                        @if ($article->symbol)
                                            <span class="rounded-sm bg-indigo-50 px-1 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200">{{ $names[$article->symbol] ?? $article->symbol }}</span> ·
                                        @endif
                                        {{ $article->source }} · {{ $article->published_at?->diffForHumans() }}
                                    </span>
                                </span>
                                <span class="shrink-0 self-center text-gray-400" aria-hidden="true">↗</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                {{ $articles->links() }}
            @endif
        </div>
    </div>
</div>
