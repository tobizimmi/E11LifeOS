import { createAtomBlockMarkdownSpec, createBlockMarkdownSpec, Editor, Extension, InputRule, Mark, markInputRule, mergeAttributes, Node } from '@tiptap/core';
import { BubbleMenu } from '@tiptap/extension-bubble-menu';
import { TaskItem, TaskList } from '@tiptap/extension-list';
import { TableKit } from '@tiptap/extension-table';
import { Placeholder } from '@tiptap/extensions';
import { Markdown } from '@tiptap/markdown';
import { PluginKey, TextSelection } from '@tiptap/pm/state';
import StarterKit from '@tiptap/starter-kit';
import { Suggestion } from '@tiptap/suggestion';

/**
 * Rich-text editor for pages. Content is stored as Markdown (the server renders,
 * searches and resolves [[wiki links]] from it), so the editor reads and writes
 * Markdown only; [[wiki links]] get their own inline node (see WikiLink).
 */

const WIKI_LINK_SOURCE = String.raw`\[\[([^\[\]|]+)(?:\|([^\[\]]+))?\]\]`;

/**
 * [[Title]] / [[Title|Label]] as an inline chip. It is written back to Markdown
 * verbatim (plain text would get its brackets escaped as \[\[ by the serializer).
 */
const WikiLink = Node.create({
    name: 'wikiLink',
    group: 'inline',
    inline: true,
    atom: true,
    selectable: true,

    addAttributes() {
        return {
            // Rendered via data-* attributes in renderHTML, not as raw attributes.
            target: { default: '', rendered: false },
            label: { default: null, rendered: false },
        };
    },

    parseHTML() {
        return [
            {
                tag: 'span[data-wiki-link]',
                getAttrs: (element) => ({
                    target: element.getAttribute('data-wiki-link'),
                    label: element.getAttribute('data-label'),
                }),
            },
        ];
    },

    renderHTML({ node, HTMLAttributes }) {
        return [
            'span',
            mergeAttributes(HTMLAttributes, {
                'data-wiki-link': node.attrs.target,
                'data-label': node.attrs.label,
                class: 'wiki-link',
            }),
            node.attrs.label || node.attrs.target,
        ];
    },

    renderText({ node }) {
        return node.attrs.label ? `[[${node.attrs.target}|${node.attrs.label}]]` : `[[${node.attrs.target}]]`;
    },

    markdownTokenizer: {
        name: 'wikiLink',
        level: 'inline',
        start: (src) => src.indexOf('[['),
        tokenize(src) {
            const match = new RegExp(`^${WIKI_LINK_SOURCE}`).exec(src);

            if (!match) {
                return undefined;
            }

            return { type: 'wikiLink', raw: match[0], target: match[1].trim(), label: match[2]?.trim() || null };
        },
    },

    parseMarkdown: (token, helpers) => helpers.createNode('wikiLink', { target: token.target, label: token.label }),

    renderMarkdown: (node) => (node.attrs?.label ? `[[${node.attrs.target}|${node.attrs.label}]]` : `[[${node.attrs?.target}]]`),

    addInputRules() {
        // Typing the closing "]]" of [[Title]] turns the text into a link chip.
        // (nodeInputRule would only replace the first capture group and leave the brackets.)
        return [
            new InputRule({
                find: new RegExp(`${WIKI_LINK_SOURCE}$`),
                handler: ({ state, range, match }) => {
                    state.tr.replaceWith(
                        range.from,
                        range.to,
                        this.type.create({ target: match[1].trim(), label: match[2]?.trim() || null }),
                    );
                },
            }),
        ];
    },
});

/**
 * ![alt](src) as an inline image (e.g. uploaded page attachments), written back as Markdown.
 */
const MarkdownImage = Node.create({
    name: 'image',
    group: 'inline',
    inline: true,
    atom: true,
    draggable: true,

    addAttributes() {
        return {
            src: { default: '' },
            alt: { default: '' },
            title: { default: null },
        };
    },

    parseHTML() {
        return [{ tag: 'img[src]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['img', mergeAttributes(HTMLAttributes, { class: 'editor-image', loading: 'lazy' })];
    },

    markdownTokenName: 'image',

    parseMarkdown: (token, helpers) => helpers.createNode('image', { src: token.href, alt: token.text || '', title: token.title || null }),

    renderMarkdown: (node) => {
        const alt = (node.attrs?.alt ?? '').replace(/[\[\]]/g, '');
        const title = node.attrs?.title ? ` "${node.attrs.title}"` : '';

        return `![${alt}](${node.attrs?.src ?? ''}${title})`;
    },
});

const CALLOUT_ICONS = { info: 'ℹ️', tip: '💡', warning: '⚠️', danger: '⛔' };
const CALLOUT_TYPES = Object.keys(CALLOUT_ICONS);

/**
 * ==text== as highlighted text (rendered as <mark> on the server).
 */
const Highlight = Mark.create({
    name: 'highlight',

    parseHTML() {
        return [{ tag: 'mark' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['mark', HTMLAttributes, 0];
    },

    markdownTokenizer: {
        name: 'highlight',
        level: 'inline',
        start: (src) => src.indexOf('=='),
        tokenize(src, _tokens, lexer) {
            const match = /^==(?=\S)([\s\S]*?\S)==/.exec(src);

            if (!match) {
                return undefined;
            }

            return { type: 'highlight', raw: match[0], text: match[1], tokens: lexer.inlineTokens(match[1]) };
        },
    },

    parseMarkdown: (token, helpers) => helpers.applyMark('highlight', helpers.parseInline(token.tokens || [])),

    renderMarkdown: (node, h) => `==${h.renderChildren(node)}==`,

    addInputRules() {
        return [markInputRule({ find: /(?:^|\s)(==(?!\s)([^=]+)(?<!\s)==)$/, type: this.type })];
    },

    addKeyboardShortcuts() {
        return { 'Mod-Shift-h': () => this.editor.commands.toggleMark(this.name) };
    },
});

const TEXT_COLORS = ['gray', 'red', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink'];

/**
 * Coloured text ("{color=green}text{/color}"), rendered as <span class="tc-green"> on the server.
 * The colour is a named palette entry so the Markdown stays short and the server can map it to a
 * theme-aware CSS class (see NoteMarkdown::colours()).
 */
const TextColor = Mark.create({
    name: 'textColor',

    addAttributes() {
        return { color: { default: 'gray' } };
    },

    parseHTML() {
        return TEXT_COLORS.map((color) => ({ tag: `span.tc-${color}`, attrs: { color } }));
    },

    renderHTML({ mark, HTMLAttributes }) {
        return ['span', mergeAttributes(HTMLAttributes, { class: `tc-${mark.attrs.color}` }), 0];
    },

    markdownTokenizer: {
        name: 'textColor',
        level: 'inline',
        start: (src) => src.indexOf('{color='),
        tokenize(src, _tokens, lexer) {
            const match = /^\{color=([a-z]+)\}([\s\S]*?)\{\/color\}/.exec(src);

            if (!match || !TEXT_COLORS.includes(match[1])) {
                return undefined;
            }

            return { type: 'textColor', raw: match[0], color: match[1], text: match[2], tokens: lexer.inlineTokens(match[2]) };
        },
    },

    parseMarkdown: (token, helpers) => helpers.applyMark('textColor', helpers.parseInline(token.tokens || []), { color: token.color }),

    renderMarkdown: (node, h) => `{color=${node.attrs?.color ?? 'gray'}}${h.renderChildren(node)}{/color}`,
});

/**
 * Coloured box (":::callout {type="tip"}" … ":::"); the symbol switches the colour.
 */
const Callout = Node.create({
    name: 'callout',
    group: 'block',
    content: 'block+',
    defining: true,

    addOptions() {
        return { translations: {} };
    },

    addAttributes() {
        return { type: { default: 'info' } };
    },

    parseHTML() {
        return [{ tag: 'div[data-callout]', getAttrs: (element) => ({ type: element.getAttribute('data-callout') || 'info' }) }];
    },

    renderHTML({ node, HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { 'data-callout': node.attrs.type, class: `callout callout-${node.attrs.type}` }), 0];
    },

    ...createBlockMarkdownSpec({ nodeName: 'callout', defaultAttributes: { type: 'info' }, allowedAttributes: ['type'] }),

    addNodeView() {
        return ({ node, getPos, editor }) => {
            const dom = document.createElement('div');
            const icon = document.createElement('button');
            const content = document.createElement('div');
            icon.type = 'button';
            icon.contentEditable = 'false';
            icon.className = 'callout-icon';
            icon.title = this.options.translations.calloutTypeHint ?? '';
            content.className = 'callout-body';
            dom.append(icon, content);

            const paint = (current) => {
                dom.className = `callout callout-${current.attrs.type}`;
                icon.textContent = CALLOUT_ICONS[current.attrs.type] ?? CALLOUT_ICONS.info;
            };

            icon.addEventListener('mousedown', (event) => event.preventDefault());
            icon.addEventListener('click', () => {
                const position = getPos();
                const current = typeof position === 'number' ? editor.state.doc.nodeAt(position) : null;

                if (current) {
                    const next = CALLOUT_TYPES[(CALLOUT_TYPES.indexOf(current.attrs.type) + 1) % CALLOUT_TYPES.length];
                    editor.view.dispatch(editor.state.tr.setNodeMarkup(position, undefined, { ...current.attrs, type: next }));
                }
            });
            paint(node);

            return {
                dom,
                contentDOM: content,
                update: (updated) => {
                    if (updated.type.name !== 'callout') {
                        return false;
                    }

                    paint(updated);

                    return true;
                },
                ignoreMutation: (mutation) => !content.contains(mutation.target),
            };
        };
    },
});

/**
 * Section that opens on click (":::toggle {title="…"}" … ":::").
 */
const Toggle = Node.create({
    name: 'toggle',
    group: 'block',
    content: 'block+',
    defining: true,

    addOptions() {
        return { translations: {} };
    },

    addAttributes() {
        return { title: { default: '' } };
    },

    parseHTML() {
        return [{ tag: 'details', getAttrs: (element) => ({ title: element.querySelector('summary')?.textContent ?? '' }) }];
    },

    renderHTML({ node }) {
        return ['details', { class: 'page-toggle', open: 'open' }, ['summary', {}, node.attrs.title], ['div', { class: 'page-toggle-body' }, 0]];
    },

    ...createBlockMarkdownSpec({ nodeName: 'toggle', allowedAttributes: ['title'] }),

    addNodeView() {
        return ({ node, getPos, editor }) => {
            const dom = document.createElement('div');
            const header = document.createElement('div');
            const input = document.createElement('input');
            const content = document.createElement('div');
            dom.className = 'page-toggle';
            header.contentEditable = 'false';
            header.className = 'flex items-center gap-2';
            header.append(Object.assign(document.createElement('span'), { textContent: '▾', ariaHidden: 'true' }), input);
            input.className = 'page-toggle-title';
            input.placeholder = this.options.translations.toggleTitle ?? '';
            input.setAttribute('aria-label', this.options.translations.toggleTitle ?? '');
            input.value = node.attrs.title ?? '';
            content.className = 'page-toggle-body';
            dom.append(header, content);

            input.addEventListener('change', () => {
                const position = getPos();
                const current = typeof position === 'number' ? editor.state.doc.nodeAt(position) : null;

                if (current) {
                    editor.view.dispatch(editor.state.tr.setNodeMarkup(position, undefined, { ...current.attrs, title: input.value.replace(/"/g, '“') }));
                }
            });

            return {
                dom,
                contentDOM: content,
                update: (updated) => {
                    if (updated.type.name !== 'toggle') {
                        return false;
                    }

                    if (document.activeElement !== input) {
                        input.value = updated.attrs.title ?? '';
                    }

                    return true;
                },
                stopEvent: (event) => event.target === input,
                ignoreMutation: (mutation) => !content.contains(mutation.target),
            };
        };
    },
});

/**
 * Multi-column layout (":::columns" with nested ":::column" blocks). Columns sit side by side on
 * wide screens and stack on phones (see .page-columns in the stylesheet). The server renders the
 * same block structure.
 */
const Column = Node.create({
    name: 'column',
    content: 'block+',
    isolating: true,

    parseHTML() {
        return [{ tag: 'div[data-column]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { 'data-column': '', class: 'page-column' }), 0];
    },

    ...createBlockMarkdownSpec({ nodeName: 'column', content: 'block' }),
});

const Columns = Node.create({
    name: 'columns',
    group: 'block',
    content: 'column+',
    defining: true,

    parseHTML() {
        return [{ tag: 'div[data-columns]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { 'data-columns': '', class: 'page-columns' }), 0];
    },

    ...createBlockMarkdownSpec({ nodeName: 'columns', content: 'block' }),
});

/** A columns node with the given number of empty columns, for the slash menu. */
function makeColumns(count) {
    return {
        type: 'columns',
        content: Array.from({ length: count }, () => ({ type: 'column', content: [{ type: 'paragraph' }] })),
    };
}

/**
 * Live block (":::embed {type="tasks"} :::"): the server fills in the page's tasks, the next
 * 14 days of the calendar or the list of subpages; the editor shows a placeholder.
 */
const Embed = Node.create({
    name: 'embed',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addOptions() {
        return { translations: {} };
    },

    addAttributes() {
        return { type: { default: 'tasks' }, list: { default: null }, collection: { default: null }, view: { default: null } };
    },

    parseHTML() {
        return [{
            tag: 'div[data-embed]',
            getAttrs: (element) => ({
                type: element.getAttribute('data-embed') || 'tasks',
                list: element.getAttribute('data-list'),
                collection: element.getAttribute('data-collection'),
                view: element.getAttribute('data-view'),
            }),
        }];
    },

    renderHTML({ node }) {
        const t = this.options.translations;
        const labels = { tasks: `✅ ${t.embedTasks}`, calendar: `📅 ${t.embedCalendar}`, subpages: `📄 ${t.embedSubpages}`, shopping: `🛒 ${t.embedShopping}`, collection: `🗂️ ${t.embedCollection}` };

        return ['div', { 'data-embed': node.attrs.type, 'data-list': node.attrs.list, 'data-collection': node.attrs.collection, 'data-view': node.attrs.view, class: 'editor-embed' }, `${labels[node.attrs.type] ?? node.attrs.type} – ${t.embedHint}`];
    },

    ...createAtomBlockMarkdownSpec({ nodeName: 'embed', defaultAttributes: { type: 'tasks' }, allowedAttributes: ['type', 'list', 'collection', 'view'] }),
});

/**
 * Minimal popup list for suggestions (slash menu, [[ autocomplete):
 * positioned under the cursor, arrow keys + Enter, click to pick.
 */
function suggestionPopup(renderItem) {
    let element;
    let items = [];
    let selected = 0;
    let command;

    const choose = (index) => {
        if (items[index]) {
            command(items[index]);
        }
    };

    const draw = () => {
        element.replaceChildren(
            ...(items.length
                ? items.map((item, index) => {
                      const button = document.createElement('button');
                      button.type = 'button';
                      button.className = 'editor-popup-item' + (index === selected ? ' is-selected' : '');
                      button.setAttribute('role', 'option');
                      button.setAttribute('aria-selected', index === selected ? 'true' : 'false');
                      renderItem(button, item);
                      button.addEventListener('mousedown', (event) => {
                          event.preventDefault();
                          choose(index);
                      });
                      return button;
                  })
                : []),
        );
        element.hidden = items.length === 0;
        element.querySelector('.is-selected')?.scrollIntoView({ block: 'nearest' });
    };

    const place = (clientRect) => {
        const rect = clientRect?.();

        if (!rect) {
            return;
        }

        const top = Math.min(rect.bottom + 6, window.innerHeight - element.offsetHeight - 8);
        const left = Math.min(rect.left, window.innerWidth - element.offsetWidth - 8);
        element.style.top = `${Math.max(8, top)}px`;
        element.style.left = `${Math.max(8, left)}px`;
    };

    return {
        onStart(props) {
            element = document.createElement('div');
            element.className = 'editor-popup';
            element.setAttribute('role', 'listbox');
            document.body.appendChild(element);
            items = props.items;
            command = props.command;
            selected = 0;
            draw();
            place(props.clientRect);
        },
        onUpdate(props) {
            items = props.items;
            command = props.command;
            selected = Math.min(selected, Math.max(0, items.length - 1));
            draw();
            place(props.clientRect);
        },
        onKeyDown({ event }) {
            if (items.length === 0) {
                return false;
            }

            if (event.key === 'ArrowDown') {
                selected = (selected + 1) % items.length;
                draw();
                return true;
            }

            if (event.key === 'ArrowUp') {
                selected = (selected + items.length - 1) % items.length;
                draw();
                return true;
            }

            if (event.key === 'Enter' || event.key === 'Tab') {
                choose(selected);
                return true;
            }

            if (event.key === 'Escape') {
                element.hidden = true;
                return true;
            }

            return false;
        },
        onExit() {
            element?.remove();
            element = null;
        },
    };
}

function slashCommands(t, onPickFile, onPickList) {
    return [
        { title: t.text, hint: '¶', run: (c) => c.setParagraph() },
        { title: t.heading1, hint: 'H1', run: (c) => c.setHeading({ level: 1 }) },
        { title: t.heading2, hint: 'H2', run: (c) => c.setHeading({ level: 2 }) },
        { title: t.heading3, hint: 'H3', run: (c) => c.setHeading({ level: 3 }) },
        { title: t.bulletList, hint: '•', run: (c) => c.toggleBulletList() },
        { title: t.orderedList, hint: '1.', run: (c) => c.toggleOrderedList() },
        { title: t.taskList, hint: '☐', run: (c) => c.toggleTaskList() },
        { title: t.quote, hint: '❝', run: (c) => c.toggleBlockquote() },
        { title: t.codeBlock, hint: '</>', run: (c) => c.toggleCodeBlock() },
        { title: t.divider, hint: '—', run: (c) => c.setHorizontalRule() },
        { title: t.table, hint: '▦', run: (c) => c.insertTable({ rows: 3, cols: 3, withHeaderRow: true }) },
        ...['info', 'tip', 'warning', 'danger'].map((type) => ({
            title: t[`callout${type[0].toUpperCase()}${type.slice(1)}`],
            hint: CALLOUT_ICONS[type],
            run: (c) => c.insertContent({ type: 'callout', attrs: { type }, content: [{ type: 'paragraph' }] }),
        })),
        { title: t.toggle, hint: '▾', run: (c) => c.insertContent({ type: 'toggle', attrs: { title: '' }, content: [{ type: 'paragraph' }] }) },
        { title: t.columns2, hint: '▥', run: (c) => c.insertContent(makeColumns(2)) },
        { title: t.columns3, hint: '◫', run: (c) => c.insertContent(makeColumns(3)) },
        { title: t.embedTasks, hint: '✅', run: (c) => c.insertContent({ type: 'embed', attrs: { type: 'tasks' } }) },
        { title: t.embedCalendar, hint: '📅', run: (c) => c.insertContent({ type: 'embed', attrs: { type: 'calendar' } }) },
        { title: t.embedSubpages, hint: '📄', run: (c) => c.insertContent({ type: 'embed', attrs: { type: 'subpages' } }) },
        { title: t.pageLink, hint: '[[ ]]', run: (c) => c.insertContent('[[') },
        ...(onPickList ? [{ title: t.database, hint: '🗂️', run: (c) => { onPickList(); return c; } }] : []),
        ...(onPickList ? [{ title: t.insertList, hint: '📋', run: (c) => { onPickList(); return c; } }] : []),
        ...(onPickFile ? [{ title: t.attachment, hint: '📎', run: (c) => { onPickFile(); return c; } }] : []),
    ];
}

const SlashMenu = Extension.create({
    name: 'slashMenu',

    addOptions() {
        return { translations: {}, onPickFile: null, onPickList: null };
    },

    addProseMirrorPlugins() {
        const commands = slashCommands(this.options.translations, this.options.onPickFile, this.options.onPickList);

        return [
            Suggestion({
                editor: this.editor,
                pluginKey: new PluginKey('slashMenu'),
                char: '/',
                allowedPrefixes: [' ', ' '],
                items: ({ query }) => commands.filter((item) => (item.title ?? '').toLowerCase().includes(query.toLowerCase())),
                command: ({ editor, range, props }) => props.run(editor.chain().focus().deleteRange(range)).run(),
                render: () =>
                    suggestionPopup((button, item) => {
                        const hint = document.createElement('span');
                        hint.className = 'editor-popup-hint';
                        hint.textContent = item.hint;
                        button.append(hint, document.createTextNode(item.title));
                    }),
            }),
        ];
    },
});

const PageLinkAutocomplete = Extension.create({
    name: 'pageLinkAutocomplete',

    addOptions() {
        return { pageTitles: [], translations: {}, searchLinks: null };
    },

    addProseMirrorPlugins() {
        const { pageTitles, translations, searchLinks } = this.options;

        return [
            Suggestion({
                editor: this.editor,
                pluginKey: new PluginKey('pageLinkAutocomplete'),
                char: '[[',
                allowSpaces: true,
                allowedPrefixes: null,
                // Pages become [[wiki links]]; tasks, entries and recipes (found on the server)
                // become regular links to their stable URL.
                items: async ({ query }) => {
                    const term = query.replace(/\]+$/, '').trim();
                    const matches = pageTitles.filter((title) => title.toLowerCase().includes(term.toLowerCase())).slice(0, 6);
                    const exists = pageTitles.some((title) => title.toLowerCase() === term.toLowerCase());
                    let others = [];

                    if (searchLinks && term !== '') {
                        try {
                            others = (await searchLinks(term)).filter((node) => !node.page).slice(0, 6);
                        } catch (error) {
                            others = [];
                        }
                    }

                    return [
                        ...matches.map((title) => ({ title })),
                        ...others.map((node) => ({ title: node.title, url: node.url, icon: node.icon, kind: node.kind })),
                        ...(term !== '' && !exists ? [{ title: term, isNew: true }] : []),
                    ];
                },
                command: ({ editor, range, props }) => {
                    // Swallow a "]]" that may already follow the cursor.
                    const after = editor.state.doc.textBetween(range.to, Math.min(range.to + 2, editor.state.doc.content.size), '');
                    const to = after === ']]' ? range.to + 2 : range.to;

                    const link = props.url
                        ? { type: 'text', text: `${props.icon} ${props.title}`, marks: [{ type: 'link', attrs: { href: props.url } }] }
                        : { type: 'wikiLink', attrs: { target: props.title } };

                    editor
                        .chain()
                        .focus()
                        .insertContentAt({ from: range.from, to }, [link, { type: 'text', text: ' ' }])
                        .run();
                },
                render: () =>
                    suggestionPopup((button, item) => {
                        const hint = document.createElement('span');
                        hint.className = 'editor-popup-hint';
                        hint.textContent = item.isNew ? '+' : (item.icon ?? '📄');
                        button.append(hint, document.createTextNode(item.isNew ? `${translations.newPage}: ${item.title}` : item.title));

                        if (item.kind) {
                            const kind = document.createElement('span');
                            kind.className = 'editor-popup-kind';
                            kind.textContent = item.kind;
                            button.append(kind);
                        }
                    }),
            }),
        ];
    },
});

/**
 * Column widths of resizable tables: GFM/CommonMark tables have no syntax for a column's width,
 * so a plain pipe table round-tripped through Markdown would always lose a resize. Each table's
 * widths (one per column, 0 for "not resized") are instead carried in an HTML comment right
 * after it — inert in both CommonMark (a raw HTML comment) and in this editor's own renderer
 * (NoteMarkdown strips the same pattern before turning the page into HTML).
 */
const COLWIDTHS_COMMENT = /\n?<!--colwidths:([\d,]*)-->/g;

/**
 * Pulls every "<!--colwidths:…-->" comment out of the raw Markdown (in document order) before
 * it reaches the parser — otherwise it would show up as a literal HTML comment node in the
 * editor. Returns the cleaned Markdown and one width-array per table, in the same order the
 * tables themselves appear.
 *
 * @returns {{ markdown: string, widthsByTable: number[][] }}
 */
/**
 * True when the Markdown has raw HTML the rich editor cannot represent (it would be silently
 * dropped on save) — outside fenced code blocks and inline code spans, where angle brackets are
 * literal text, not real HTML, and round-trip through the editor's own code-block node untouched.
 * A false positive here is not cosmetic: it permanently kicks a page into the plain-textarea
 * fallback, which has no slash menu, no table, no formatting — just raw Markdown.
 */
export function hasUnsupportedHtml(markdown) {
    const withoutColwidthHints = extractColwidthHints(markdown).markdown;
    const withoutCode = withoutColwidthHints.replace(/^```.*$[\s\S]*?^```$/gm, '').replace(/`[^`\n]+`/g, '');

    return /<[a-z!/]/i.test(withoutCode);
}

export function extractColwidthHints(markdown) {
    const widthsByTable = [];
    const cleaned = markdown.replace(COLWIDTHS_COMMENT, (_match, list) => {
        widthsByTable.push(list.length ? list.split(',').map(Number) : []);

        return '';
    });

    return { markdown: cleaned, widthsByTable };
}

/**
 * Column index of a cell within its row (relative to its table), accounting for the colspan of
 * cells before it — one cell spanning 2 columns pushes every following column index up by 1.
 */
function columnIndexAt(table, relativePos) {
    const row = table.resolve(relativePos).parent;
    const cell = table.nodeAt(relativePos);
    let column = 0;

    for (let i = 0; i < row.childCount && row.child(i) !== cell; i++) {
        column += row.child(i).attrs.colspan ?? 1;
    }

    return column;
}

/**
 * Applies previously extracted column widths to the tables now in the document, in order. Cells
 * are addressed by column index (accounting for colspan), not by position, since every cell in a
 * column carries the same colwidth once resized.
 */
function applyColwidthHints(editor, widthsByTable) {
    if (!widthsByTable.length) {
        return;
    }

    const { tr } = editor.state;
    let tableIndex = 0;

    editor.state.doc.descendants((table, tablePos) => {
        if (table.type.name !== 'table') {
            return true;
        }

        const widths = widthsByTable[tableIndex++] ?? [];

        if (widths.length) {
            table.descendants((cell, relativePos) => {
                if (cell.type.name !== 'tableCell' && cell.type.name !== 'tableHeader') {
                    return true;
                }

                const width = widths[columnIndexAt(table, relativePos)];

                if (width) {
                    tr.setNodeAttribute(tablePos + 1 + relativePos, 'colwidth', [width]);
                }

                return false;
            });
        }

        return false;
    });

    if (tr.docChanged) {
        editor.view.dispatch(tr);
    }
}

/**
 * Widens or narrows the column the cursor is currently in by a fixed step (every cell of that
 * column together, like a resize-handle drag) — a tap-friendly alternative to the drag handle for
 * touch screens, where dragging a 4px column border reliably is impractical.
 */
export function nudgeColumnWidth(editor, delta) {
    const { state, view } = editor;
    const { $from } = state.selection;
    let tablePos = null;
    let cellPos = null;

    for (let d = $from.depth; d > 0; d--) {
        const name = $from.node(d).type.name;

        if (name === 'tableCell' || name === 'tableHeader') {
            cellPos = $from.before(d);
        }

        if (name === 'table') {
            tablePos = $from.before(d);
            break;
        }
    }

    if (tablePos === null || cellPos === null) {
        return;
    }

    const table = state.doc.nodeAt(tablePos);
    const column = columnIndexAt(table, cellPos - tablePos - 1);
    const [currentWidth] = table.nodeAt(cellPos - tablePos - 1).attrs.colwidth ?? [];
    const width = Math.max(60, (currentWidth || 150) + delta);
    const tr = state.tr;

    table.descendants((cell, relativePos) => {
        if (cell.type.name !== 'tableCell' && cell.type.name !== 'tableHeader') {
            return true;
        }

        if (columnIndexAt(table, relativePos) === column) {
            tr.setNodeAttribute(tablePos + 1 + relativePos, 'colwidth', [width]);
        }

        return false;
    });

    if (tr.docChanged) {
        view.dispatch(tr);
    }
}

/**
 * Reads the column widths of every resized table in the current document, in order — null for a
 * table nobody has resized (so it keeps auto-sizing instead of being pinned to its last render).
 *
 * @returns {number[][]}
 */
function readColwidthHints(editor) {
    const widthsByTable = [];

    editor.state.doc.descendants((table) => {
        if (table.type.name !== 'table') {
            return true;
        }

        const columns = [];
        const firstRow = table.firstChild;

        firstRow?.forEach((cell) => {
            const [width] = cell.attrs.colwidth ?? [];
            columns.push(width || 0, ...Array((cell.attrs.colspan ?? 1) - 1).fill(0));
        });

        widthsByTable.push(columns.some(Boolean) ? columns : []);

        return false;
    });

    return widthsByTable;
}

/**
 * Re-inserts a "<!--colwidths:…-->" comment after each table in freshly serialised Markdown, in
 * the same order extractColwidthHints() reads them back out.
 */
function injectColwidthHints(markdown, widthsByTable) {
    if (!widthsByTable.some((widths) => widths.length)) {
        return markdown;
    }

    let tableIndex = 0;

    // A pipe-table block: a header row, its "|---|---|" separator, then zero or more data rows.
    return markdown.replace(/^(\|.*\|\n\|[-:| ]+\|\n(?:\|.*\|\n?)*)/gm, (block) => {
        const widths = widthsByTable[tableIndex++] ?? [];

        return widths.length ? `${block.replace(/\n$/, '')}\n<!--colwidths:${widths.join(',')}-->\n` : block;
    });
}

/**
 * @param {HTMLElement} element     Mount point for the editable area.
 * @param {HTMLElement} bubbleMenu  Toolbar shown above selected text.
 * @param {object} options          { markdown, pageTitles, translations, onChange(markdown), onFiles(files), onPickFile(), onPickList(), searchLinks(term) }
 */
export function createPageEditor(element, bubbleMenu, { markdown, pageTitles, translations, onChange, onFiles, onPickFile, onPickList, searchLinks }) {
    const { markdown: cleanedMarkdown, widthsByTable } = extractColwidthHints(markdown);
    // The bubble menu plugin attaches the toolbar itself when text is selected
    // (and only ever hides it after having shown it), so start detached.
    bubbleMenu.remove();

    const editor = new Editor({
        element,
        extensions: [
            StarterKit.configure({
                // "++underline++" is not CommonMark: the server would show the plus signs.
                underline: false,
                link: { openOnClick: false, autolink: true, protocols: ['http', 'https', 'mailto'] },
            }),
            TaskList,
            TaskItem.configure({ nested: true }),
            TableKit.configure({ table: { resizable: true, lastColumnResizable: true } }),
            Placeholder.configure({ placeholder: translations.placeholder }),
            Markdown,
            // Stays inside the Alpine component (moving it to <body> would detach its
            // bindings) but is positioned against the viewport, so nothing covers it.
            BubbleMenu.configure({ element: bubbleMenu, options: { strategy: 'fixed', placement: 'top' } }),
            WikiLink,
            MarkdownImage,
            Highlight,
            TextColor,
            Column,
            Columns,
            Callout.configure({ translations }),
            Toggle.configure({ translations }),
            Embed.configure({ translations }),
            SlashMenu.configure({ translations, onPickFile, onPickList }),
            PageLinkAutocomplete.configure({ pageTitles, translations, searchLinks }),
        ],
        content: cleanedMarkdown,
        contentType: 'markdown',
        editorProps: {
            attributes: { class: 'note-content note-editor', 'aria-label': translations.editorLabel },
            // Files dropped or pasted into the text are uploaded as page attachments.
            handleDrop: (view, event) => {
                const files = event.dataTransfer?.files;

                if (!files?.length) {
                    return false;
                }

                event.preventDefault();
                const position = view.posAtCoords({ left: event.clientX, top: event.clientY });

                if (position) {
                    view.dispatch(view.state.tr.setSelection(TextSelection.near(view.state.doc.resolve(position.pos))));
                }

                onFiles?.(files);

                return true;
            },
            handlePaste: (view, event) => {
                const files = event.clipboardData?.files;

                if (!files?.length) {
                    return false;
                }

                onFiles?.(files);

                return true;
            },
        },
        onUpdate: ({ editor }) => onChange(injectColwidthHints(editor.getMarkdown(), readColwidthHints(editor))),
    });

    applyColwidthHints(editor, widthsByTable);

    return editor;
}
