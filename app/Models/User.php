<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Addons;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'email', 'password', 'reminder_emails', 'weekly_digest', 'weekly_digest_day', 'weekly_digest_hour', 'morning_briefing', 'morning_briefing_hour', 'weather_place', 'weather_latitude', 'weather_longitude'])]
#[Hidden(['password', 'remember_token', 'calendar_token'])]
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
            // Files of private pages and unsaved uploads first (the query delete below skips model events).
            Attachment::where('created_by', $user->id)
                ->where(fn ($query) => $query->whereNull('item_id')->orWhereIn('item_id', Item::where('created_by', $user->id)->where('visibility', Item::VISIBILITY_PRIVATE)->select('id')))
                ->get()
                ->each->delete();

            Item::where('created_by', $user->id)
                ->where('visibility', Item::VISIBILITY_PRIVATE)
                ->delete();

            // Private recipes too (one by one, so their photos are removed as well).
            Recipe::where('created_by', $user->id)
                ->where('visibility', Item::VISIBILITY_PRIVATE)
                ->get()
                ->each->delete();

            MealPlan::where('created_by', $user->id)
                ->where('visibility', Item::VISIBILITY_PRIVATE)
                ->delete();

            // Private collections go too (their entries are removed by the foreign key cascade).
            Collection::where('created_by', $user->id)
                ->where('visibility', Item::VISIBILITY_PRIVATE)
                ->delete();

            // Business receipts are personal files; their rows go with the foreign key cascade.
            Storage::disk(BookkeepingEntry::RECEIPT_DISK)->deleteDirectory('business-receipts/'.$user->id);
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
            'reminder_emails' => 'boolean',
            'dashboard_widgets' => 'array',
            'is_admin' => 'boolean',
            'weekly_digest_day' => 'integer',
            'weekly_digest_hour' => 'integer',
            'weekly_digest_sent_at' => 'datetime',
            'morning_briefing_hour' => 'integer',
            'morning_briefing_sent_on' => 'date',
            'weather_latitude' => 'float',
            'weather_longitude' => 'float',
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

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function portfolios(): HasMany
    {
        return $this->hasMany(Portfolio::class)->orderBy('id');
    }

    /**
     * The module is on for the household and allowed for this member.
     */
    public function canUseAddon(string $addon): bool
    {
        return Addons::allowed($this, $addon);
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function currentHousehold(): ?Household
    {
        return $this->households()->first();
    }
}
