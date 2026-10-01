<?php

namespace App\Support\Life;

use App\Models\Trip;
use App\Models\TripFeedback;
use App\Models\TripParticipant;
use App\Models\TripStop;
use App\Models\User;
use App\Support\WebPush;
use Illuminate\Support\Carbon;

/**
 * Comments and suggestions on a trip plan: written in the app or via the trip folder / a personal
 * participant page. Suggestions wait until a member with access to the trip approves them (they
 * become an entry of the plan, as an idea or on a day) or declines them.
 */
class TripFeedbackBox
{
    /**
     * @param  array{name: string, participant?: ?TripParticipant, user?: ?User}  $author
     */
    public static function comment(Trip $trip, array $author, string $body, ?int $stopId = null): TripFeedback
    {
        $feedback = $trip->feedback()->create([
            ...self::authorFields($author),
            'type' => TripFeedback::COMMENT,
            'trip_stop_id' => $stopId !== null && $trip->stops()->whereKey($stopId)->exists() ? $stopId : null,
            'body' => mb_substr(trim($body), 0, 2000),
        ]);
        self::notify($trip, $feedback, $author['user'] ?? null);

        return $feedback;
    }

    /**
     * @param  array{name: string, participant?: ?TripParticipant, user?: ?User}  $author
     */
    public static function suggest(Trip $trip, array $author, string $title, ?string $day = null, ?string $location = null, ?string $body = null): TripFeedback
    {
        $date = $day ? Carbon::parse($day)->startOfDay() : null;

        $feedback = $trip->feedback()->create([
            ...self::authorFields($author),
            'type' => TripFeedback::SUGGESTION,
            'status' => TripFeedback::PENDING,
            'title' => mb_substr(trim($title), 0, 255),
            'location' => trim((string) $location) !== '' ? mb_substr(trim((string) $location), 0, 255) : null,
            'day' => $date && $date->betweenIncluded($trip->starts_on, $trip->ends_on) ? $date : null,
            'body' => trim((string) $body) !== '' ? mb_substr(trim((string) $body), 0, 2000) : null,
        ]);
        self::notify($trip, $feedback, $author['user'] ?? null);

        return $feedback;
    }

    /**
     * Takes a suggestion into the plan: on the chosen day (or the suggested one), otherwise as an idea.
     */
    public static function approve(TripFeedback $suggestion, ?string $day, User $approver): TripStop
    {
        abort_unless($suggestion->isPending(), 422);
        $trip = $suggestion->trip;
        $date = $day === null ? $suggestion->day : ($day === '' ? null : Carbon::parse($day)->startOfDay());
        $date = $date && $date->betweenIncluded($trip->starts_on, $trip->ends_on) ? $date : null;

        $stop = $trip->stops()->create([
            'day' => $date,
            'kind' => 'sight',
            'title' => (string) $suggestion->title,
            'location' => $suggestion->location,
            'notes' => trim(__('Suggested by :name', ['name' => $suggestion->author_name]).($suggestion->body ? "\n".$suggestion->body : '')),
            'position' => (int) $trip->stops()->where('day', $date)->max('position') + 1,
            'created_by' => $approver->id,
        ]);
        $suggestion->update(['status' => TripFeedback::APPROVED, 'trip_stop_id' => $stop->id]);

        return $stop;
    }

    public static function decline(TripFeedback $suggestion): void
    {
        abort_unless($suggestion->isPending(), 422);
        $suggestion->update(['status' => TripFeedback::DECLINED]);
    }

    /**
     * @param  array{name: string, participant?: ?TripParticipant, user?: ?User}  $author
     * @return array{author_name: string, participant_id: ?int, user_id: ?int}
     */
    private static function authorFields(array $author): array
    {
        return [
            'author_name' => mb_substr(trim($author['name']), 0, 100),
            'participant_id' => ($author['participant'] ?? null)?->id,
            'user_id' => ($author['user'] ?? null)?->id,
        ];
    }

    /**
     * Tells the creator of the trip (bell, push) unless they wrote it themselves.
     */
    private static function notify(Trip $trip, TripFeedback $feedback, ?User $author): void
    {
        $creator = $trip->creator;

        if (! $creator || $creator->id === $author?->id) {
            return;
        }

        $title = $feedback->isSuggestion()
            ? '💡 '.__(':name suggests “:title”', ['name' => $feedback->author_name, 'title' => $feedback->title])
            : '💬 '.__(':name commented on “:trip”', ['name' => $feedback->author_name, 'trip' => $trip->title]);

        app(WebPush::class)->send($creator, $title, (string) ($feedback->body ?? ''), route('trips.show', ['trip' => $trip, 'tab' => 'plan']));
    }
}
