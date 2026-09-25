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
     * Matches a checklist marker ("- [ ]", "1. [x]", also inside "> " quotes);
     * group 1 is everything before the state character, group 2 the state.
     */
    private const TASK_MARKER = '/^((?:\s*>)*\s*(?:[-*+]|\d+[.)])\s+\[)([ xX])(?=\])/';

    /**
     * Wiki links resolve against the notes the viewer can see: existing ones open
     * the note, missing ones offer to create a note with that title.
     *
     * With $interactiveTasks, checklist boxes become clickable and call the
     * Livewire action toggleTask(n), n being the box's position in the page.
     */
    public static function toHtml(Item $note, User $viewer, bool $interactiveTasks = false): string
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

        $html = strtr((string) Str::markdown($markdown, self::MARKDOWN_OPTIONS), $links);

        if ($interactiveTasks) {
            // Only the task-list renderer emits <input> tags: raw HTML from the note is stripped.
            $taskIndex = 0;
            $html = (string) preg_replace_callback(
                '/<input (checked="" )?disabled="" type="checkbox">/',
                function (array $match) use (&$taskIndex) {
                    $isChecked = ($match[1] ?? '') !== '';
                    $index = $taskIndex++;

                    // The key changes with the state, so Livewire replaces the box instead of
                    // morphing it (a prevented click leaves the old checked state on screen).
                    return sprintf(
                        '<input %stype="checkbox" wire:key="task-%d-%s" wire:click.prevent="toggleTask(%d)" aria-label="%s">',
                        $isChecked ? 'checked="" ' : '',
                        $index,
                        $isChecked ? 'done' : 'open',
                        $index,
                        e(__('Tick off')),
                    );
                },
                $html,
            );
        }

        return $html;
    }

    /**
     * Flips the n-th checklist item in the Markdown source, counted the same way
     * the rendered page counts its checkboxes (fenced code blocks are skipped).
     * Returns null when there is no such item.
     */
    public static function toggleTask(string $markdown, int $index): ?string
    {
        $lines = preg_split('/(\r\n|\n|\r)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE);
        $fence = null;
        $taskIndex = 0;

        foreach ($lines as $i => $line) {
            if ($i % 2 === 1) {
                continue; // line separator captured by preg_split
            }

            if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $match)) {
                if ($fence === null) {
                    $fence = $match[1][0];
                } elseif ($match[1][0] === $fence) {
                    $fence = null;
                }

                continue;
            }

            if ($fence !== null || ! preg_match(self::TASK_MARKER, $line, $match)) {
                continue;
            }

            if ($taskIndex++ === $index) {
                $lines[$i] = $match[1].($match[2] === ' ' ? 'x' : ' ').substr($line, strlen($match[0]));

                return implode('', $lines);
            }
        }

        return null;
    }

    /**
     * Markdown without wiki links (e.g. an AI answer) to safe HTML.
     */
    public static function plainToHtml(string $markdown): string
    {
        return (string) Str::markdown($markdown, self::MARKDOWN_OPTIONS);
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
