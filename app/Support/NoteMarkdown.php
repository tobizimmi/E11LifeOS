<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\CollectionView;
use App\Models\Item;
use App\Models\ShoppingList;
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

    private const BLOCK_OPEN = '/^:::(callout|toggle|columns|column)(?:\s+\{([^}]*)\})?\s*$/';

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

        $embed = fn (array $attributes): string => self::embed($note, $viewer, (string) ($attributes['type'] ?? ''), $interactiveTasks, $attributes);
        $html = strtr(self::sizes(self::colours(self::highlight(self::renderBlocks((string) $markdown, $embed)))), $links);

        // After decorateChecklists so its "@Name" person pattern never fires inside an "@[id:Name]"
        // mention token ("@[" is not a letter, so the two never collide).
        $html = self::mentions(self::decorateChecklists($html, $note, $viewer));

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
                            .sprintf('<button type="button" class="checklist-to-shopping" wire:click="checklistToShopping(%d)" title="%s" aria-label="%s">🛒</button>', $index, e(__('Add to shopping list')), e(__('Add to shopping list')))
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

        return self::headingsAndToc($html);
    }

    /**
     * Read-only HTML for the public wiki. Reuses the blocks/colour/size/highlight/mention pipeline
     * and the table of contents, but embeds (tasks/calendar/subpages) are dropped and wiki links
     * resolve within the published subtree (to "?p=<id>" under the public token) or, when the target
     * is not public, render as plain text. No viewer-specific or interactive markup is produced.
     *
     * @param  array<string, int>  $idsByTitle  lower-case page title => id, for pages in the subtree
     */
    public static function publicToHtml(Item $note, string $token, array $idsByTitle): string
    {
        $links = [];

        $markdown = preg_replace_callback(Item::WIKI_LINK_PATTERN, function (array $match) use (&$links, $idsByTitle, $token) {
            $title = trim($match[1]);
            $label = trim($match[2] ?? '') ?: $title;
            $targetId = $idsByTitle[mb_strtolower($title)] ?? null;
            $placeholder = 'E11WIKILINK'.count($links).'X';

            $links[$placeholder] = $targetId
                ? sprintf('<a href="%s" class="wiki-link">%s</a>', e(route('pages.public', ['token' => $token]).'?p='.$targetId), e($label))
                : e($label);

            return $placeholder;
        }, $note->body());

        $embed = fn (array $attributes): string => '';
        $html = strtr(self::mentions(self::sizes(self::colours(self::highlight(self::renderBlocks((string) $markdown, $embed))))), $links);

        return self::headingsAndToc($html);
    }

    /**
     * Gives headings (h1–h3) stable id anchors and, where the body contains a "[toc]" line on its
     * own, replaces it with an automatically generated table of contents linking to those anchors.
     */
    private static function headingsAndToc(string $html): string
    {
        $toc = [];
        $used = [];

        $html = (string) preg_replace_callback('/<h([1-3])>(.*?)<\/h\1>/su', function (array $match) use (&$toc, &$used) {
            $level = (int) $match[1];
            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($match[2])));

            if ($text === '') {
                return $match[0];
            }

            $slug = Str::slug($text) ?: 'abschnitt';
            $base = $slug;
            $n = 2;

            while (isset($used[$slug])) {
                $slug = $base.'-'.$n++;
            }

            $used[$slug] = true;
            $toc[] = ['level' => $level, 'id' => $slug, 'text' => $text];

            return sprintf('<h%d id="%s">%s</h%d>', $level, e($slug), $match[2], $level);
        }, $html);

        if ($toc === []) {
            return (string) str_ireplace('<p>[toc]</p>', '', $html);
        }

        $items = '';

        foreach ($toc as $entry) {
            $items .= sprintf('<li class="page-toc-h%d"><a href="#%s">%s</a></li>', $entry['level'], e($entry['id']), e($entry['text']));
        }

        $nav = '<nav class="page-toc" aria-label="'.e(__('Table of contents')).'"><p class="page-toc-title">'.e(__('Contents')).'</p><ul>'.$items.'</ul></nav>';

        return (string) str_ireplace('<p>[toc]</p>', $nav, $html);
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

        if ($kind === 'columns') {
            return '<div class="page-columns">'.$innerHtml.'</div>';
        }

        if ($kind === 'column') {
            return '<div class="page-column">'.$innerHtml.'</div>';
        }

        $type = array_key_exists($attributes['type'] ?? '', self::CALLOUT_TYPES) ? $attributes['type'] : 'info';

        return sprintf('<div class="callout callout-%s" role="note"><span class="callout-icon" aria-hidden="true">%s</span><div class="callout-body">%s</div></div>', $type, self::CALLOUT_TYPES[$type], $innerHtml);
    }

    /**
     * Named text colours the editor can apply ("{color=green}…{/color}").
     */
    public const TEXT_COLOURS = ['gray', 'red', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink'];

    /** Named font sizes a span can carry ("{size=large}…{/size}"), rendered as .ts-<name>. */
    public const TEXT_SIZES = ['small', 'large', 'xl'];

    /**
     * "{color=green}text{/color}" becomes <span class="tc-green">, except inside code. Only the
     * fixed palette is accepted; anything else is left untouched.
     */
    private static function colours(string $html): string
    {
        $parts = preg_split('/(<pre[\s>].*?<\/pre>|<code[\s>].*?<\/code>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = (string) preg_replace_callback('/\{color=([a-z]+)\}(.+?)\{\/color\}/su', function (array $match) {
                    return in_array($match[1], self::TEXT_COLOURS, true)
                        ? '<span class="tc-'.$match[1].'">'.$match[2].'</span>'
                        : $match[0];
                }, $part);
            }
        }

        return implode('', $parts);
    }

    /**
     * "{size=large}text{/size}" becomes <span class="ts-large">, except inside code. Only the
     * named sizes are accepted; anything else is left untouched.
     */
    private static function sizes(string $html): string
    {
        $parts = preg_split('/(<pre[\s>].*?<\/pre>|<code[\s>].*?<\/code>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = (string) preg_replace_callback('/\{size=([a-z]+)\}(.+?)\{\/size\}/su', function (array $match) {
                    return in_array($match[1], self::TEXT_SIZES, true)
                        ? '<span class="ts-'.$match[1].'">'.$match[2].'</span>'
                        : $match[0];
                }, $part);
            }
        }

        return implode('', $parts);
    }

    /**
     * "@[id:Name]" becomes a mention chip <span class="mention" data-user="id">@Name</span>, except
     * inside code. The name is taken from the stored token (already HTML-escaped by the renderer).
     */
    private static function mentions(string $html): string
    {
        $parts = preg_split('/(<pre[\s>].*?<\/pre>|<code[\s>].*?<\/code>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = (string) preg_replace_callback('/@\[(\d+):([^\]]+)\]/u', function (array $match) {
                    return '<span class="mention" data-user="'.$match[1].'">@'.$match[2].'</span>';
                }, $part);
            }
        }

        return implode('', $parts);
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
     * Live content embedded in a page: its linked tasks, the next two weeks, its subpages, a
     * shopping list, or a collection (optionally through one of its saved views) of the
     * household.
     *
     * @param  array<string, string>  $attributes
     */
    private static function embed(Item $page, User $viewer, string $type, bool $interactive, array $attributes = []): string
    {
        return match ($type) {
            'tasks' => self::embedTasks($page, $viewer, $interactive && $page->collection_id === null),
            'calendar' => self::embedCalendar($page, $viewer),
            'subpages' => self::embedSubpages($page, $viewer),
            'shopping' => self::embedShopping($page, (int) ($attributes['list'] ?? 0)),
            'collection' => self::embedCollection($page, $viewer, (int) ($attributes['collection'] ?? 0), isset($attributes['view']) ? (int) $attributes['view'] : null),
            default => '',
        };
    }

    /**
     * Up to 15 entries of a collection, live — through one of its saved views when given (same
     * filter, hidden-done and sort it has in the full collection), otherwise unfiltered. A
     * private collection, or one from another household, renders as "no longer available"
     * rather than a 404: the embed must degrade gracefully for every other viewer of the page.
     */
    private static function embedCollection(Item $page, User $viewer, int $collectionId, ?int $viewId): string
    {
        $collection = self::visibleCollection($viewer, $page->household_id, $collectionId);

        if (! $collection) {
            return self::embedFrame('🗂️ '.__('Collection'), '<p class="page-embed-empty">'.e(__('This collection is no longer available.')).'</p>');
        }

        // A direct query, not the $collection->properties relation: Collection::properties() has
        // no generic return-type annotation, so the relation would type as a bare Model here.
        $properties = CollectionProperty::where('collection_id', $collection->id)->orderBy('position')->orderBy('id')->get();
        $entries = Item::query()->forHousehold($page->household_id)->visibleTo($viewer)
            ->where('collection_id', $collection->id)->orderBy('position')->orderBy('id')->get();

        $view = $viewId ? self::collectionView($collection->id, $viewId) : null;
        $settings = $view ? $view->settings : [];

        if (! empty($settings['filters'])) {
            $entries = EntryFilters::apply($entries, EntryFilters::sanitize($settings['filters'], $properties), $properties, $viewer->id, ($settings['filterMatch'] ?? 'all') === 'any' ? 'or' : 'and');
        }

        if (! empty($settings['hideDone']) && $collection->isTasks()) {
            $entries = $entries->reject(fn (Item $entry) => $entry->done);
        }

        if (! empty($settings['sort'])) {
            // Scoped to this collection: a view's sort setting must not resolve to a property of
            // another collection (or household) just because the numeric id happens to exist.
            $sortProperty = CollectionProperty::where('collection_id', $collection->id)->find((int) $settings['sort']);
            $key = fn (Item $entry) => $sortProperty ? $entry->propertyValue($sortProperty) : mb_strtolower($entry->title);
            $entries = (($settings['direction'] ?? 'asc') === 'desc' ? $entries->sortByDesc($key) : $entries->sortBy($key))->values();
        }

        // Up to three columns (the title is always shown): relations need extra lookups and are
        // left out of the compact table.
        $columns = $properties->reject(fn (CollectionProperty $property) => $property->type === CollectionProperty::TYPE_RELATION)->take(3)->values();
        $shown = $entries->take(15);

        $label = ($collection->icon ?: '🗂️').' '.$collection->name.($view ? ' – '.$view->name : '');
        $link = sprintf('<p class="page-embed-empty"><a href="%s">%s</a></p>', e(route('collections.show', $collection->id)), e(__('Open collection')));
        $more = $entries->count() > 15 ? sprintf('<p class="page-embed-empty">%s</p>', e(__(':count more …', ['count' => $entries->count() - 15]))) : '';

        if ($shown->isEmpty()) {
            return self::embedFrame($label, '<p class="page-embed-empty">'.e(__('No entries here (yet).')).'</p>'.$link);
        }

        // A list when there are no extra columns (same look as before), otherwise a compact table.
        if ($columns->isEmpty()) {
            $rows = $shown->map(fn (Item $entry) => sprintf(
                '<li><a href="%s"%s>%s</a></li>',
                e(route('collections.show', ['collection' => $collection->id, 'entry' => $entry->id])),
                $collection->isTasks() && $entry->done ? ' class="is-done"' : '',
                e($entry->title),
            ));

            return self::embedFrame($label, '<ul class="page-embed-subpages">'.$rows->implode('').'</ul>'.$more.$link);
        }

        $memberNames = self::personNames($columns, $shown);

        $head = '<tr><th>'.e($collection->name).'</th>'.$columns->map(fn (CollectionProperty $property) => '<th>'.e($property->name).'</th>')->implode('').'</tr>';
        $body = $shown->map(function (Item $entry) use ($collection, $columns, $memberNames) {
            $cells = $columns->map(fn (CollectionProperty $property) => '<td>'.e(self::formatCell($property, $entry, $memberNames)).'</td>')->implode('');

            return sprintf(
                '<tr><td><a href="%s"%s>%s</a></td>%s</tr>',
                e(route('collections.show', ['collection' => $collection->id, 'entry' => $entry->id])),
                $collection->isTasks() && $entry->done ? ' class="is-done"' : '',
                e($entry->title),
                $cells,
            );
        })->implode('');

        return self::embedFrame($label, '<div class="page-embed-tablewrap"><table class="page-embed-table"><thead>'.$head.'</thead><tbody>'.$body.'</tbody></table></div>'.$more.$link);
    }

    /**
     * Names of the members referenced by person columns of the shown entries (id => name), so the
     * table can print "Anna" instead of a raw id.
     *
     * @param  \Illuminate\Support\Collection<int, CollectionProperty>  $columns
     * @param  \Illuminate\Support\Collection<int, Item>  $entries
     * @return array<int, string>
     */
    private static function personNames($columns, $entries): array
    {
        $personColumns = $columns->where('type', CollectionProperty::TYPE_PERSON);

        if ($personColumns->isEmpty()) {
            return [];
        }

        $ids = $entries->flatMap(fn (Item $entry) => $personColumns->map(fn (CollectionProperty $property) => $entry->propertyValue($property)))
            ->filter()->map(fn ($value) => (int) $value)->unique()->values()->all();

        return $ids === [] ? [] : User::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * A property value of an entry as a short, human-readable string for the embed table.
     *
     * @param  array<int, string>  $memberNames
     */
    private static function formatCell(CollectionProperty $property, Item $entry, array $memberNames): string
    {
        $value = $entry->propertyValue($property);

        return match ($property->type) {
            CollectionProperty::TYPE_CHECKBOX => $value ? '✓' : '–',
            CollectionProperty::TYPE_DATE => $value ? (self::parseDue((string) $value)?->format('d.m.Y') ?? (string) $value) : '',
            CollectionProperty::TYPE_SELECT => $property->option((string) $value)['name'] ?? '',
            CollectionProperty::TYPE_MULTI_SELECT => collect((array) $value)
                ->map(fn ($id) => $property->option((string) $id)['name'] ?? null)->filter()->implode(', '),
            CollectionProperty::TYPE_PERSON => $value !== null ? ($memberNames[(int) $value] ?? '') : '',
            CollectionProperty::TYPE_URL => $value ? (string) (parse_url((string) $value, PHP_URL_HOST) ?: $value) : '',
            CollectionProperty::TYPE_NUMBER => $value === null ? '' : rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ','),
            default => (string) ($value ?? ''),
        };
    }

    /**
     * The open items of a shopping list of the page's household, always up to date.
     */
    private static function embedShopping(Item $page, int $listId): string
    {
        $list = ShoppingList::where('household_id', $page->household_id)->find($listId);

        if (! $list) {
            return self::embedFrame('🛒 '.__('Shopping list'), '<p class="page-embed-empty">'.e(__('This shopping list no longer exists.')).'</p>');
        }

        $open = $list->entries()->where('checked', false)->orderBy('position')->get();
        $rows = $open->map(fn ($entry) => sprintf('<li>%s%s</li>', e($entry->label), $entry->quantity ? ' <span class="page-embed-meta">'.e($entry->quantity).'</span>' : ''));
        $link = sprintf('<p class="page-embed-empty"><a href="%s">%s</a></p>', e(route('shopping-list.index', ['list' => $list->id])), e(__('Open shopping list')));

        return self::embedFrame(($list->icon ?: '🛒').' '.$list->name, ($rows->isEmpty()
            ? '<p class="page-embed-empty">'.e(__('Everything bought – the list is empty.')).'</p>'
            : '<ul class="page-embed-subpages">'.$rows->implode('').'</ul>').$link);
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

    private static function visibleCollection(User $viewer, int $householdId, int $id): ?Collection
    {
        return Collection::query()->visibleTo($viewer, $householdId)->with('properties')->whereKey($id)->first();
    }

    private static function collectionView(int $collectionId, int $viewId): ?CollectionView
    {
        return CollectionView::where('collection_id', $collectionId)->find($viewId);
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
