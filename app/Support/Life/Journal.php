<?php

namespace App\Support\Life;

use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\Trip;
use App\Models\User;
use App\Support\CalendarEvents;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * The daily journal: moods, activities to tag the day with, the questions of the morning and
 * evening (after the Five Minute Journal), photos and what else happened that day in the tool.
 */
class Journal
{
    public const DISK = 'local';

    public const MAX_PHOTOS = 6;

    /**
     * @return array<int, array{icon: string, label: string}>
     */
    public static function moods(): array
    {
        return [
            1 => ['icon' => '😞', 'label' => __('Bad')],
            2 => ['icon' => '🙁', 'label' => __('Meh')],
            3 => ['icon' => '😐', 'label' => __('Okay')],
            4 => ['icon' => '🙂', 'label' => __('Good')],
            5 => ['icon' => '😄', 'label' => __('Great')],
        ];
    }

    /**
     * Activities offered for tagging a day; own ones the member used before are added.
     *
     * @return list<string>
     */
    public static function activities(User $user): array
    {
        $defaults = [__('Family'), __('Friends'), __('Work'), __('Sport'), __('Nature'), __('Reading'), __('Cooking'), __('Travel'), __('Rest'), __('Learning'), __('Hobby'), __('Slept well'), __('Stress'), __('Ill')];
        $own = JournalEntry::where('user_id', $user->id)->whereNotNull('activities')->latest('date')->limit(200)->pluck('activities')->flatten()->filter()->unique()->all();

        return array_values(array_unique([...$defaults, ...$own]));
    }

    /**
     * The questions of the day: morning and evening.
     *
     * @return array<string, array{label: string, placeholder: string, part: string}>
     */
    public static function questions(): array
    {
        return [
            'grateful' => ['label' => __('I am grateful for …'), 'placeholder' => __('three things, big or small'), 'part' => 'morning'],
            'great_day' => ['label' => __('What would make today great?'), 'placeholder' => __('one thing you can influence'), 'part' => 'morning'],
            'highlights' => ['label' => __('What was good today?'), 'placeholder' => __('moments, successes, encounters'), 'part' => 'evening'],
            'better' => ['label' => __('What did I learn – what will I do better?'), 'placeholder' => __('one thought for tomorrow'), 'part' => 'evening'],
        ];
    }

    public static function storePhoto(UploadedFile $image, User $user): string
    {
        $decoded = (new ImageManager(new Driver))->read($image->getRealPath());
        $path = sprintf('journal/%d/%s.jpg', $user->id, Str::uuid());
        Storage::disk(self::DISK)->put($path, (string) $decoded->orient()->scaleDown(1600, 1600)->toJpeg(80));

        return $path;
    }

    public static function deletePhoto(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * Entries of the same calendar day in earlier years ("on this day").
     *
     * @return Collection<int, JournalEntry>
     */
    public static function onThisDay(User $user, Carbon $date): Collection
    {
        return JournalEntry::where('user_id', $user->id)
            ->where('date', '<', $date->toDateString())
            ->get()
            ->filter(fn (JournalEntry $entry) => $entry->date->format('m-d') === $date->format('m-d') && ! $entry->isEmpty())
            ->sortByDesc('date')->values();
    }

    /**
     * What else happened that day: appointments and dates from the calendar, completed tasks and trips.
     *
     * @return array{events: list<array{time: ?string, title: string, url: string, icon: string}>, tasks: Collection<int, Item>, trips: Collection<int, Trip>}
     */
    public static function dayContext(User $user, int $householdId, Carbon $date): array
    {
        $sources = CalendarEvents::sources($user);
        $events = collect(CalendarEvents::between($user, $householdId, $date->copy()->startOfDay(), $date->copy()->endOfDay(), array_values(array_diff(array_keys($sources), [CalendarEvents::TASKS]))))
            ->map(fn (array $event) => ['time' => $event['time'], 'title' => $event['title'], 'url' => $event['url'], 'icon' => $sources[$event['source']]['icon'] ?? '📌'])
            ->values()->all();

        $tasks = Item::query()->forHousehold($householdId)->visibleTo($user)
            ->where('type', Item::TYPE_TODO)->where('done', true)
            ->whereBetween('completed_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->orderBy('completed_at')->limit(20)->get();

        $trips = Trip::query()->visibleTo($user, $householdId)
            ->whereDate('starts_on', '<=', $date->toDateString())->whereDate('ends_on', '>=', $date->toDateString())
            ->get();

        return ['events' => $events, 'tasks' => $tasks, 'trips' => $trips];
    }
}
