<?php

namespace App\Models;

use App\Support\Addons;
use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'approved_at', 'mail_address', 'addons', 'offers_zip', 'offers_chains'])]
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'addons' => 'array',
            'offers_chains' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * A household from open self-registration is approved once an administrator lets it in.
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * @param  Builder<Household>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('approved_at');
    }

    public function hasAddon(string $addon): bool
    {
        return Addons::enabled($this, $addon);
    }

    public function setAddon(string $addon, bool $enabled): void
    {
        $this->update(['addons' => array_merge($this->addons ?? [], [$addon => $enabled])]);
    }

    public function shoppingLists(): HasMany
    {
        return $this->hasMany(ShoppingList::class)->orderBy('position')->orderBy('id');
    }

    public function shoppingStores(): HasMany
    {
        return $this->hasMany(ShoppingStore::class)->orderBy('position')->orderBy('id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(Recipe::class);
    }

    /**
     * @return HasMany<HouseholdMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'household_members')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function invites(): HasMany
    {
        return $this->hasMany(HouseholdInvite::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function shoppingListEntries(): HasMany
    {
        return $this->hasMany(ShoppingListEntry::class);
    }
}
