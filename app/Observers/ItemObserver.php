<?php

namespace App\Observers;

use App\Events\HouseholdItemsChanged;
use App\Models\Item;
use App\Support\Links;

class ItemObserver
{
    public function created(Item $item): void
    {
        $this->syncLinks($item, true);
        $this->broadcast($item);
    }

    public function updated(Item $item): void
    {
        $this->syncLinks($item, false);
        $this->broadcast($item);
    }

    /**
     * Keeps the links table in step with the text and relation properties.
     */
    private function syncLinks(Item $item, bool $created): void
    {
        if ($created || $item->wasChanged('content')) {
            Links::syncBody($item);
        }

        if ($created || $item->wasChanged('properties')) {
            Links::syncProperties($item);
        }
    }

    /**
     * Subpages are not deleted with their page: they move up one level instead,
     * so deleting a page never silently removes content (e.g. others' private pages).
     */
    public function deleting(Item $item): void
    {
        Item::where('parent_id', $item->id)->update(['parent_id' => $item->parent_id]);
        Links::forget(Links::ITEM, $item->id);
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
