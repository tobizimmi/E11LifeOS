<?php

namespace App\Console\Commands;

use App\Models\PortfolioPosition;
use App\Models\PriceAlert;
use App\Support\Portfolio\MarketData;
use App\Support\Portfolio\Notifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('portfolio:check-alerts')]
#[Description('Notify about triggered price alerts and positions that reached their stop loss or take profit (runs every 15 minutes via the scheduler)')]
class CheckPriceAlerts extends Command
{
    public function handle(MarketData $market, Notifier $notifier): int
    {
        $alerts = PriceAlert::where('active', true)->whereNull('triggered_at')->with('user')->get();
        $positions = PortfolioPosition::where(fn ($query) => $query->whereNotNull('stop_loss_price')->orWhereNotNull('take_profit_price'))->with('portfolio.user')->get();
        $quotes = $market->quotes($alerts->pluck('symbol')->merge($positions->pluck('symbol'))->unique()->values()->all());
        $euro = fn (float $value) => number_format($value, 2, ',', '.').' €';
        $sent = 0;

        foreach ($alerts as $alert) {
            $security = $quotes[$alert->symbol] ?? null;

            if (! $security?->last_price) {
                continue;
            }

            // Alerts are entered in euros unless the currency of the security was chosen.
            $price = $alert->currency === 'EUR' ? $market->toEuro($security->last_price, $security->currency) : $security->last_price;
            $hit = $alert->condition === 'above' ? $price >= $alert->target_price : $price <= $alert->target_price;

            if ($hit) {
                $alert->update(['triggered_at' => now()]);
                $notifier->send(
                    $alert->user,
                    ($alert->condition === 'above' ? '📈 ' : '📉 ').($alert->name ?: $security->name),
                    __(':name is at :price (alert :condition :target)', [
                        'name' => $alert->name ?: $security->name,
                        'price' => number_format($price, 2, ',', '.').' '.$alert->currency,
                        'condition' => $alert->condition === 'above' ? __('above') : __('below'),
                        'target' => number_format($alert->target_price, 2, ',', '.').' '.$alert->currency,
                    ]),
                    route('portfolio.security', ['symbol' => $alert->symbol]),
                    important: true,
                );
                $sent++;
            }
        }

        foreach ($positions as $position) {
            $security = $quotes[$position->symbol] ?? null;

            if (! $security?->last_price) {
                continue;
            }

            $price = $market->toEuro($security->last_price, $security->currency);

            foreach ([['stop_loss_price', 'stop_loss_notified_at', fn ($limit) => $price <= $limit, '⚠️ '.__('Stop loss reached')], ['take_profit_price', 'take_profit_notified_at', fn ($limit) => $price >= $limit, '🎯 '.__('Target price reached')]] as [$limitField, $notifiedField, $crossed, $title]) {
                $limit = $position->{$limitField};

                if (! $limit) {
                    continue;
                }

                if ($crossed($limit) && ! $position->{$notifiedField}) {
                    $position->update([$notifiedField => now()]);
                    $notifier->send($position->portfolio->user, $title, __(':name (:portfolio) is at :price – mark :limit', [
                        'name' => $position->name ?: $position->symbol,
                        'portfolio' => $position->portfolio->name,
                        'price' => $euro($price),
                        'limit' => $euro($limit),
                    ]), route('portfolio.security', ['symbol' => $position->symbol]), important: true);
                    $sent++;
                } elseif (! $crossed($limit) && $position->{$notifiedField}) {
                    // Back on the other side: notify again the next time the mark is crossed.
                    $position->update([$notifiedField => null]);
                }
            }
        }

        $this->info("Notifications sent: {$sent}");

        return self::SUCCESS;
    }
}
