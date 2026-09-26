<?php

namespace App\Livewire\Concerns;

use App\Models\Portfolio;
use App\Models\PortfolioSetting;
use App\Models\WatchlistItem;
use App\Support\Portfolio\MarketData;
use Illuminate\Database\Eloquent\Builder;

/**
 * Portfolios are personal: every query is limited to the signed-in user. Use together with
 * InteractsWithHousehold (for currentUser()).
 */
trait InteractsWithPortfolio
{
    /**
     * @return Builder<Portfolio>
     */
    protected function portfolios(): Builder
    {
        return Portfolio::where('user_id', $this->currentUser()->id);
    }

    protected function findPortfolio(int $portfolioId): Portfolio
    {
        return $this->portfolios()->find($portfolioId) ?? abort(404);
    }

    /**
     * @return Builder<WatchlistItem>
     */
    protected function watchlist(): Builder
    {
        return WatchlistItem::where('user_id', $this->currentUser()->id);
    }

    protected function portfolioSettings(): PortfolioSetting
    {
        return PortfolioSetting::for($this->currentUser());
    }

    /**
     * Security search for the pickers (name, symbol, ISIN or WKN).
     *
     * @return list<array{symbol: string, name: string, exchange: string, type: string}>
     */
    public function searchSecurities(string $query): array
    {
        return app(MarketData::class)->search(mb_substr($query, 0, 64));
    }
}
