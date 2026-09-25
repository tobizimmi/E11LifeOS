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

    /**
     * Subpages are not deleted with their page: they move up one level instead,
     * so deleting a page never silently removes content (e.g. others' private pages).
     */
    public function deleting(Item $item): void
    {
        Item::where('parent_id', $item->id)->update(['parent_id' => $item->parent_id]);
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
