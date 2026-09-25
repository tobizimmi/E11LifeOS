<?php

namespace App\Support;

use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Renders note bodies (Markdown with [[Wiki links]]) to safe HTML.
 */
class NoteMarkdown
{
    /**
     * Raw HTML in notes is stripped and javascript:/data: links are dropped, so
     * the output can be echoed unescaped.
     *
     * @var array<string, mixed>
     */
    private const MARKDOWN_OPTIONS = [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ];

    /**
     * Wiki links resolve against the notes the viewer can see: existing ones open
     * the note, missing ones offer to create a note with that title.
     */
    public static function toHtml(Item $note, User $viewer): string
    {
        $noteIdsByTitle = self::visibleNoteIdsByTitle($note->household_id, $viewer);
        $links = [];

        $markdown = preg_replace_callback(Item::WIKI_LINK_PATTERN, function (array $match) use (&$links, $noteIdsByTitle) {
            $title = trim($match[1]);
            $label = trim($match[2] ?? '') ?: $title;
            $targetId = $noteIdsByTitle[mb_strtolower($title)] ?? null;

            // Alphanumeric placeholder: survives Markdown rendering untouched.
            $placeholder = 'E11WIKILINK'.count($links).'X';

            $links[$placeholder] = $targetId
                ? sprintf(
                    '<a href="%s" class="wiki-link">%s</a>',
                    e(route('pages.index', ['item' => $targetId])),
                    e($label),
                )
                : sprintf(
                    '<a href="%s" class="wiki-link wiki-link-missing" title="%s">%s</a>',
                    e(route('pages.index', ['new' => $title])),
                    e(__('Create page')),
                    e($label),
                );

            return $placeholder;
        }, $note->body());

        $html = (string) Str::markdown($markdown, self::MARKDOWN_OPTIONS);

        return strtr($html, $links);
    }

    /**
     * Plain-text preview for lists (Markdown syntax and link brackets removed).
     */
    public static function excerpt(string $body, int $limit = 160): string
    {
        $withoutWikiLinks = preg_replace_callback(
            Item::WIKI_LINK_PATTERN,
            fn (array $match) => trim($match[2] ?? '') ?: trim($match[1]),
            $body,
        );

        $html = (string) Str::markdown($withoutWikiLinks, self::MARKDOWN_OPTIONS);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), $limit);
    }

    /**
     * @return array<string, int>
     */
    private static function visibleNoteIdsByTitle(int $householdId, User $viewer): array
    {
        return Item::query()
            ->forHousehold($householdId)
            ->visibleTo($viewer)
            ->ofType(Item::TYPE_NOTE)
            // Descending so that, for duplicate titles, the oldest note wins.
            ->orderByDesc('id')
            ->get(['id', 'title'])
            ->mapWithKeys(fn (Item $item) => [mb_strtolower($item->title) => $item->id])
            ->all();
    }
}
