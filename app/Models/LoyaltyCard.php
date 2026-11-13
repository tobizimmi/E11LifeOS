<?php

namespace App\Models;

use App\Support\CardCode;
use Database\Factories\LoyaltyCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A loyalty / store card (Stocard-style): a name, the code and its symbology, shown as a
 * scannable barcode or QR code. Shared in the household unless marked private.
 *
 * @property int $id
 * @property int $household_id
 * @property ?int $created_by
 * @property string $visibility
 * @property string $name
 * @property string $code
 * @property string $code_format
 * @property ?string $color
 * @property ?string $notes
 * @property int $position
 */
#[Fillable(['household_id', 'created_by', 'visibility', 'name', 'code', 'code_format', 'color', 'notes', 'position'])]
class LoyaltyCard extends Model
{
    /** @use HasFactory<LoyaltyCardFactory> */
    use HasFactory;

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
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
     * Whether the code is drawn as a square 2D code (QR) rather than a 1D barcode.
     */
    public function isTwoDimensional(): bool
    {
        return CardCode::isTwoDimensional($this->code_format);
    }

    /**
     * The card's code rendered as an SVG in its original symbology.
     */
    public function codeSvg(): string
    {
        return CardCode::svg($this->code, $this->code_format);
    }

    public function formatLabel(): string
    {
        return CardCode::label($this->code_format);
    }

    /**
     * Shared cards of the household and the member's private ones.
     *
     * @param  Builder<LoyaltyCard>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }
}
