<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Shared items outlive their creator (created_by is nulled), but private ones
     * would become invisible orphans nobody can see or delete — remove them.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user) {
            Item::where('created_by', $user->id)
                ->where('visibility', Item::VISIBILITY_PRIVATE)
                ->delete();
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'household_members')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function householdMemberships(): HasMany
    {
        return $this->hasMany(HouseholdMember::class);
    }

    public function currentHousehold(): ?Household
    {
        return $this->households()->first();
    }
}
