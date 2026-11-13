<?php

namespace App\Models;

use App\Support\CardCode;
use App\Support\CardImages;
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
 * @property ?string $front_image_path
 * @property ?string $back_image_path
 * @property ?string $link_url
 * @property ?array<int, array<string, mixed>> $details
 * @property int $position
 */
#[Fillable(['household_id', 'created_by', 'visibility', 'name', 'code', 'code_format', 'color', 'notes', 'front_image_path', 'back_image_path', 'link_url', 'details', 'position'])]
class LoyaltyCard extends Model
{
    /** @use HasFactory<LoyaltyCardFactory> */
    use HasFactory;

    /** Disk for card photos (served through LoyaltyCardImageController, which checks access). */
    public const IMAGE_DISK = 'local';

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (LoyaltyCard $card) {
            CardImages::delete($card->front_image_path);
            CardImages::delete($card->back_image_path);
        });
    }

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

    public function frontImageUrl(): ?string
    {
        return $this->front_image_path ? route('loyalty-cards.image', ['card' => $this->id, 'side' => 'front', 'v' => substr(md5($this->front_image_path), 0, 8)]) : null;
    }

    public function backImageUrl(): ?string
    {
        return $this->back_image_path ? route('loyalty-cards.image', ['card' => $this->id, 'side' => 'back', 'v' => substr(md5($this->back_image_path), 0, 8)]) : null;
    }

    /**
     * The account link, only when it is a safe web address (http/https); other schemes are shown as
     * plain text, never as a clickable link.
     */
    public function safeLinkUrl(): ?string
    {
        return is_string($this->link_url) && preg_match('#^https?://#i', $this->link_url) ? $this->link_url : null;
    }

    /**
     * The labelled detail rows with both a label and a value, cleaned up for display.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function detailRows(): array
    {
        return collect(is_array($this->details) ? $this->details : [])
            ->map(fn ($row) => ['label' => trim((string) ($row['label'] ?? '')), 'value' => trim((string) ($row['value'] ?? ''))])
            ->filter(fn (array $row) => $row['label'] !== '' || $row['value'] !== '')
            ->values()
            ->all();
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
