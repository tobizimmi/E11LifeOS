<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\HouseholdMember;
use App\Models\Item;
use App\Models\User;

/**
 * Creates inbox entries and tasks from one line of text – used by the forms and by the
 * endpoint that receives entries captured while offline.
 */
class QuickCapture
{
    /**
     * "#tags" in the text become tags of the entry.
     */
    public static function inbox(User $user, int $householdId, string $text, bool $private = false): ?Item
    {
        [$title, $tags] = Item::splitHashtags(trim($text));

        if ($title === '') {
            return null;
        }

        $item = Item::create([
            'household_id' => $householdId,
            'type' => Item::TYPE_INBOX,
            'visibility' => $private ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'title' => mb_substr($title, 0, 255),
            'created_by' => $user->id,
        ]);
        $item->syncTagNames($tags);

        return $item;
    }

    /**
     * A task from natural language (see TaskInput); null if no title is left.
     *
     * @param  array<int, string>  $ignored  Recognised phrases to keep in the title.
     * @param  array<int, string>  $extraTags
     */
    public static function task(User $user, int $householdId, string $text, ?string $defaultDue = null, array $ignored = [], array $extraTags = []): ?Item
    {
        $parsed = TaskInput::parse(mb_substr($text, 0, 500), self::members($householdId), $user->id, $ignored);

        if ($parsed['title'] === '') {
            return null;
        }

        $collection = Collection::tasksFor($householdId)->load('properties');
        $task = new Item([
            'household_id' => $householdId,
            'collection_id' => $collection->id,
            'type' => Item::TYPE_TODO,
            'visibility' => $collection->entryVisibility(),
            'title' => mb_substr($parsed['title'], 0, 255),
            'created_by' => $user->id,
            'position' => (int) Item::where('collection_id', $collection->id)->max('position') + 1,
        ]);

        $values = ['due' => $parsed['due'] ?? $defaultDue, 'repeat' => $parsed['repeat'], 'priority' => $parsed['priority'], 'assignee' => $parsed['assignee']];

        foreach ($values as $key => $value) {
            if ($value !== null && ($property = $collection->systemProperty($key))) {
                $task->setPropertyValue($property, $property->normalizeValue($value, array_keys(self::members($householdId))));
            }
        }

        $task->due_time = $parsed['time'];
        $task->save();
        $task->syncTagNames([...$parsed['tags'], ...$extraTags]);

        return $task;
    }

    /**
     * @return array<int, string>
     */
    public static function members(int $householdId): array
    {
        return User::whereIn('id', HouseholdMember::where('household_id', $householdId)->pluck('user_id'))
            ->orderBy('name')->pluck('name', 'id')->all();
    }
}
