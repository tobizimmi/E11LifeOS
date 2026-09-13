<?php

namespace App\Observers;

use App\Events\HouseholdItemsChanged;
use App\Models\Item;

class ItemObserver
{
    public function created(Item $item): void
    {
        $this->broadcast($item);
    }

    public function updated(Item $item): void
    {
        $this->broadcast($item);
    }

    public function deleted(Item $item): void
    {
        $this->broadcast($item);
    }

    private function broadcast(Item $item): void
    {
        HouseholdItemsChanged::dispatch($item->household_id, $item->type);
    }
}
