<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, bool>|null $permissions
 */
#[Fillable(['household_id', 'user_id', 'role', 'permissions', 'joined_at'])]
class HouseholdMember extends Model
{
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'permissions' => 'array',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Owners use every module; other members unless the owner switched it off for them.
     */
    public function mayUse(string $addon): bool
    {
        return $this->isOwner() || ($this->permissions[$addon] ?? true) !== false;
    }
}
