<?php

namespace App\Livewire\Concerns;

use App\Models\Tag;
use App\Support\AiException;
use App\Support\BrainAi;
use Illuminate\Support\Facades\RateLimiter;

/**
 * AI tag suggestions for pages and entries. Needs InteractsWithHousehold.
 */
trait SuggestsTags
{
    /**
     * Suggested tags that are not already set; failures are shown as a toast.
     *
     * @param  array<int, string>  $currentTags
     * @return array<int, string>
     */
    protected function tagSuggestionsFor(string $title, string $text, array $currentTags): array
    {
        $limiterKey = 'brain-tags:'.$this->currentUser()->id;

        if (RateLimiter::tooManyAttempts($limiterKey, 60)) {
            $this->dispatch('toast', message: __('Too many requests in a short time. Please try again in a few minutes.'));

            return [];
        }

        RateLimiter::hit($limiterKey, 3600);
        $existingTags = Tag::where('household_id', $this->householdId)->orderBy('name')->pluck('name')->all();

        try {
            $suggestions = app(BrainAi::class)->suggestTags($title, $text, $existingTags);
        } catch (AiException $exception) {
            $this->dispatch('toast', message: $exception->getMessage());

            return [];
        }

        $suggestions = array_values(array_diff($suggestions, array_map(fn (string $tag) => mb_strtolower(trim(ltrim(trim($tag), '#'))), $currentTags)));

        if ($suggestions === []) {
            $this->dispatch('toast', message: __('No further tags to suggest.'));
        }

        return $suggestions;
    }
}
