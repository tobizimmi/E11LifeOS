<?php

namespace App\Livewire\Concerns;

use App\Models\Item;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;

/**
 * Scopes a Livewire component to the signed-in user's household: resolves the
 * household once on mount, re-renders when a household member changes data
 * (Reverb broadcast), and hands out item queries that respect private items.
 */
trait InteractsWithHousehold
{
    #[Locked]
    public ?int $householdId = null;

    /**
     * Pages that can render without a household (e.g. the dashboard) return false.
     */
    protected function requiresHousehold(): bool
    {
        return true;
    }

    public function mountInteractsWithHousehold(): void
    {
        $this->resolveHousehold();
    }

    /**
     * Livewire runs trait mount hooks after the component's own mount(); a mount()
     * that already needs the household calls this first. Safe to call twice.
     */
    protected function resolveHousehold(): void
    {
        if ($this->householdId === null) {
            $this->householdId = $this->currentUser()->currentHousehold()?->id;
        }

        abort_if($this->householdId === null && $this->requiresHousehold(), 403);
    }

    /**
     * Adds the household broadcast to the component's own $listeners.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        if ($this->householdId === null) {
            return $this->listeners;
        }

        return $this->listeners + ["echo-private:household.{$this->householdId},.items.updated" => '$refresh'];
    }

    protected function currentUser(): User
    {
        return Auth::user();
    }

    /**
     * Items of the given type that the current user is allowed to see.
     *
     * @return Builder<Item>
     */
    protected function visibleItems(string $type): Builder
    {
        return Item::query()
            ->forHousehold((int) $this->householdId)
            ->visibleTo($this->currentUser())
            ->ofType($type)
            // Quick notes live in the hidden "Notes" inbox space and stay out of the normal lists.
            ->where(fn (Builder $query) => $query->whereNull('space_id')
                ->orWhereNotIn('space_id', Space::query()->where('household_id', (int) $this->householdId)->where('system', true)->select('id')));
    }

    /**
     * Tells the user when completing a repeating task moved it to its next date.
     */
    protected function announceReschedule(Item $item): void
    {
        if ($item->rescheduledTo) {
            $this->dispatch('toast', message: __('Repeats – next due date: :date', [
                'date' => Carbon::parse($item->rescheduledTo)->translatedFormat('l, j. F Y'),
            ]));
        }
    }

    protected function findVisibleItem(string $type, int $id): Item
    {
        return $this->visibleItems($type)->findOrFail($id);
    }
}
