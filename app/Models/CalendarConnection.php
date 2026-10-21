<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A connected external calendar (currently Google) for two-way sync. The OAuth tokens are stored
 * encrypted; cached_events holds the last pulled events for the calendar display until the next sync.
 *
 * @property array<int, array{date: string, time: ?string, title: string}>|null $cached_events
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $last_synced_at
 * @property string|null $access_token
 * @property string|null $refresh_token
 */
#[Fillable(['user_id', 'provider', 'google_email', 'calendar_id', 'access_token', 'refresh_token', 'token_expires_at', 'cached_events', 'last_synced_at', 'last_error'])]
class CalendarConnection extends Model
{
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'cached_events' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
