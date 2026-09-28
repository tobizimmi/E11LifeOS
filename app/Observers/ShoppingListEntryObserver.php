<?php

namespace App\Observers;

use App\Events\HouseholdItemsChanged;
use App\Models\ShoppingListEntry;
use App\Support\ShoppingLists;

class ShoppingListEntryObserver
{
    public function created(ShoppingListEntry $entry): void
    {
        $this->broadcast($entry);
    }

    public function updated(ShoppingListEntry $entry): void
    {
        // Ticking an item off counts as buying it (for "bought often").
        if ($entry->wasChanged('checked') && $entry->checked) {
            ShoppingLists::recordPurchase($entry);
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
