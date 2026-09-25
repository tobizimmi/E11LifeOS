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


    /**
     * Cooking mode: step navigation, several kitchen timers with alarm, screen wake lock.
     */
    window.Alpine.data('cookingMode', (total) => ({
        step: 0,
        total,
        timers: [],
        wakeLock: null,
        ticker: null,

        init() {
            this.requestWakeLock();
            this.onVisibility = () => document.visibilityState === 'visible' && this.requestWakeLock();
            document.addEventListener('visibilitychange', this.onVisibility);
            this.ticker = setInterval(() => this.tick(), 250);
        },

        destroy() {
            clearInterval(this.ticker);
            document.removeEventListener('visibilitychange', this.onVisibility);
            this.wakeLock?.release().catch(() => {});
        },

        async requestWakeLock() {
            try {
                this.wakeLock = await navigator.wakeLock?.request('screen') ?? null;
            } catch (error) {
                this.wakeLock = null;
            }
        },

        onKey(event) {
            if (['INPUT', 'TEXTAREA'].includes(event.target.tagName)) {
                return;
            }
            if (event.key === 'ArrowRight') this.next();
            if (event.key === 'ArrowLeft') this.previous();
        },

        next() {
            this.step = Math.min(this.total, this.step + 1);
        },

        previous() {
            this.step = Math.max(0, this.step - 1);
        },

        startTimer(seconds, label) {
            this.timers.push({ id: Date.now() + Math.random(), label, remaining: seconds, endsAt: Date.now() + seconds * 1000, alerted: false });
        },

        toggleTimer(timer) {
            timer.endsAt = timer.endsAt ? null : Date.now() + timer.remaining * 1000;
        },

        removeTimer(timer) {
            this.timers = this.timers.filter((other) => other.id !== timer.id);
        },

        tick() {
            this.timers.forEach((timer) => {
                if (! timer.endsAt) {
                    return;
                }
                timer.remaining = Math.max(0, Math.ceil((timer.endsAt - Date.now()) / 1000));
                if (timer.remaining === 0 && ! timer.alerted) {
                    timer.alerted = true;
                    timer.endsAt = null;
                    this.alarm();
                }
            });
        },

        alarm() {
            navigator.vibrate?.([300, 150, 300, 150, 300]);
            try {
                const audio = new (window.AudioContext || window.webkitAudioContext)();
                [0, 0.4, 0.8].forEach((offset) => {
                    const oscillator = audio.createOscillator();
                    oscillator.frequency.value = 880;
                    oscillator.connect(audio.destination);
                    oscillator.start(audio.currentTime + offset);
                    oscillator.stop(audio.currentTime + offset + 0.25);
                });
            } catch (error) {
                // No audio available – the pulsing timer is the fallback.
            }
        },

        format(seconds) {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            const rest = String(seconds % 60).padStart(2, '0');

            return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${rest}` : `${minutes}:${rest}`;
        },
    }));

    /**
     * Barcode scanner for the shopping list: the browser's BarcodeDetector reads EAN/UPC codes
     * from the rear camera; where it is missing (e.g. Safari) the number can be typed.
     */
    window.Alpine.data('barcodeScanner', () => {
        let stream = null;
        let timer = null;

        return {
            visible: false,
            supported: 'BarcodeDetector' in window,
            failed: false,
            manualCode: '',

            async open() {
                this.visible = true;
                this.failed = false;
                this.manualCode = '';

                if (! this.supported) {
                    return;
                }

                try {
                    const detector = new window.BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e'] });
                    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                    this.$refs.video.srcObject = stream;
                    await this.$refs.video.play();

                    timer = setInterval(async () => {
                        const codes = await detector.detect(this.$refs.video).catch(() => []);
                        if (codes.length > 0) {
                            this.found(codes[0].rawValue);
                        }
                    }, 300);
                } catch (error) {
                    this.failed = true;
                    this.stop();
                }
            },

            found(code) {
                navigator.vibrate?.(100);
                this.close();
                this.$wire.lookupBarcode(code);
            },

            submitManual() {
                if (this.manualCode.trim() !== '') {
                    this.found(this.manualCode.trim());
                }
            },

            stop() {
                clearInterval(timer);
                stream?.getTracks().forEach((track) => track.stop());
                stream = null;
            },

            close() {
                this.stop();
                this.visible = false;
            },

            destroy() {
                this.stop();
            },
        };
    });
});
