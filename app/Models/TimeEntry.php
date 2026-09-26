<?php

namespace App\Models;

use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracked working time, billable to a customer.
 */
#[Fillable(['user_id', 'household_id', 'customer_id', 'description', 'started_at', 'ended_at', 'minutes', 'hourly_rate', 'invoice_id'])]
class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'minutes' => 'integer',
            'hourly_rate' => 'float',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Minutes worked (running timers count up to now).
     */
    public function duration(): int
    {
        return $this->minutes ?? (int) max(0, $this->started_at->diffInMinutes($this->ended_at ?? now()));
    }

    public function amount(): float
    {
        return round($this->duration() / 60 * (float) $this->hourly_rate, 2);
    }
}
