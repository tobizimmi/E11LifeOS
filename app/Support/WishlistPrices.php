<?php

namespace App\Support;

use App\Console\Commands\CheckWishlistPrices;
use App\Models\WishlistItem;

/**
 * Refreshes the tracked price of a wish from its saved link and records a notification when the
 * price drops. Used by the scheduled {@see CheckWishlistPrices} and by the
 * "check now" button on the wishlist page.
 */
class WishlistPrices
{
    /**
     * Looks up the current price for a wish and stores it. Returns true when a price was found.
     * When $notify is set, a notification is recorded for the creator if the price dropped.
     */
    public static function refresh(WishlistItem $item, bool $notify = true): bool
    {
        if (! $item->url) {
            return false;
        }

        $previous = $item->tracked_price;
        $found = PriceFinder::fetch($item->url);

        if ($found === null) {
            $item->forceFill(['price_checked_at' => now(), 'price_check_status' => 'failed'])->save();

            return false;
        }

        $price = $found['price'];
        $lowest = $item->lowest_price !== null ? min($item->lowest_price, $price) : $price;

        $item->forceFill([
            'tracked_price' => $price,
            'tracked_currency' => $found['currency'],
            'lowest_price' => $lowest,
            'price_checked_at' => now(),
            'price_check_status' => 'ok',
        ])->save();

        if ($notify && $item->creator && self::isDrop($previous, $price, $item->price)) {
            InAppNotifications::record(
                $item->creator,
                __('Price dropped: :title', ['title' => $item->title]),
                __('Now :price (saved at :saved).', [
                    'price' => self::euro($price, $found['currency']),
                    'saved' => $item->price !== null ? self::euro($item->price, $found['currency']) : '–',
                ]),
                $item->url,
            );
        }

        return true;
    }

    /**
     * A notable drop: the new price undercuts the previously tracked price, or the saved price, by
     * at least 1 % (so tiny fluctuations do not notify).
     */
    private static function isDrop(?float $previous, float $price, ?float $saved): bool
    {
        if ($previous !== null && $price < $previous * 0.99) {
            return true;
        }

        return $previous === null && $saved !== null && $price < $saved * 0.99;
    }

    private static function euro(float $value, ?string $currency): string
    {
        return number_format($value, 2, ',', '.').' '.($currency ?: '€');
    }
}
