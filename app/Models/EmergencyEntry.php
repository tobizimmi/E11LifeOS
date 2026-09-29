<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the member's emergency folder: a document, directive, contact, digital account
 * or wish – details and the place where the original lies are stored encrypted.
 */
#[Fillable(['user_id', 'section', 'kind', 'title', 'details', 'location', 'expires_on', 'remind_days', 'position'])]
class EmergencyEntry extends Model
{
    public const CUSTOM = 'custom';

    protected function casts(): array
    {
        return [
            'details' => 'encrypted',
            'location' => 'encrypted',
            'expires_on' => 'date',
            'remind_days' => 'integer',
            'position' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->lt(today());
    }

    public function expiresSoon(): bool
    {
        return $this->expires_on !== null && ! $this->isExpired() && $this->expires_on->lte(today()->addDays(max(1, $this->remind_days)));
    }
}
