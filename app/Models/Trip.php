<?php

namespace App\Models;

use App\Models\Concerns\HasShareTokens;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A holiday or business trip of the household with bookings, costs and a packing list; group trips
 * also have participants and a password-protected trip folder for external people.
 *
 * @property int $id
 * @property int $household_id
 * @property string $title
 * @property ?string $destination
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property ?Carbon $share_expires_at
 * @property ?string $share_password
 * @property bool $share_references
 * @property ?string $meeting_point
 * @property ?string $organizer_contact
 * @property ?string $group_info
 */
#[Fillable(['household_id', 'created_by', 'visibility', 'title', 'destination', 'starts_on', 'ends_on', 'budget', 'travelers', 'packing_templates', 'notes', 'share_token', 'share_expires_at', 'share_password', 'share_references', 'meeting_point', 'organizer_contact', 'group_info'])]
#[Hidden(['share_token', 'share_token_encrypted', 'share_password'])]
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory, HasShareTokens;

    protected static function booted(): void
    {
        static::deleting(fn (Trip $trip) => ExternalLink::where('linkable_type', 'trip')->where('linkable_id', $trip->id)->get()->each->delete());
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'budget' => 'float',
            'packing_templates' => 'array',
            'notes' => 'encrypted',
            'share_expires_at' => 'datetime',
            'share_password' => 'hashed',
            'share_references' => 'boolean',
            'group_info' => 'encrypted',
        ];
    }

    /**
     * @return list<string>
     */
    protected function shareTokenAttributes(): array
    {
        return ['share_token'];
    }

    /**
     * @return HasMany<TripParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(TripParticipant::class)->orderBy('position')->orderBy('id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(TripBooking::class)->orderByRaw('starts_at IS NULL')->orderBy('starts_at')->orderBy('id');
    }

    public function packingItems(): HasMany
    {
        return $this->hasMany(TripPackingItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Shared trips of the household and the member's private ones.
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }

    public function nights(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on);
    }

    public function isPast(): bool
    {
        return $this->ends_on->lt(today());
    }

    public function isOngoing(): bool
    {
        return ! $this->starts_on->gt(today()) && ! $this->isPast();
    }
}
