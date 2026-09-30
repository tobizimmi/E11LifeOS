<?php

namespace App\Models;

use Database\Factories\LearningFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A lesson learned – from an experience, a mistake, a book or a person – that comes back for
 * review at growing intervals (1, 3, 7, 16 … days) until it is second nature.
 *
 * @property int $id
 * @property int $household_id
 * @property ?int $created_by
 * @property string $visibility
 * @property string $title
 * @property ?string $body
 * @property string $kind
 * @property string $area
 * @property ?string $source
 * @property int $interval_days
 * @property ?Carbon $due_on
 * @property int $review_count
 * @property ?Carbon $last_reviewed_on
 * @property int $applied_count
 * @property ?Carbon $archived_at
 * @property ?Carbon $created_at
 */
#[Fillable(['household_id', 'created_by', 'visibility', 'title', 'body', 'kind', 'area', 'source', 'interval_days', 'due_on', 'review_count', 'last_reviewed_on', 'applied_count', 'archived_at'])]
class Learning extends Model
{
    /** @use HasFactory<LearningFactory> */
    use HasFactory;

    /** Longest gap between two reviews. */
    public const MAX_INTERVAL = 365;

    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'body' => 'encrypted',
            'source' => 'encrypted',
            'due_on' => 'date',
            'last_reviewed_on' => 'date',
            'archived_at' => 'datetime',
            'interval_days' => 'integer',
            'review_count' => 'integer',
            'applied_count' => 'integer',
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function kinds(): array
    {
        return [
            'experience' => ['label' => __('Experience'), 'icon' => '🌱'],
            'mistake' => ['label' => __('Mistake'), 'icon' => '🩹'],
            'success' => ['label' => __('Success'), 'icon' => '🏆'],
            'book' => ['label' => __('Book / course'), 'icon' => '📖'],
            'person' => ['label' => __('Advice from someone'), 'icon' => '🗣️'],
            'principle' => ['label' => __('Principle'), 'icon' => '🧭'],
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function areas(): array
    {
        return [
            'self' => ['label' => __('Personal growth'), 'icon' => '🧠'],
            'work' => ['label' => __('Work & career'), 'icon' => '💼'],
            'money' => ['label' => __('Money'), 'icon' => '💶'],
            'health' => ['label' => __('Health'), 'icon' => '💪'],
            'relationships' => ['label' => __('Relationships'), 'icon' => '❤️'],
            'family' => ['label' => __('Family & parenting'), 'icon' => '👨‍👩‍👧'],
            'home' => ['label' => __('Home & everyday life'), 'icon' => '🏠'],
        ];
    }

    public function isPrivate(): bool
    {
        return $this->visibility === Item::VISIBILITY_PRIVATE;
    }

    /**
     * Shared lessons of the household and the member's private ones.
     *
     * @param  Builder<Learning>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }

    /**
     * Records a review: "again" starts over tomorrow, "good" and "easy" stretch the gap.
     */
    public function review(string $rating, ?Carbon $today = null): void
    {
        $today ??= today();
        $interval = match ($rating) {
            'again' => 1,
            'easy' => (int) ceil(max(4, $this->interval_days * 4)),
            default => (int) ceil(max(3, $this->interval_days * 2.5)),
        };
        $interval = min(self::MAX_INTERVAL, $interval);

        $this->update([
            'interval_days' => $interval,
            'due_on' => $today->copy()->addDays($interval),
            'review_count' => $this->review_count + 1,
            'last_reviewed_on' => $today,
        ]);
    }
}
