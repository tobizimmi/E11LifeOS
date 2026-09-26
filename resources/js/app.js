import sort from '@alpinejs/sort';

import './echo';

const isAuthenticated = !!document.querySelector('meta[name="e11-authenticated"]');
const baseUrl = () => document.querySelector('meta[name="app-base-url"]')?.content?.replace(/\/$/, '') ?? '';

// Offline copies of pages belong to the signed-in user: drop them on the login/welcome pages.
if (!isAuthenticated && 'caches' in window) {
    caches.delete('e11lifeos-pages').catch(() => {});
}

/**
 * Inbox entries and tasks captured while offline wait in localStorage and are sent
 * (with a fresh CSRF token) as soon as the device is online again.
 */
const QUEUE_KEY = 'e11-offline-queue';

window.E11Offline = {
    items() {
        try {
            return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
        } catch {
            return [];
        }
    },

    save(items) {
        localStorage.setItem(QUEUE_KEY, JSON.stringify(items));
        window.dispatchEvent(new CustomEvent('offline-queue', { detail: { count: items.length } }));
    },

    add(kind, text, options = {}) {
        const id = (crypto.randomUUID?.() ?? `${Date.now()}-${Math.random()}`).toString();
        this.save([...this.items(), { id, kind, text, ...options }]);
    },

    flushing: false,

    async flush() {
        if (this.flushing || !navigator.onLine || this.items().length === 0) {
            return;
        }

        this.flushing = true;
        let sent = 0;

        try {
            const tokenResponse = await fetch(`${baseUrl()}/offline/token`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

            if (!tokenResponse.ok) {
                return;
            }

            const { token } = await tokenResponse.json();

            for (const item of this.items()) {
                const response = await fetch(`${baseUrl()}/offline/capture`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify(item),
                });

                // Server errors and rate limits: try again later. Everything else is done with.
                if (response.status >= 500 || response.status === 429 || response.status === 419) {
                    break;
                }

                this.save(this.items().filter((queued) => queued.id !== item.id));
                sent += response.ok ? 1 : 0;
            }
        } catch {
            // Still no connection: keep everything for the next attempt.
        } finally {
            this.flushing = false;
        }

        if (sent > 0) {
            const template = document.querySelector('meta[name="e11-offline-sent"]')?.content ?? ':count sent';
            window.dispatchEvent(new CustomEvent('toast', { detail: { message: template.replace(':count', sent) } }));
            window.Livewire?.dispatch('offline-queue-flushed');
        }
    },
};

if (isAuthenticated) {
    window.addEventListener('online', () => window.E11Offline.flush());
    window.addEventListener('load', () => window.E11Offline.flush());
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // Base URL is injected server-side (works whether the app is deployed
        // at the domain root or under a path, e.g. https://host/E11LifeOS).
        navigator.serviceWorker.register(`${baseUrl()}/service-worker.js`, { scope: `${baseUrl()}/` });
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
            // Raw HTML has no editor equivalent and would be dropped on save.
            hasUnsupportedContent: false,
            uploadStatus: '',

            async init() {
                try {
                    const { createPageEditor } = await import('./editor');

                    editor = createPageEditor(this.$refs.editor, this.$refs.bubble, {
                        markdown: this.$wire.body ?? '',
                        pageTitles,
                        translations,
                        onChange: (markdown) => this.$wire.$set('body', markdown, false),
                        onFiles: (files) => this.uploadFiles(files),
                        onPickFile: () => this.pickFile(),
                        searchLinks: (term) => this.$wire.linkTargets(term),
                    });
                    editor.on('selectionUpdate', () => this.selectionTick++);
                    editor.on('transaction', () => this.selectionTick++);
                    this.hasUnsupportedContent = /<[a-z!/]/i.test(this.$wire.body ?? '');
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

            pickFile() {
                this.$refs.file?.click();
            },

            /**
             * Uploads files one after another and inserts each at the cursor
             * (images inline, other files as a link).
             */
            async uploadFiles(files) {
                for (const file of Array.from(files)) {
                    this.uploadStatus = `${translations.uploading} ${file.name}`;

                    try {
                        await new Promise((resolve, reject) => this.$wire.upload('attachmentUpload', file, resolve, reject));
                        const result = await this.$wire.storeAttachment();

                        if (!result) {
                            throw new Error('rejected');
                        }

                        this.insertAttachment(result);
                        this.uploadStatus = '';
                    } catch (error) {
                        this.uploadStatus = translations.uploadFailed;
                    }
                }
            },

            insertAttachment({ markdown, url, name, isImage }) {
                if (editor && this.mode === 'rich') {
                    const content = isImage
                        ? { type: 'image', attrs: { src: url, alt: name } }
                        : { type: 'text', text: `📎 ${name}`, marks: [{ type: 'link', attrs: { href: url } }] };

                    editor.chain().focus().insertContent([content, { type: 'text', text: ' ' }]).run();
                } else {
                    const body = (this.$wire.body ?? '').trimEnd();
                    this.$wire.$set('body', (body ? `${body}\n\n` : '') + markdown + '\n', false);
                    this.$wire.$refresh();
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
     * Barcode scanner for the shopping list: reads EAN/UPC codes from the rear camera with the
     * browser's BarcodeDetector, or with the ZXing library where it is missing (e.g. Safari on
     * iPhone – loaded only then). The number can always be typed instead.
     */
    window.Alpine.data('barcodeScanner', () => {
        let stream = null;
        let timer = null;
        let controls = null;

        return {
            visible: false,
            supported: !!navigator.mediaDevices?.getUserMedia,
            failed: false,
            manualCode: '',

            async open() {
                this.visible = true;
                this.failed = false;
                this.manualCode = '';

                if (!this.supported) {
                    return;
                }

                try {
                    if ('BarcodeDetector' in window) {
                        await this.scanWithDetector();
                    } else {
                        await this.scanWithZxing();
                    }
                } catch (error) {
                    this.failed = true;
                    this.stop();
                }
            },

            async scanWithDetector() {
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
            },

            async scanWithZxing() {
                const { BrowserMultiFormatReader } = await import('@zxing/browser');

                if (!this.visible) {
                    return;
                }

                controls = await new BrowserMultiFormatReader().decodeFromConstraints(
                    { video: { facingMode: 'environment' } },
                    this.$refs.video,
                    (result) => result && this.found(result.getText()),
                );
            },

            found(code) {
                if (!this.visible) {
                    return;
                }

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
                controls?.stop();
                controls = null;
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

    /**
     * Dictation into a text field with the browser's speech recognition (German).
     * The recognised text is appended to the field and sent to Livewire as if typed.
     */
    window.Alpine.data('voiceInput', (inputId, submitWhenDone) => {
        const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        let recognition = null;

        return {
            supported: !!Recognition,
            listening: false,

            toggle() {
                this.listening ? recognition?.stop() : this.start();
            },

            start() {
                const input = document.getElementById(inputId);

                if (!Recognition || !input) {
                    return;
                }

                const before = input.value.trim();
                recognition = new Recognition();
                recognition.lang = document.documentElement.lang || 'de-DE';
                recognition.interimResults = true;
                recognition.continuous = false;

                recognition.onresult = (event) => {
                    const spoken = Array.from(event.results).map((result) => result[0].transcript).join('').trim();
                    input.value = [before, spoken].filter(Boolean).join(' ');
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                };
                recognition.onend = () => {
                    this.listening = false;
                    input.focus();

                    if (submitWhenDone && input.value.trim() !== '' && input.value.trim() !== before) {
                        input.form?.requestSubmit();
                    }
                };
                recognition.onerror = () => {
                    this.listening = false;
                };

                this.listening = true;
                recognition.start();
            },

            destroy() {
                recognition?.abort();
            },
        };
    });

    /**
     * Turns push notifications on/off for this browser (profile page). The subscription
     * is registered with the app, which then wakes the device for reminders.
     */
    window.Alpine.data('pushSettings', (publicKey, subscribeUrl) => {
        const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
        const isAppleMobile = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const keyBytes = (base64) => {
            const raw = atob((base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
            return Uint8Array.from(raw, (char) => char.charCodeAt(0));
        };
        const send = (method, body) => fetch(subscribeUrl, {
            method,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify(body),
        });

        return {
            supported,
            needsHomeScreen: isAppleMobile && !standalone,
            permission: supported ? Notification.permission : 'default',
            subscribed: false,
            busy: false,
            failed: false,

            async init() {
                if (!supported) {
                    return;
                }

                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();
                this.subscribed = !!subscription;

                // Keep the server in sync (e.g. after another person used this device).
                if (subscription) {
                    send('POST', subscription.toJSON());
                }
            },

            async enable() {
                this.busy = true;
                this.failed = false;

                try {
                    this.permission = await Notification.requestPermission();

                    if (this.permission !== 'granted') {
                        return;
                    }

                    const registration = await navigator.serviceWorker.ready;
                    const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) });
                    const response = await send('POST', subscription.toJSON());
                    this.subscribed = response.ok;
                    this.failed = !response.ok;
                    this.$wire.$refresh();
                } catch (error) {
                    console.error(error);
                    this.failed = true;
                } finally {
                    this.busy = false;
                }
            },

            async disable() {
                this.busy = true;

                try {
                    const registration = await navigator.serviceWorker.ready;
                    const subscription = await registration.pushManager.getSubscription();

                    if (subscription) {
                        await send('DELETE', { endpoint: subscription.endpoint });
                        await subscription.unsubscribe();
                    }

                    this.subscribed = false;
                    this.$wire.$refresh();
                } finally {
                    this.busy = false;
                }
            },
        };
    });
});
