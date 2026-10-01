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
     * Livewire action toggleTask(n), n being the box's position in the page. With $taskButtons,
     * each item also gets a button for checklistToTask(n).
     */
    public static function toHtml(Item $note, User $viewer, bool $interactiveTasks = false, bool $taskButtons = false): string
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

        $html = self::decorateChecklists($html, $note, $viewer);

        if ($interactiveTasks) {
            // Only the task-list renderer emits <input> tags: raw HTML from the note is stripped.
            $taskIndex = 0;
            $html = (string) preg_replace_callback(
                '/<input (checked="" )?disabled="" type="checkbox">/',
                function (array $match) use (&$taskIndex, $taskButtons) {
                    $isChecked = ($match[1] ?? '') !== '';
                    $index = $taskIndex++;

                    // The key changes with the state, so Livewire replaces the box instead of
                    // morphing it (a prevented click leaves the old checked state on screen).
                    $button = $taskButtons
                        ? sprintf('<button type="button" class="checklist-to-task" wire:click="checklistToTask(%d)" title="%s" aria-label="%s">↗</button>', $index, e(__('Make it a task')), e(__('Make it a task')))
                        : '';

                    return sprintf(
                        '<input %stype="checkbox" wire:key="task-%d-%s" wire:click.prevent="toggleTask(%d)" aria-label="%s">%s',
                        $isChecked ? 'checked="" ' : '',
                        $index,
                        $isChecked ? 'done' : 'open',
                        $index,
                        e(__('Tick off')),
                        $button,
                    );
                },
                $html,
            );
        }

        return $html;
    }

    /** "//15.10.2026", "//15.10." or "//2026-10-15" after a space: the due date of a checklist item. */
    public const DUE_PATTERN = '/(?<=^|\s|>)\/\/(\d{1,2}\.\d{1,2}\.(?:\d{2,4})?|\d{4}-\d{2}-\d{2})(?=$|[\s<,.;!?])/u';

    /** "@Anna" after a space: who is responsible. */
    public const PERSON_PATTERN = '/(?<=^|\s|>)@([\p{L}][\p{L}\-]{1,30})/u';

    /** "{task:123}": the task made from a checklist item. */
    public const TASK_LINK_PATTERN = '/\{task:(\d+)\}/';

    /**
     * Due dates, people and linked tasks of checklist items as small chips (like in Confluence).
     */
    private static function decorateChecklists(string $html, Item $note, User $viewer): string
    {
        preg_match_all(self::TASK_LINK_PATTERN, $html, $ids);
        $tasks = $ids[1] === [] ? collect() : Item::query()->forHousehold($note->household_id)->visibleTo($viewer)
            ->where('type', Item::TYPE_TODO)->whereIn('id', array_map('intval', $ids[1]))->get()->keyBy('id');

        // Only text between tags is decorated, never attributes or code.
        $parts = preg_split('/(<[^>]*>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        $inCode = 0;

        foreach ($parts as $position => $part) {
            if (str_starts_with($part, '<')) {
                if (preg_match('#^<(/?)(code|pre)\b#i', $part, $tag)) {
                    $inCode = max(0, $inCode + ($tag[1] === '/' ? -1 : 1));
                }

                continue;
            }

            if ($inCode > 0 || $part === '' || ! preg_match('/\/\/|@|\{task:/', $part)) {
                continue;
            }

            $part = (string) preg_replace_callback(self::DUE_PATTERN, function (array $match) {
                $date = self::parseDue($match[1]);
                $overdue = $date?->lt(today()) ? ' checklist-due-overdue' : '';

                return '<span class="checklist-due'.$overdue.'">📅 '.e($date ? $date->format('d.m.Y') : $match[1]).'</span>';
            }, $part);
            $part = (string) preg_replace(self::PERSON_PATTERN, '<span class="checklist-person">@$1</span>', $part);
            $parts[$position] = (string) preg_replace_callback(self::TASK_LINK_PATTERN, function (array $match) use ($tasks) {
                $task = $tasks->get((int) $match[1]);

                return $task
                    ? '<a href="'.e(route('tasks.index', ['task' => $task->id])).'" class="checklist-task'.($task->done ? ' checklist-task-done' : '').'" title="'.e($task->title).'">'.($task->done ? '✓ ' : '✅ ').e(__('Task')).'</a>'
                    : '';
            }, $part);
        }

        return implode('', $parts);
    }

    public static function parseDue(string $value): ?Carbon
    {
        try {
            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})?$/', $value, $parts)) {
                $year = isset($parts[3]) ? (int) $parts[3] : (int) now()->year;
                $year = $year < 100 ? 2000 + $year : $year;

                return checkdate((int) $parts[2], (int) $parts[1], $year) ? Carbon::create($year, (int) $parts[2], (int) $parts[1])->startOfDay() : null;
            }

            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The text of the n-th checklist item (counted like toggleTask), or null.
     *
     * @return ?array{line: int, text: string, done: bool}
     */
    public static function taskLine(string $markdown, int $index): ?array
    {
        $lines = preg_split('/\r\n|\n|\r/', $markdown) ?: [];
        $fence = null;
        $taskIndex = 0;

        foreach ($lines as $number => $line) {
            if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $match)) {
                $fence = $fence === null ? $match[1][0] : ($match[1][0] === $fence ? null : $fence);

                continue;
            }

            if ($fence === null && preg_match(self::TASK_MARKER, $line, $match) && $taskIndex++ === $index) {
                return ['line' => $number, 'text' => trim(substr($line, strlen($match[0]) + 1)), 'done' => $match[2] !== ' '];
            }
        }

        return null;
    }

    /**
     * Appends text to the n-th checklist item (e.g. the link to the task made from it).
     */
    public static function appendToTask(string $markdown, int $index, string $suffix): ?string
    {
        $found = self::taskLine($markdown, $index);

        if (! $found) {
            return null;
        }

        $lines = preg_split('/\r\n|\n|\r/', $markdown) ?: [];
        $lines[$found['line']] = rtrim($lines[$found['line']]).' '.$suffix;

        return implode("\n", $lines);
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
