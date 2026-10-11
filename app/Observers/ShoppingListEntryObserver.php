<?php

namespace App\Observers;

use App\Events\HouseholdItemsChanged;
use App\Models\ShoppingListEntry;
use App\Support\ShoppingLists;
use Throwable;

class ShoppingListEntryObserver
{
    public function created(ShoppingListEntry $entry): void
    {
        $this->broadcast($entry);
    }

    public function updated(ShoppingListEntry $entry): void
    {
        // Ticking an item off counts as buying it (for "bought often") – a failure here
        // must never block the actual checkbox toggle.
        if ($entry->wasChanged('checked') && $entry->checked) {
            try {
                ShoppingLists::recordPurchase($entry);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->broadcast($entry);
    }

    public function deleted(ShoppingListEntry $entry): void
    {
        $this->broadcast($entry);
    }

    private function broadcast(ShoppingListEntry $entry): void
    {
        HouseholdItemsChanged::dispatch($entry->household_id, 'shopping');
    }
}
