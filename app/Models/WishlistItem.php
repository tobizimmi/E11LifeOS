<?php

namespace App\Models;

use Database\Factories\WishlistItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something wanted, with the reason, a priority and the price – checked against the cashflow
 * before it is bought.
 *
 * @property int $id
 * @property int $household_id
 * @property ?int $created_by
 * @property string $visibility
 * @property string $title
 * @property ?float $price
 * @property string $priority
 * @property string $category
 * @property ?string $reason
 * @property ?string $url
 * @property string $status
 * @property ?Carbon $wanted_by
 * @property ?int $savings_goal_id
 * @property ?Carbon $decided_at
 * @property ?Carbon $created_at
 */
#[Fillable(['household_id', 'created_by', 'visibility', 'title', 'price', 'priority', 'category', 'reason', 'url', 'status', 'wanted_by', 'savings_goal_id', 'decided_at', 'position'])]
class WishlistItem extends Model
{
    /** @use HasFactory<WishlistItemFactory> */
    use HasFactory;

    public const PRIORITIES = ['need', 'want', 'nice'];

    public const STATUSES = ['open', 'bought', 'dropped'];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'wanted_by' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function priorities(): array
    {
        return [
            'need' => ['label' => __('Needed'), 'icon' => '❗'],
            'want' => ['label' => __('Would really like'), 'icon' => '⭐'],
            'nice' => ['label' => __('Nice to have'), 'icon' => '💭'],
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function categories(): array
    {
        return [
            'tech' => ['label' => __('Tech & gadgets'), 'icon' => '💻'],
            'home' => ['label' => __('Home & furniture'), 'icon' => '🛋️'],
            'clothing' => ['label' => __('Clothing'), 'icon' => '👕'],
            'travel' => ['label' => __('Travel & experiences'), 'icon' => '✈️'],
            'hobby' => ['label' => __('Hobby & sport'), 'icon' => '🚴'],
            'car' => ['label' => __('Car & mobility'), 'icon' => '🚗'],
            'education' => ['label' => __('Education'), 'icon' => '🎓'],
            'gift' => ['label' => __('Gift'), 'icon' => '🎁'],
            'other' => ['label' => __('Other'), 'icon' => '🛍️'],
        ];
    }

    /**
     * @return BelongsTo<SavingsGoal, $this>
     */
    public function savingsGoal(): BelongsTo
    {
        return $this->belongsTo(SavingsGoal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPrivate(): bool
    {
        return $this->visibility === Item::VISIBILITY_PRIVATE;
    }

    /**
     * Shared wishes of the household and the member's private ones.
     *
     * @param  Builder<WishlistItem>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }
}
