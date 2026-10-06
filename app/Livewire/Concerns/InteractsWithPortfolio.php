<?php

namespace App\Livewire\Concerns;

use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSetting;
use App\Models\PriceAlert;
use App\Models\WatchlistItem;
use App\Support\Finance\BankCsvImporter;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\PortfolioLink;
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
     * Private task about a security (#portfolio, links back to the security page).
     */
    public function createTask(string $symbol, string $title = ''): void
    {
        $task = PortfolioLink::createTask($this->currentUser(), (int) $this->householdId, mb_substr($symbol, 0, 32), mb_substr(trim($title), 0, 200));

        $this->dispatch('toast', message: $task ? __('Task created: :title', ['title' => $task->title]) : __('The task could not be created.'));
    }

    /**
     * One-click profit alerts for a holding: a price alert at each step of PriceAlert::GAIN_STEPS
     * above the average buy price (in the security's own currency). Steps already set up are kept.
     *
     * @return int the number of newly created alerts
     */
    protected function createGainAlerts(PortfolioPosition $position): int
    {
        if ($position->avg_buy_price <= 0) {
            return 0;
        }

        $existing = PriceAlert::where('user_id', $this->currentUser()->id)
            ->where('symbol', $position->symbol)
            ->whereNotNull('gain_percent')
            ->pluck('gain_percent')
            ->all();

        $created = 0;

        foreach (PriceAlert::GAIN_STEPS as $percent) {
            if (in_array($percent, $existing, true)) {
                continue;
            }

            PriceAlert::create([
                'user_id' => $this->currentUser()->id,
                'symbol' => $position->symbol,
                'name' => $position->name ?: $position->symbol,
                'condition' => 'above',
                'target_price' => round($position->avg_buy_price * (1 + $percent / 100), 2),
                'gain_percent' => $percent,
                'currency' => $position->currency ?: 'EUR',
                'active' => true,
            ]);
            $created++;
        }

        return $created;
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

    /**
     * German or English number input ("1.234,56", "12.5") as numeric string for validation;
     * unreadable input is returned unchanged so that validation reports it.
     */
    protected function parseNumber(mixed $value): string
    {
        $value = trim((string) $value);
        $number = $value === '' ? null : BankCsvImporter::parseAmount($value);

        return $number === null ? $value : (string) $number;
    }
}
