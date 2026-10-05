<?php

namespace App\Models;

use App\Models\Concerns\HasShareTokens;
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
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;

/**
 * @property array{pinned?: list<string>, hidden?: list<string>, board?: int|null}|null $nav_preferences
 * @property array<int, mixed>|null $dashboard_widgets
 */
#[Fillable(['name', 'email', 'password', 'reminder_emails', 'weekly_digest', 'weekly_digest_day', 'weekly_digest_hour', 'morning_briefing', 'morning_briefing_hour', 'weather_place', 'weather_latitude', 'weather_longitude', 'tax_rate', 'tax_children', 'postal_address', 'insurance_answers', 'retirement_plan', 'cashflow_manual_only', 'cashflow_categories', 'career_profile', 'dismissed_subscriptions', 'bank_alerts', 'calendar_feed_options', 'push_quiet_from', 'push_quiet_until', 'push_bundle', 'pushover_all'])]
#[Hidden(['calendar_token_encrypted', 'password', 'remember_token', 'calendar_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements HasPasskeys
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasShareTokens, InteractsWithPasskeys, Notifiable;

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

            $privateItems = Item::where('created_by', $user->id)->where('visibility', Item::VISIBILITY_PRIVATE);
            ExternalLink::where('linkable_type', 'item')->whereIn('linkable_id', (clone $privateItems)->select('id'))->get()->each->delete();
            $privateItems->delete();

            // Private properties with their invoices, receipts and document links.
            RealEstate::where('created_by', $user->id)
                ->where('visibility', Item::VISIBILITY_PRIVATE)
                ->get()
                ->each->delete();

            // Mail copies attached to the user's bank bookings (the bookings go with the foreign key cascade).
            ExternalLink::where('linkable_type', 'transaction')->whereIn('linkable_id', Transaction::where('user_id', $user->id)->select('id'))->get()->each->delete();
            ExternalLink::where('linkable_type', 'career_entry')->whereIn('linkable_id', CareerEntry::where('user_id', $user->id)->select('id'))->get()->each->delete();

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

            // Private trips (with their document links), vehicles and people.
            Trip::where('created_by', $user->id)->where('visibility', Item::VISIBILITY_PRIVATE)->get()->each->delete();
            ExpenseGroup::where('created_by', $user->id)->where('visibility', Item::VISIBILITY_PRIVATE)->delete();
            WishlistItem::where('created_by', $user->id)->where('visibility', Item::VISIBILITY_PRIVATE)->delete();
            Place::where('created_by', $user->id)->where('visibility', Item::VISIBILITY_PRIVATE)->delete();
            Learning::where('created_by', $user->id)->where('visibility', Item::VISIBILITY_PRIVATE)->delete();
            Space::where('created_by', $user->id)->where('visibility', Item::VISIBILITY_PRIVATE)->delete();
            Vehicle::where('created_by', $user->id)->where('visibility', Item::VISIBILITY_PRIVATE)->delete();
            Contact::where('created_by', $user->id)->where('visibility', Item::VISIBILITY_PRIVATE)->delete();

            // Support requests with their files (the rows would go with the foreign key cascade).
            SupportTicket::where('user_id', $user->id)->get()->each->delete();

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
            'push_quiet_from' => 'integer',
            'push_quiet_until' => 'integer',
            'push_bundle' => 'boolean',
            'pushover_all' => 'boolean',
            'dashboard_widgets' => 'array',
            'nav_preferences' => 'array',
            'presence_at' => 'datetime',
            'insurance_answers' => 'array',
            'dismissed_subscriptions' => 'array',
            'bank_alerts' => 'array',
            'calendar_feed_options' => 'array',
            'retirement_plan' => 'array',
            'cashflow_manual_only' => 'boolean',
            'cashflow_categories' => 'array',
            'career_profile' => 'encrypted:array',
            'is_admin' => 'boolean',
            'weekly_digest_day' => 'integer',
            'weekly_digest_hour' => 'integer',
            'weekly_digest_sent_at' => 'datetime',
            'morning_briefing_hour' => 'integer',
            'morning_briefing_sent_on' => 'date',
            'weather_latitude' => 'float',
            'weather_longitude' => 'float',
            'tax_rate' => 'float',
            'tax_children' => 'integer',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'onboarding_dismissed_at' => 'datetime',
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

    /**
     * @return HasMany<PushSubscription, $this>
     */
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

    /**
     * Whether the user can use a paperless-ngx instance: their own or one shared with the household.
     */
    public function hasPaperless(): bool
    {
        return PaperlessInstance::query()->availableTo($this)->exists();
    }

    /**
     * Signing in needs a second step: an authenticator code or a passkey.
     */
    public function hasSecondFactor(): bool
    {
        return $this->hasTwoFactor() || $this->passkeys()->exists();
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    /**
     * @return list<string>
     */
    protected function shareTokenAttributes(): array
    {
        return ['calendar_token'];
    }
}
