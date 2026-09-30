<?php

namespace App\Models;

use App\Models\Concerns\HasShareTokens;
use Database\Factories\TripParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Someone taking part in a group trip; opens their personal page with their own link and replies
 * there (yes / maybe / no).
 *
 * @property int $id
 * @property int $trip_id
 * @property string $name
 * @property ?string $email
 * @property ?string $phone
 * @property string $status
 * @property ?string $personal_info
 * @property ?string $reply
 * @property ?Carbon $replied_at
 * @property-read Trip $trip
 */
#[Fillable(['trip_id', 'name', 'email', 'phone', 'status', 'personal_info', 'reply', 'replied_at', 'share_token', 'position'])]
#[Hidden(['share_token', 'share_token_encrypted'])]
class TripParticipant extends Model
{
    /** @use HasFactory<TripParticipantFactory> */
    use HasFactory, HasShareTokens;

    public const STATUSES = ['invited', 'confirmed', 'maybe', 'declined'];

    protected function casts(): array
    {
        return [
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'personal_info' => 'encrypted',
            'reply' => 'encrypted',
            'replied_at' => 'datetime',
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
     * @return array<string, array{label: string, icon: string}>
     */
    public static function statuses(): array
    {
        return [
            'invited' => ['label' => __('No reply yet'), 'icon' => '✉️'],
            'confirmed' => ['label' => __('Coming'), 'icon' => '✅'],
            'maybe' => ['label' => __('Maybe'), 'icon' => '🤔'],
            'declined' => ['label' => __('Not coming'), 'icon' => '❌'],
        ];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * Phone number in international form for WhatsApp links (German numbers get +49), or null.
     */
    public function whatsappNumber(): ?string
    {
        $digits = preg_replace('/[^0-9+]/', '', (string) $this->phone);

        if ($digits === '' || $digits === null) {
            return null;
        }

        $digits = str_starts_with($digits, '+') ? substr($digits, 1) : (str_starts_with($digits, '00') ? substr($digits, 2) : (str_starts_with($digits, '0') ? '49'.substr($digits, 1) : $digits));

        return strlen($digits) >= 8 ? $digits : null;
    }
}
