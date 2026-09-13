<?php

namespace App\Observers;

use App\Events\HouseholdItemsChanged;
use App\Models\ShoppingListEntry;

class ShoppingListEntryObserver
{
    public function created(ShoppingListEntry $entry): void
    {
        $this->broadcast($entry);
    }

    public function updated(ShoppingListEntry $entry): void
    {
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
