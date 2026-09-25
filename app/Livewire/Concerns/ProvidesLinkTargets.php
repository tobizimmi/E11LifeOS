<?php

namespace App\Livewire\Concerns;

use App\Support\Links;
use Illuminate\Support\Facades\Auth;

/**
 * Search for link targets (pages, tasks, entries, recipes) used by the editor's [[ suggestions
 * and relation properties. Needs InteractsWithHousehold.
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
}
