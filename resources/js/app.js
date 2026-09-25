import sort from '@alpinejs/sort';

import './echo';

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // Base URL is injected server-side (works whether the app is deployed
        // at the domain root or under a path, e.g. https://host/E11LifeOS).
        const base = document.querySelector('meta[name="app-base-url"]')?.content?.replace(/\/$/, '') ?? '';
        navigator.serviceWorker.register(`${base}/service-worker.js`, { scope: `${base}/` });
    });
}

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(sort);

    /**
     * Page editor (Tiptap) bound to the Livewire "body" property as Markdown.
     * Tiptap is loaded on demand so pages that only display content stay light.
     * The editor instance lives in the closure, not in Alpine's reactive state:
     * wrapping it in a proxy breaks ProseMirror.
     */
    window.Alpine.data('pageEditor', ({ pageTitles, translations }) => {
        let editor = null;

        return {
            mode: 'loading',
            selectionTick: 0,
            // Images and raw HTML have no editor equivalent and would be dropped on save.
            hasUnsupportedContent: false,

            async init() {
                try {
                    const { createPageEditor } = await import('./editor');

                    editor = createPageEditor(this.$refs.editor, this.$refs.bubble, {
                        markdown: this.$wire.body ?? '',
                        pageTitles,
                        translations,
                        onChange: (markdown) => this.$wire.$set('body', markdown, false),
                    });
                    editor.on('selectionUpdate', () => this.selectionTick++);
                    editor.on('transaction', () => this.selectionTick++);
                    this.hasUnsupportedContent = /!\[|<[a-z!/]/i.test(this.$wire.body ?? '');
                    this.mode = this.hasUnsupportedContent ? 'markdown' : 'rich';
                } catch (error) {
                    console.error(error);
                    this.mode = 'markdown';
                }
            },

            destroy() {
                editor?.destroy();
                editor = null;
            },

            toggleMode() {
                if (!editor) {
                    return;
                }

                if (this.mode === 'rich') {
                    this.mode = 'markdown';
                } else {
                    editor.commands.setContent(this.$wire.body ?? '', { contentType: 'markdown', emitUpdate: false });
                    this.mode = 'rich';
                    editor.commands.focus();
                }
            },

            isActive(name, attributes = {}) {
                return this.selectionTick >= 0 && editor?.isActive(name, attributes);
            },

            run(command, ...args) {
                editor?.chain().focus()[command](...args).run();
            },

            setLink() {
                const previous = editor?.getAttributes('link').href ?? '';
                const url = window.prompt(translations.linkPrompt, previous);

                if (url === null) {
                    return;
                }

                if (url.trim() === '') {
                    this.run('unsetLink');
                } else {
                    editor.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run();
                }
            },
        };
    });
});
