<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\JournalEntry;
use App\Support\Life\Journal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The personal journal for the app: one entry per day with mood, activities and the questions of
 * the morning and the evening. Entries belong to the member alone.
 */
class JournalController extends ApiController
{
    private const TEXT_FIELDS = ['grateful', 'great_day', 'highlights', 'better', 'notes'];

    private const MAX_RANGE_DAYS = 370;

    /**
     * The entries of a period (default: the last 60 days) plus the choices for mood and activities.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $to = Carbon::parse($data['to'] ?? today()->toDateString());
        $from = Carbon::parse($data['from'] ?? $to->copy()->subDays(59)->toDateString());
        abort_if($from->diffInDays($to) > self::MAX_RANGE_DAYS, 422, __('Please choose at most one year.'));

        $user = $this->user();

        return $this->ok([
            'moods' => collect(Journal::moods())->map(fn (array $mood, int $value) => ['value' => $value] + $mood)->values()->all(),
            'activities' => Journal::activities($user),
            'questions' => collect(Journal::questions())->map(fn (array $question, string $key) => ['key' => $key] + $question)->values()->all(),
            'entries' => JournalEntry::where('user_id', $user->id)
                ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
                ->orderByDesc('date')->get()
                ->map(fn (JournalEntry $entry) => $this->entryData($entry))->all(),
        ]);
    }

    public function show(string $date): JsonResponse
    {
        $entry = $this->entryFor($date);

        return $this->ok($entry->exists ? $this->entryData($entry) : null);
    }

    /**
     * Saves the day (only the fields that are sent); a day with nothing left in it is removed.
     */
    public function update(Request $request, string $date): JsonResponse
    {
        abort_if(Carbon::parse($date)->isAfter(today()->addDay()), 422, __('The day is in the future.'));

        $data = $request->validate([
            'mood' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'activities' => ['sometimes', 'array', 'max:40'],
            'activities.*' => ['string', 'max:40'],
            'grateful' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'great_day' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'highlights' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'better' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $entry = $this->entryFor($date);

        if (array_key_exists('mood', $data)) {
            $entry->mood = $data['mood'];
        }

        if (array_key_exists('activities', $data)) {
            $entry->activities = collect($data['activities'])->map(fn (string $activity) => trim($activity))->filter()->unique()->values()->all();
        }

        foreach (self::TEXT_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $entry->{$field} = trim((string) $data[$field]) !== '' ? trim((string) $data[$field]) : null;
            }
        }

        if ($entry->isEmpty()) {
            $entry->exists && $entry->delete();

            return $this->ok(null);
        }

        $entry->save();

        return $this->ok($this->entryData($entry));
    }

    /**
     * Adds a photo to the day (at most Journal::MAX_PHOTOS).
     */
    public function storePhoto(Request $request, string $date): JsonResponse
    {
        abort_if(Carbon::parse($date)->isAfter(today()->addDay()), 422, __('The day is in the future.'));
        $request->validate(['photo' => ['required', 'image', 'max:15360']]);
        $entry = $this->entryFor($date);
        $photos = $entry->photos ?? [];
        abort_if(count($photos) >= Journal::MAX_PHOTOS, 422, __('At most :count photos per day.', ['count' => Journal::MAX_PHOTOS]));

        $entry->photos = [...$photos, Journal::storePhoto($request->file('photo'), $this->user())];
        $entry->save();

        return $this->ok($this->entryData($entry), 201);
    }

    public function photo(string $date, int $index): StreamedResponse
    {
        $entry = $this->entryFor($date);
        $path = ($entry->photos ?? [])[$index] ?? null;
        abort_unless($entry->exists && is_string($path) && Storage::disk(Journal::DISK)->exists($path), 404);

        return Storage::disk(Journal::DISK)->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function destroyPhoto(string $date, int $index): JsonResponse
    {
        $entry = $this->entryFor($date);
        $photos = $entry->photos ?? [];
        abort_unless($entry->exists && isset($photos[$index]), 404);

        Journal::deletePhoto($photos[$index]);
        unset($photos[$index]);
        $entry->photos = array_values($photos);

        if ($entry->isEmpty()) {
            $entry->delete();

            return $this->ok(null);
        }

        $entry->save();

        return $this->ok($this->entryData($entry));
    }

    private function entryFor(string $date): JournalEntry
    {
        $day = Carbon::parse($date)->toDateString();

        return JournalEntry::where('user_id', $this->user()->id)->whereDate('date', $day)->first()
            ?? new JournalEntry(['user_id' => $this->user()->id, 'date' => $day]);
    }

    /**
     * @return array<string, mixed>
     */
    private function entryData(JournalEntry $entry): array
    {
        return [
            'date' => $entry->date->toDateString(),
            'mood' => $entry->mood,
            'activities' => $entry->activities ?? [],
            'grateful' => $entry->grateful,
            'great_day' => $entry->great_day,
            'highlights' => $entry->highlights,
            'better' => $entry->better,
            'notes' => $entry->notes,
            'photo_count' => count($entry->photos ?? []),
        ];
    }
}
