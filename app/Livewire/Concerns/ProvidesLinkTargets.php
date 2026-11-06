<?php

namespace App\Livewire\Concerns;

use App\Support\Links;
use App\Support\Mentions;
use Illuminate\Support\Facades\Auth;

/**
 * Search for link targets (pages, tasks, entries, recipes) used by the editor's [[ suggestions
 * and relation properties, and household members for the "@" mention autocomplete.
 * Needs InteractsWithHousehold.
 */
trait ProvidesLinkTargets
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function linkTargets(string $query): array
    {
        return Links::search(mb_substr($query, 0, 100), Auth::user(), (int) $this->householdId);
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function mentionTargets(string $query): array
    {
        return Mentions::search(mb_substr($query, 0, 100), (int) $this->householdId);
    }
}
