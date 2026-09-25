import { Editor, Extension, InputRule, mergeAttributes, Node } from '@tiptap/core';
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

function slashCommands(t, onPickFile) {
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
        { title: t.pageLink, hint: '[[ ]]', run: (c) => c.insertContent('[[') },
        ...(onPickFile ? [{ title: t.attachment, hint: '📎', run: (c) => { onPickFile(); return c; } }] : []),
    ];
}

const SlashMenu = Extension.create({
    name: 'slashMenu',

    addOptions() {
        return { translations: {}, onPickFile: null };
    },

    addProseMirrorPlugins() {
        const commands = slashCommands(this.options.translations, this.options.onPickFile);

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
        return { pageTitles: [], translations: {} };
    },

    addProseMirrorPlugins() {
        const { pageTitles, translations } = this.options;

        return [
            Suggestion({
                editor: this.editor,
                pluginKey: new PluginKey('pageLinkAutocomplete'),
                char: '[[',
                allowSpaces: true,
                allowedPrefixes: null,
                items: ({ query }) => {
                    const term = query.replace(/\]+$/, '').trim();
                    const matches = pageTitles.filter((title) => title.toLowerCase().includes(term.toLowerCase())).slice(0, 8);
                    const exists = pageTitles.some((title) => title.toLowerCase() === term.toLowerCase());

                    return term !== '' && !exists
                        ? [...matches.map((title) => ({ title })), { title: term, isNew: true }]
                        : matches.map((title) => ({ title }));
                },
                command: ({ editor, range, props }) => {
                    // Swallow a "]]" that may already follow the cursor.
                    const after = editor.state.doc.textBetween(range.to, Math.min(range.to + 2, editor.state.doc.content.size), '');
                    const to = after === ']]' ? range.to + 2 : range.to;

                    editor
                        .chain()
                        .focus()
                        .insertContentAt({ from: range.from, to }, [
                            { type: 'wikiLink', attrs: { target: props.title } },
                            { type: 'text', text: ' ' },
                        ])
                        .run();
                },
                render: () =>
                    suggestionPopup((button, item) => {
                        const hint = document.createElement('span');
                        hint.className = 'editor-popup-hint';
                        hint.textContent = item.isNew ? '+' : '📄';
                        button.append(hint, document.createTextNode(item.isNew ? `${translations.newPage}: ${item.title}` : item.title));
                    }),
            }),
        ];
    },
});

/**
 * @param {HTMLElement} element     Mount point for the editable area.
 * @param {HTMLElement} bubbleMenu  Toolbar shown above selected text.
 * @param {object} options          { markdown, pageTitles, translations, onChange(markdown), onFiles(files), onPickFile() }
 */
export function createPageEditor(element, bubbleMenu, { markdown, pageTitles, translations, onChange, onFiles, onPickFile }) {
    // The bubble menu plugin attaches the toolbar itself when text is selected
    // (and only ever hides it after having shown it), so start detached.
    bubbleMenu.remove();

    return new Editor({
        element,
        extensions: [
            StarterKit.configure({
                // "++underline++" is not CommonMark: the server would show the plus signs.
                underline: false,
                link: { openOnClick: false, autolink: true, protocols: ['http', 'https', 'mailto'] },
            }),
            TaskList,
            TaskItem.configure({ nested: true }),
            TableKit.configure({ table: { resizable: false } }),
            Placeholder.configure({ placeholder: translations.placeholder }),
            Markdown,
            // Stays inside the Alpine component (moving it to <body> would detach its
            // bindings) but is positioned against the viewport, so nothing covers it.
            BubbleMenu.configure({ element: bubbleMenu, options: { strategy: 'fixed', placement: 'top' } }),
            WikiLink,
            MarkdownImage,
            SlashMenu.configure({ translations, onPickFile }),
            PageLinkAutocomplete.configure({ pageTitles, translations }),
        ],
        content: markdown,
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
        onUpdate: ({ editor }) => onChange(editor.getMarkdown()),
    });
}
