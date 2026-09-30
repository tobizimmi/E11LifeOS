<?php

namespace App\Models;

use App\Models\Concerns\HasShareTokens;
use App\Models\Concerns\SharedWithGroups;
use App\Support\Life\TripCovers;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property ?string $cover_path
 * @property ?string $color
 */
#[Fillable(['household_id', 'created_by', 'visibility', 'title', 'destination', 'starts_on', 'ends_on', 'budget', 'travelers', 'packing_templates', 'notes', 'cover_path', 'color', 'share_token', 'share_expires_at', 'share_password', 'share_references', 'meeting_point', 'organizer_contact', 'group_info'])]
#[Hidden(['share_token', 'share_token_encrypted', 'share_password'])]
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory, HasShareTokens, SharedWithGroups;

    protected static function booted(): void
    {
        static::deleting(function (Trip $trip): void {
            ExternalLink::where('linkable_type', 'trip')->where('linkable_id', $trip->id)->get()->each->delete();
            TripCovers::delete($trip->cover_path);
        });
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

    /**
     * @return HasMany<TripBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(TripBooking::class)->orderByRaw('starts_at IS NULL')->orderBy('starts_at')->orderBy('id');
    }

    /**
     * Entries of the trip plan: by day (ideas without a day first), then in the chosen order.
     *
     * @return HasMany<TripStop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(TripStop::class)->orderByRaw('day IS NOT NULL')->orderBy('day')->orderBy('position')->orderBy('id');
    }

    /**
     * Header colour of the trip (chosen or derived from its id).
     *
     * @return array{from: string, to: string}
     */
    public function colorScheme(): array
    {
        $schemes = self::colorSchemes();

        return $schemes[$this->color] ?? array_values($schemes)[$this->id % count($schemes)];
    }

    /**
     * @return array<string, array{from: string, to: string}>
     */
    public static function colorSchemes(): array
    {
        return [
            'ocean' => ['from' => '#0ea5e9', 'to' => '#4f46e5'],
            'sunset' => ['from' => '#fb923c', 'to' => '#db2777'],
            'forest' => ['from' => '#10b981', 'to' => '#0f766e'],
            'desert' => ['from' => '#f59e0b', 'to' => '#c2410c'],
            'berry' => ['from' => '#d946ef', 'to' => '#7c3aed'],
            'night' => ['from' => '#475569', 'to' => '#1e1b4b'],
        ];
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path ? route('trips.cover', ['trip' => $this->id, 'v' => substr(md5($this->cover_path), 0, 8)]) : null;
    }

    /**
     * Names of the travellers: participants of a group trip, otherwise the "travellers" text.
     *
     * @return list<string>
     */
    public function travellerNames(): array
    {
        $names = $this->relationLoaded('participants') && $this->participants->isNotEmpty()
            ? $this->participants->pluck('name')->all()
            : preg_split('/\s*(?:,|;|\bund\b|&|\+)\s*/u', (string) $this->travelers, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(array_map('trim', $names ?: [])));
    }

    public function packingItems(): HasMany
    {
        return $this->hasMany(TripPackingItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Shared trips of the household and the member's private ones.
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('household_id', $householdId)
                ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id)))
            ->orWhereIn($this->getQualifiedKeyName(), static::sharedIdsFor($user)));
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
