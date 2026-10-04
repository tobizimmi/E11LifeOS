<?php

namespace App\Models;

use Database\Factories\SmartHomeDashboardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dashboard of the Smart Home page the household arranges itself: cards for devices, headings
 * and the energy flow, each with its own display and size. Personal dashboards are only seen by
 * the member who created them.
 *
 * @property-read Collection<int, SmartHomeDashboardCard> $cards
 */
#[Fillable(['household_id', 'created_by', 'personal', 'name', 'icon', 'position'])]
class SmartHomeDashboard extends Model
{
    /** @use HasFactory<SmartHomeDashboardFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'personal' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SmartHomeDashboardCard, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(SmartHomeDashboardCard::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Shared dashboards of the household and the member's own personal ones.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('personal', false)->orWhere('created_by', $user->id));
    }

    /**
     * Personal dashboards are edited by their creator; shared ones by the creator and the household owner.
     */
    public function isEditableBy(User $user, bool $isOwner): bool
    {
        return $this->created_by === $user->id || (! $this->personal && $isOwner);
    }
}
