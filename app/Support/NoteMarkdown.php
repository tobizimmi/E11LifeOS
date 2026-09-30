<?php

namespace App\Support;

use App\Models\Item;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Renders note bodies (Markdown with [[Wiki links]]) to safe HTML.
 *
 * Besides CommonMark it understands blocks written by the page editor:
 * ":::callout {type="tip"}" … ":::", ":::toggle {title="…"}" … ":::",
 * ":::embed {type="tasks"} :::" (tasks, calendar, subpages) and ==highlighted text==.
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

    private const BLOCK_OPEN = '/^:::(callout|toggle)(?:\s+\{([^}]*)\})?\s*$/';

    private const BLOCK_CLOSE = '/^:::\s*$/';

    private const EMBED = '/^:::embed(?:\s+\{([^}]*)\})?\s*:::\s*$/';

    /**
     * Callout types with their symbol.
     *
     * @var array<string, string>
     */
    public const CALLOUT_TYPES = ['info' => 'ℹ️', 'tip' => '💡', 'warning' => '⚠️', 'danger' => '⛔'];

    public const EMBED_TYPES = ['tasks', 'calendar', 'subpages'];

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

        $embed = fn (array $attributes): string => self::embed($note, $viewer, (string) ($attributes['type'] ?? ''), $interactiveTasks);
        $html = strtr(self::highlight(self::renderBlocks((string) $markdown, $embed)), $links);

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
     * Markdown to HTML with the editor's callout, toggle and embed blocks. The blocks are cut out
     * as placeholder paragraphs, their content is rendered on its own and put back as HTML.
     *
     * @param  Closure(array<string, string>): string  $embed
     */
    private static function renderBlocks(string $markdown, Closure $embed): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $markdown) ?: [];
        $output = [];
        $blocks = [];
        $fence = null;
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (self::togglesFence($line, $fence) || $fence !== null) {
                $output[] = $line;

                continue;
            }

            if (preg_match(self::EMBED, $line, $match)) {
                $placeholder = 'E11BLOCK'.count($blocks).'X';
                $blocks[$placeholder] = $embed(self::attributes($match[1] ?? ''));
                array_push($output, '', $placeholder, '');

                continue;
            }

            if (preg_match(self::BLOCK_OPEN, $line, $match)) {
                $end = self::closingLine($lines, $i);

                if ($end !== null) {
                    $placeholder = 'E11BLOCK'.count($blocks).'X';
                    $inner = self::renderBlocks(implode("\n", array_slice($lines, $i + 1, $end - $i - 1)), $embed);
                    $blocks[$placeholder] = self::wrapBlock($match[1], self::attributes($match[2] ?? ''), $inner);
                    array_push($output, '', $placeholder, '');
                    $i = $end;

                    continue;
                }
            }

            $output[] = $line;
        }

        $html = (string) Str::markdown(implode("\n", $output), self::MARKDOWN_OPTIONS);

        return $blocks === [] ? $html : (string) preg_replace_callback('/<p>(E11BLOCK\d+X)<\/p>/', fn (array $match) => $blocks[$match[1]] ?? '', $html);
    }

    /**
     * Tracks fenced code blocks; true when the line opens or closes one.
     */
    private static function togglesFence(string $line, ?string &$fence): bool
    {
        if (! preg_match('/^\s*(`{3,}|~{3,})/', $line, $match)) {
            return false;
        }

        if ($fence === null) {
            $fence = $match[1][0];
        } elseif ($match[1][0] === $fence) {
            $fence = null;
        }

        return true;
    }

    /**
     * Index of the ":::" line that closes the block opened on line $start (nested blocks count).
     *
     * @param  list<string>  $lines
     */
    private static function closingLine(array $lines, int $start): ?int
    {
        $depth = 1;
        $fence = null;

        for ($i = $start + 1, $count = count($lines); $i < $count; $i++) {
            if (self::togglesFence($lines[$i], $fence) || $fence !== null) {
                continue;
            }

            if (preg_match(self::BLOCK_OPEN, $lines[$i])) {
                $depth++;
            } elseif (preg_match(self::BLOCK_CLOSE, $lines[$i]) && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * key="value" pairs of a block's {…} part.
     *
     * @return array<string, string>
     */
    private static function attributes(string $source): array
    {
        preg_match_all('/([\w-]+)="([^"]*)"/', $source, $matches, PREG_SET_ORDER);

        return collect($matches)->mapWithKeys(fn (array $match) => [$match[1] => $match[2]])->all();
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private static function wrapBlock(string $kind, array $attributes, string $innerHtml): string
    {
        if ($kind === 'toggle') {
            return sprintf('<details class="page-toggle"><summary>%s</summary><div class="page-toggle-body">%s</div></details>', e(($attributes['title'] ?? '') ?: __('Details')), $innerHtml);
        }

        $type = array_key_exists($attributes['type'] ?? '', self::CALLOUT_TYPES) ? $attributes['type'] : 'info';

        return sprintf('<div class="callout callout-%s" role="note"><span class="callout-icon" aria-hidden="true">%s</span><div class="callout-body">%s</div></div>', $type, self::CALLOUT_TYPES[$type], $innerHtml);
    }

    /**
     * ==text== becomes <mark>, except inside code.
     */
    private static function highlight(string $html): string
    {
        $parts = preg_split('/(<pre[\s>].*?<\/pre>|<code[\s>].*?<\/code>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = (string) preg_replace('/==(?=\S)(.+?)(?<=\S)==/u', '<mark>$1</mark>', $part);
            }
        }

        return implode('', $parts);
    }

    /**
     * Live content embedded in a page: its linked tasks, the next two weeks or its subpages.
     */
    private static function embed(Item $page, User $viewer, string $type, bool $interactive): string
    {
        return match ($type) {
            'tasks' => self::embedTasks($page, $viewer, $interactive && $page->collection_id === null),
            'calendar' => self::embedCalendar($page, $viewer),
            'subpages' => self::embedSubpages($page, $viewer),
            default => '',
        };
    }

    private static function embedTasks(Item $page, User $viewer, bool $interactive): string
    {
        $rows = TaskPages::tasksOf($page, $viewer)->map(fn (Item $task) => sprintf(
            '<li><input type="checkbox" %s%s aria-label="%s"> <a href="%s"%s>%s</a></li>',
            $task->done ? 'checked="" ' : '',
            $interactive ? sprintf('wire:key="embed-task-%d-%d" wire:click.prevent="togglePageTask(%d)"', $task->id, (int) $task->done, $task->id) : 'disabled=""',
            e(__('Tick off')),
            e(route('tasks.index', ['task' => $task->id])),
            $task->done ? ' class="is-done"' : '',
            e($task->title),
        ));

        return self::embedFrame('✅ '.__('Tasks of this page'), $rows->isEmpty()
            ? '<p class="page-embed-empty">'.e(__('No linked tasks yet – add them below the page.')).'</p>'
            : '<ul class="page-embed-tasks">'.$rows->implode('').'</ul>');
    }

    private static function embedCalendar(Item $page, User $viewer): string
    {
        $from = Carbon::today();
        $events = collect(CalendarEvents::between($viewer, $page->household_id, $from, $from->copy()->addDays(13), array_keys(CalendarEvents::sources($viewer))))->take(25);
        $rows = $events->groupBy('date')->map(fn ($day, string $date) => sprintf(
            '<li><span class="page-embed-date">%s</span><ul>%s</ul></li>',
            e(Carbon::parse($date)->translatedFormat('D, d.m.')),
            $day->map(fn (array $event) => sprintf('<li>%s<a href="%s">%s</a></li>', $event['time'] ? '<span class="page-embed-time">'.e($event['time']).'</span> ' : '', e($event['url']), e($event['title'])))->implode(''),
        ));

        return self::embedFrame('📅 '.__('Next 14 days'), ($rows->isEmpty()
            ? '<p class="page-embed-empty">'.e(__('Nothing planned for the next 14 days.')).'</p>'
            : '<ul class="page-embed-calendar">'.$rows->implode('').'</ul>')
            .sprintf('<p class="page-embed-more"><a href="%s">%s</a></p>', e(route('calendar.index')), e(__('Open calendar'))));
    }

    private static function embedSubpages(Item $page, User $viewer): string
    {
        $rows = Item::query()->forHousehold($page->household_id)->visibleTo($viewer)->ofType(Item::TYPE_NOTE)
            ->where('parent_id', $page->id)->orderBy('position')->orderBy('title')->get()
            ->map(fn (Item $child) => sprintf('<li><a href="%s">%s %s</a></li>', e(route('pages.index', ['item' => $child->id])), e($child->displayIcon()), e($child->title)));

        return self::embedFrame('📄 '.__('Subpages'), $rows->isEmpty()
            ? '<p class="page-embed-empty">'.e(__('No subpages yet.')).'</p>'
            : '<ul class="page-embed-subpages">'.$rows->implode('').'</ul>');
    }

    private static function embedFrame(string $title, string $body): string
    {
        return sprintf('<section class="page-embed"><p class="page-embed-title">%s</p>%s</section>', e($title), $body);
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
