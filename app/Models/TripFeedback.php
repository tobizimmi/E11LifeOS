<?php

namespace App\Models;

use Database\Factories\TripFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A comment on the trip (or one entry of the plan) or a suggestion for the plan. Comes from a
 * household member or, via the trip folder, from a participant or guest; a member with access
 * to the trip approves a suggestion (it becomes an entry) or declines it.
 *
 * @property int $id
 * @property int $trip_id
 * @property ?int $trip_stop_id
 * @property ?int $participant_id
 * @property ?int $user_id
 * @property string $author_name
 * @property string $type
 * @property ?string $body
 * @property ?string $title
 * @property ?string $location
 * @property ?Carbon $day
 * @property ?string $status
 * @property Carbon $created_at
 * @property-read Trip $trip
 * @property-read ?TripStop $stop
 */
#[Table('trip_feedback')]
#[Fillable(['trip_id', 'trip_stop_id', 'participant_id', 'user_id', 'author_name', 'type', 'body', 'title', 'location', 'day', 'status'])]
class TripFeedback extends Model
{
    /** @use HasFactory<TripFeedbackFactory> */
    use HasFactory;

    public const COMMENT = 'comment';

    public const SUGGESTION = 'suggestion';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'day' => 'date',
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
     * @return BelongsTo<TripStop, $this>
     */
    public function stop(): BelongsTo
    {
        return $this->belongsTo(TripStop::class, 'trip_stop_id');
    }

    public function isSuggestion(): bool
    {
        return $this->type === self::SUGGESTION;
    }

    public function isPending(): bool
    {
        return $this->isSuggestion() && $this->status === self::PENDING;
    }
}
