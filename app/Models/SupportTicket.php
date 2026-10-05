<?php

namespace App\Models;

use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A support request of a member (or an internal bug/feature of an administrator), handled like in
 * a help desk: status, priority, type, assignee, tags, first-response target and milestone.
 *
 * @property list<string>|null $tags
 * @property array<string, string>|null $context
 * @property Carbon|null $first_response_due_at
 * @property Carbon|null $first_response_at
 * @property Carbon|null $solved_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $last_activity_at
 */
#[Fillable(['user_id', 'household_id', 'subject', 'type', 'priority', 'status', 'source', 'area', 'assignee_id', 'roadmap_milestone_id', 'tags', 'context', 'first_response_due_at', 'first_response_at', 'solved_at', 'closed_at', 'last_activity_at', 'requester_unread', 'agent_unread', 'satisfaction', 'satisfaction_comment'])]
class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory;

    public const DISK = 'local';

    public const STATUSES = ['new', 'open', 'pending', 'on_hold', 'solved', 'closed'];

    /** Statuses in which the team still has to do something. */
    public const UNSOLVED = ['new', 'open', 'pending', 'on_hold'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const TYPES = ['question', 'problem', 'bug', 'feature', 'account', 'task'];

    /** Types that are planned in the roadmap. */
    public const ROADMAP_TYPES = ['bug', 'feature', 'task'];

    /** Hours until the first answer, by priority. */
    public const FIRST_RESPONSE_HOURS = ['urgent' => 4, 'high' => 8, 'normal' => 24, 'low' => 72];

    protected static function booted(): void
    {
        static::deleting(function (SupportTicket $ticket) {
            $ticket->messages()->get()->each->delete();
            Storage::disk(self::DISK)->deleteDirectory('support/'.$ticket->id);
        });
    }

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'context' => 'array',
            'first_response_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'solved_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'requester_unread' => 'boolean',
            'agent_unread' => 'boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return ['new' => __('New'), 'open' => __('Open'), 'pending' => __('Pending'), 'on_hold' => __('On hold'), 'solved' => __('Solved'), 'closed' => __('Closed')];
    }

    /**
     * How the requester sees the status (they never see "on hold" or "new").
     */
    public function requesterStatus(): string
    {
        return match ($this->status) {
            'new', 'open', 'on_hold' => __('In progress'),
            'pending' => __('Waiting for your answer'),
            'solved' => __('Solved'),
            default => __('Closed'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function priorityLabels(): array
    {
        return ['low' => __('Low'), 'normal' => __('Normal'), 'high' => __('High'), 'urgent' => __('Urgent')];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    public static function typeLabels(): array
    {
        return [
            'question' => ['label' => __('Question'), 'icon' => '❓'],
            'problem' => ['label' => __('Problem'), 'icon' => '⚠️'],
            'bug' => ['label' => __('Bug'), 'icon' => '🐞'],
            'feature' => ['label' => __('Feature request'), 'icon' => '💡'],
            'account' => ['label' => __('Account & access'), 'icon' => '🔑'],
            'task' => ['label' => __('Roadmap item'), 'icon' => '📌'],
        ];
    }

    /**
     * What members can choose when they write a request (roadmap items are for the team).
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public static function requesterTypeLabels(): array
    {
        return array_diff_key(self::typeLabels(), ['task' => true]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return BelongsTo<RoadmapMilestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(RoadmapMilestone::class, 'roadmap_milestone_id');
    }

    /**
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @param  Builder<SupportTicket>  $query
     */
    public function scopeUnsolved(Builder $query): void
    {
        $query->whereIn('status', self::UNSOLVED);
    }

    /**
     * Still without a first answer after the target time.
     */
    public function isOverdue(): bool
    {
        return $this->first_response_at === null && $this->first_response_due_at !== null && $this->first_response_due_at->isPast() && in_array($this->status, self::UNSOLVED, true);
    }

    public function number(): string
    {
        return '#'.$this->id;
    }
}
