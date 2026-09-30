<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\CollectionProperty;
use App\Models\HouseholdMember;
use App\Models\Item;
use App\Models\ItemActivity;
use Illuminate\Support\Facades\Auth;

/**
 * History of collection entries and tasks: who created, renamed, completed or changed what.
 * Values are stored as display text, so the history stays readable after properties change.
 */
class ActivityLog
{
    /**
     * Called by the item observer after an entry was created or updated.
     */
    public static function record(Item $item, bool $created): void
    {
        if ($item->collection_id === null || ! Auth::check()) {
            return;
        }

        if ($created) {
            self::add($item, 'created');

            return;
        }

        if ($item->wasChanged('title')) {
            self::add($item, 'renamed', ['from' => (string) $item->getOriginal('title'), 'to' => $item->title]);
        }

        if ($item->wasChanged('done')) {
            self::add($item, $item->done ? 'completed' : 'reopened');
        }

        if ($item->wasChanged('properties')) {
            $before = (array) ($item->getOriginal('properties') ?? []);
            $after = (array) ($item->properties ?? []);
            $properties = $item->collection?->properties ?? collect();
            $members = null;

            foreach ($properties as $property) {
                $old = $before[$property->id] ?? null;
                $new = $after[$property->id] ?? null;

                // Status changes of tasks are already logged as completed/reopened.
                if ($old == $new || ($property->system_key === 'status' && $item->wasChanged('done'))) {
                    continue;
                }

                $members ??= HouseholdMember::where('household_id', $item->household_id)->with('user:id,name')->get()->pluck('user.name', 'user_id')->all();

                self::add($item, 'changed', [
                    'property' => $property->name,
                    'from' => self::text($property, $old, $members),
                    'to' => self::text($property, $new, $members),
                ]);
            }
        }
    }

    public static function documentAdded(Attachment $attachment): void
    {
        $item = $attachment->item_id ? Item::find($attachment->item_id) : null;

        if ($item?->collection_id !== null && Auth::check()) {
            self::add($item, 'document_added', ['name' => $attachment->original_name]);
        }
    }

    /**
     * Human-readable sentence for one activity, e.g. "changed Status: Open → Done".
     */
    public static function describe(ItemActivity $activity): string
    {
        $details = $activity->details ?? [];

        return match ($activity->action) {
            'created' => __('created the entry'),
            'renamed' => __('renamed it to “:title”', ['title' => $details['to'] ?? '']),
            'completed' => __('marked it as done'),
            'reopened' => __('reopened it'),
            'document_added' => __('added the document “:name”', ['name' => $details['name'] ?? '']),
            'changed' => __('changed :property: :from → :to', [
                'property' => $details['property'] ?? '',
                'from' => ($details['from'] ?? null) ?: '—',
                'to' => ($details['to'] ?? null) ?: '—',
            ]),
            default => $activity->action,
        };
    }

    /**
     * @param  array<int, string>  $members
     */
    private static function text(CollectionProperty $property, mixed $value, array $members): ?string
    {
        if ($value === null) {
            return null;
        }

        $formatted = PropertyDisplay::format($property, (new Item)->forceFill(['properties' => [$property->id => $value]]), $members);

        return $formatted === null ? null : mb_substr($formatted['text'], 0, 200);
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private static function add(Item $item, string $action, ?array $details = null): void
    {
        ItemActivity::create(['item_id' => $item->id, 'user_id' => Auth::id(), 'action' => $action, 'details' => $details, 'created_at' => now()]);
    }
}
