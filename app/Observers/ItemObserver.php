<?php

namespace App\Observers;

use App\Events\HouseholdItemsChanged;
use App\Models\Item;
use App\Models\User;
use App\Support\Links;
use App\Support\WebPush;
use Illuminate\Support\Facades\Auth;

class ItemObserver
{
    public function created(Item $item): void
    {
        $this->syncLinks($item, true);
        $this->notifyNewAssignee($item, true);
        $this->broadcast($item);
    }

    public function updated(Item $item): void
    {
        $this->syncLinks($item, false);
        $this->notifyNewAssignee($item, false);
        $this->broadcast($item);
    }

    /**
     * Push to the person a task was just assigned to (not when you assign it to yourself).
     */
    private function notifyNewAssignee(Item $item, bool $created): void
    {
        $property = $item->type === Item::TYPE_TODO ? $item->collection?->systemProperty('assignee') : null;

        if (! $property || $item->done || $item->isPrivate()) {
            return;
        }

        $assigneeId = (int) $item->propertyValue($property);
        $previousId = $created ? 0 : (int) (($item->getOriginal('properties') ?? [])[$property->id] ?? 0);

        if ($assigneeId === 0 || $assigneeId === $previousId || $assigneeId === Auth::id()) {
            return;
        }

        $assignee = User::find($assigneeId);

        if ($assignee) {
            app(WebPush::class)->send(
                $assignee,
                '👤 '.__('New task for you'),
                $item->title.(Auth::user() ? ' · '.__('from :name', ['name' => Auth::user()->name]) : ''),
                route('tasks.index', ['task' => $item->id]),
            );
        }
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
