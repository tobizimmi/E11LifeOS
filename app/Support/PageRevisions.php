<?php

namespace App\Support;

use App\Models\Item;
use App\Models\PageRevision;
use App\Models\User;

/**
 * Version history of pages: before a page changes, its previous title and text are kept. While
 * someone keeps typing (autosave) only one version per ten minutes is kept, at most 50 per page.
 */
class PageRevisions
{
    public const KEEP = 50;

    public const QUIET_MINUTES = 10;

    /**
     * Keeps the current state of the page if title or text are about to change.
     */
    public static function record(Item $page, User $user, string $newTitle, string $newBody, bool $force = false): void
    {
        $title = (string) $page->title;
        $body = $page->body();

        if ($title === $newTitle && $body === $newBody) {
            return;
        }

        $last = PageRevision::where('item_id', $page->id)->latest('id')->first();

        // Ongoing editing by the same person: the version from before they started is enough.
        if (! $force && $last && $last->user_id === $user->id && $last->created_at?->gt(now()->subMinutes(self::QUIET_MINUTES))) {
            return;
        }

        PageRevision::create(['item_id' => $page->id, 'user_id' => $user->id, 'title' => mb_substr($title, 0, 255), 'body' => $body]);

        $keep = PageRevision::where('item_id', $page->id)->latest('id')->limit(self::KEEP)->pluck('id');
        PageRevision::where('item_id', $page->id)->whereNotIn('id', $keep)->delete();
    }

    /**
     * Brings an earlier version back; the current state is kept as a version first.
     */
    public static function restore(Item $page, PageRevision $revision, User $user): void
    {
        abort_unless($revision->item_id === $page->id, 404);

        self::record($page, $user, $revision->title, (string) $revision->body, true);
        $page->update(['title' => $revision->title, 'content' => ['body' => (string) $revision->body] + ($page->content ?? [])]);
    }
}
