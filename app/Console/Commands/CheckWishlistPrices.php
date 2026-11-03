<?php

namespace App\Console\Commands;

use App\Models\WishlistItem;
use App\Support\WishlistPrices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('wishlist:check-prices')]
#[Description('Refresh the online price of open wishes that have a link, at most every few days, and notify on price drops (runs daily via the scheduler)')]
class CheckWishlistPrices extends Command
{
    public function handle(): int
    {
        // Only open wishes with a link that were not checked in the last three days.
        $items = WishlistItem::query()
            ->where('status', 'open')
            ->whereNotNull('url')
            ->where(fn ($query) => $query->whereNull('price_checked_at')->orWhere('price_checked_at', '<', now()->subDays(3)))
            ->with('creator')
            ->limit(200)
            ->get();

        $checked = 0;

        foreach ($items as $item) {
            if (WishlistPrices::refresh($item)) {
                $checked++;
            }
        }

        $this->info("Wishlist prices checked: {$checked} of {$items->count()}");

        return self::SUCCESS;
    }
}
