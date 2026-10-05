<?php

namespace App\Support\Helpdesk;

use App\Models\RoadmapMilestone;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketUpdated;
use App\Support\AppVersion;
use App\Support\Modules\ModuleCatalog;
use App\Support\WebPush;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The course of a support ticket: opening, public replies and internal notes, field changes with
 * their history, first-response target, notifications and closing.
 */
class Tickets
{
    /** Days after which a solved ticket is closed (it can no longer be reopened then). */
    public const CLOSE_AFTER_DAYS = 7;

    /**
     * @param  array{subject: string, body: string, type: string, priority?: string, area?: ?string, url?: ?string, agent?: string}  $data
     * @param  list<UploadedFile>  $files
     */
    public static function open(User $requester, array $data, array $files = [], ?User $createdBy = null): SupportTicket
    {
        $priority = $data['priority'] ?? 'normal';
        $ticket = SupportTicket::create([
            'user_id' => $requester->id,
            'household_id' => $requester->currentHousehold()?->id,
            'subject' => $data['subject'],
            'type' => $data['type'],
            'priority' => $priority,
            'status' => 'new',
            'source' => $createdBy ? 'admin' : 'user',
            'area' => isset($data['area']) && ModuleCatalog::exists((string) $data['area']) ? $data['area'] : null,
            'context' => array_filter([
                'url' => isset($data['url']) && ! str_contains((string) $data['url'], '/livewire/') ? mb_substr((string) $data['url'], 0, 500) : null,
                'version' => AppVersion::label(),
                'agent' => isset($data['agent']) ? mb_substr((string) $data['agent'], 0, 300) : null,
            ]),
            'first_response_due_at' => $createdBy ? null : now()->addHours(SupportTicket::FIRST_RESPONSE_HOURS[$priority] ?? 24),
            'last_activity_at' => now(),
            'agent_unread' => $createdBy === null,
        ]);

        self::addMessage($ticket, $createdBy ?? $requester, SupportMessage::REPLY, $data['body'], $files);

        if (! $createdBy) {
            foreach (self::agents() as $agent) {
                self::push($agent, __('New support ticket :number', ['number' => $ticket->number()]), $requester->name.': '.$ticket->subject, route('admin.support.ticket', $ticket));
            }
        }

        return $ticket;
    }

    /**
     * A roadmap item of the team (no requester to notify), straight into a milestone or the backlog.
     */
    public static function addItem(User $admin, string $title, string $type, string $priority = 'normal', ?int $milestoneId = null, string $description = ''): SupportTicket
    {
        abort_unless(in_array($type, SupportTicket::ROADMAP_TYPES, true) && in_array($priority, SupportTicket::PRIORITIES, true), 422);
        abort_if($milestoneId !== null && ! RoadmapMilestone::whereKey($milestoneId)->exists(), 422);

        $ticket = self::open($admin, ['subject' => $title, 'body' => $description !== '' ? $description : $title, 'type' => $type, 'priority' => $priority], [], $admin);
        $ticket->forceFill(['status' => 'open', 'roadmap_milestone_id' => $milestoneId])->save();

        return $ticket;
    }

    /**
     * A public reply or an internal note. Agents choose the status the ticket gets ("submit as").
     *
     * @param  list<UploadedFile>  $files
     */
    public static function reply(SupportTicket $ticket, User $author, string $body, array $files = [], bool $internal = false, ?string $status = null): SupportMessage
    {
        $isAgent = $author->isAdmin() && ! $author->is($ticket->requester);
        abort_if($ticket->status === 'closed' && ! $isAgent, 422, __('This ticket is closed – please open a new request.'));
        abort_if($internal && ! $isAgent, 403);

        $message = self::addMessage($ticket, $author, $internal ? SupportMessage::NOTE : SupportMessage::REPLY, $body, $files);
        $changes = ['last_activity_at' => now()];

        if ($isAgent) {
            if (! $internal) {
                $changes['requester_unread'] = true;
                $changes['first_response_at'] = $ticket->first_response_at ?? now();
            }

            $changes['agent_unread'] = false;
            $status ??= $internal ? $ticket->status : 'pending';
            $ticket->forceFill($changes)->save();

            if ($status !== $ticket->status) {
                self::change($ticket, $author, ['status' => $status], notify: false);
            }

            if (! $internal) {
                self::notifyRequester($ticket, $message);
            }

            return $message;
        }

        // The requester answered: back to the team (a solved ticket opens again).
        $changes['agent_unread'] = true;
        $changes['requester_unread'] = false;

        if (in_array($ticket->status, ['pending', 'solved', 'on_hold'], true)) {
            $changes['status'] = 'open';
            $changes['solved_at'] = null;
        }

        $ticket->forceFill($changes)->save();

        foreach ($ticket->assignee ? [$ticket->assignee] : self::agents() as $agent) {
            self::push($agent, __('New answer on :number', ['number' => $ticket->number()]), $author->name.': '.Str::limit($body, 120), route('admin.support.ticket', $ticket));
        }

        return $message;
    }

    /**
     * Changes fields of the ticket and writes each change into its history.
     *
     * @param  array<string, mixed>  $fields  status, priority, type, assignee_id, roadmap_milestone_id, tags, area, subject
     */
    public static function change(SupportTicket $ticket, User $agent, array $fields, bool $notify = true): void
    {
        $events = [];

        foreach ($fields as $field => $value) {
            $old = $ticket->{$field};

            if ($field === 'tags') {
                $value = array_values(array_unique(array_filter(array_map(fn ($tag) => Str::slug((string) $tag), (array) $value))));
                $old = $old ?? [];
            }

            if ($old == $value) {
                continue;
            }

            abort_unless(match ($field) {
                'status' => in_array($value, SupportTicket::STATUSES, true),
                'priority' => in_array($value, SupportTicket::PRIORITIES, true),
                'type' => array_key_exists((string) $value, SupportTicket::typeLabels()),
                'assignee_id' => $value === null || User::whereKey($value)->where('is_admin', true)->exists(),
                'roadmap_milestone_id' => $value === null || RoadmapMilestone::whereKey($value)->exists(),
                'area' => $value === null || ModuleCatalog::exists((string) $value),
                'tags' => true,
                'subject' => is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255,
                default => false,
            }, 422);

            $ticket->{$field} = $value;
            $events[] = self::describe($field, $old, $value);
        }

        if ($events === []) {
            return;
        }

        if ($ticket->isDirty('status')) {
            $ticket->solved_at = $ticket->status === 'solved' ? now() : ($ticket->status === 'closed' ? ($ticket->solved_at ?? now()) : null);
            $ticket->closed_at = $ticket->status === 'closed' ? now() : null;
        }

        if ($ticket->isDirty('priority') && $ticket->first_response_at === null && $ticket->source === 'user') {
            $ticket->first_response_due_at = Carbon::parse($ticket->created_at)->addHours(SupportTicket::FIRST_RESPONSE_HOURS[$ticket->priority]);
        }

        $statusChanged = $ticket->isDirty('status');
        $assigneeChanged = $ticket->isDirty('assignee_id');
        $ticket->last_activity_at = now();
        $ticket->save();

        SupportMessage::create(['support_ticket_id' => $ticket->id, 'user_id' => $agent->id, 'kind' => SupportMessage::EVENT, 'body' => implode(' · ', $events)]);

        if ($notify && $statusChanged && $ticket->status === 'solved') {
            $ticket->forceFill(['requester_unread' => true])->save();
            self::notifyRequester($ticket, null);
        }

        if ($assigneeChanged && $ticket->assignee && ! $ticket->assignee->is($agent)) {
            self::push($ticket->assignee, __(':name assigned :number to you', ['name' => $agent->name, 'number' => $ticket->number()]), $ticket->subject, route('admin.support.ticket', $ticket));
        }
    }

    /**
     * The requester rates a solved ticket.
     */
    public static function rate(SupportTicket $ticket, User $requester, string $rating, ?string $comment = null): void
    {
        abort_unless($ticket->user_id === $requester->id && in_array($ticket->status, ['solved', 'closed'], true) && in_array($rating, ['good', 'bad'], true), 422);
        $ticket->forceFill(['satisfaction' => $rating, 'satisfaction_comment' => $comment ? mb_substr($comment, 0, 1000) : null])->save();
        SupportMessage::create(['support_ticket_id' => $ticket->id, 'user_id' => $requester->id, 'kind' => SupportMessage::EVENT, 'body' => $rating === 'good' ? __('Rated the help as good') : __('Rated the help as not good')]);
    }

    /**
     * Closes solved tickets after a week (scheduled).
     */
    public static function closeSolved(): int
    {
        return SupportTicket::where('status', 'solved')->where('solved_at', '<', now()->subDays(self::CLOSE_AFTER_DAYS))
            ->update(['status' => 'closed', 'closed_at' => now()]);
    }

    /**
     * @return Collection<int, User>
     */
    public static function agents(): Collection
    {
        return User::where('is_admin', true)->orderBy('name')->get();
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private static function addMessage(SupportTicket $ticket, User $author, string $kind, string $body, array $files): SupportMessage
    {
        $stored = [];

        foreach ($files as $file) {
            $stored[] = [
                'path' => $file->store('support/'.$ticket->id, SupportTicket::DISK),
                'name' => mb_substr($file->getClientOriginalName(), 0, 150),
                'size' => (int) $file->getSize(),
                'mime' => (string) $file->getMimeType(),
            ];
        }

        return SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $author->id,
            'kind' => $kind,
            'body' => $body,
            'attachments' => $stored ?: null,
        ]);
    }

    private static function describe(string $field, mixed $old, mixed $new): string
    {
        $label = fn (mixed $value) => match ($field) {
            'status' => SupportTicket::statusLabels()[$value] ?? (string) $value,
            'priority' => SupportTicket::priorityLabels()[$value] ?? (string) $value,
            'type' => SupportTicket::typeLabels()[$value]['label'] ?? (string) $value,
            'assignee_id' => $value ? (User::whereKey($value)->value('name') ?? '–') : __('nobody'),
            'roadmap_milestone_id' => $value ? (RoadmapMilestone::whereKey($value)->value('title') ?? '–') : __('none'),
            'area' => $value ? (ModuleCatalog::all()[$value]['label'] ?? $value) : __('none'),
            'tags' => $value ? implode(', ', (array) $value) : __('none'),
            default => (string) $value,
        };

        $names = ['status' => __('Status'), 'priority' => __('Priority'), 'type' => __('Type'), 'assignee_id' => __('Assignee'), 'roadmap_milestone_id' => __('Milestone'), 'area' => __('Area'), 'tags' => __('Tags'), 'subject' => __('Subject')];

        return $names[$field].': '.$label($old).' → '.$label($new);
    }

    private static function notifyRequester(SupportTicket $ticket, ?SupportMessage $message): void
    {
        $requester = $ticket->requester;

        if (! $requester) {
            return;
        }

        self::push($requester, $message ? __('Answer to your request :number', ['number' => $ticket->number()]) : __('Your request :number was solved', ['number' => $ticket->number()]), $message ? Str::limit($message->body, 140) : $ticket->subject, route('support.show', $ticket));
        rescue(fn () => $requester->notify(new SupportTicketUpdated($ticket, $message)));
    }

    private static function push(User $user, string $title, string $body, string $url): void
    {
        rescue(fn () => app(WebPush::class)->send($user, $title, $body, $url));
    }

    /**
     * The stored file of an attachment.
     *
     * @return array{path: string, name: string, size: int, mime: string}|null
     */
    public static function file(SupportMessage $message, int $index): ?array
    {
        $file = $message->attachments[$index] ?? null;

        return $file && Storage::disk(SupportTicket::DISK)->exists($file['path']) ? $file : null;
    }
}
