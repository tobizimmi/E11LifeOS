import sort from '@alpinejs/sort';
import { browserSupportsWebAuthn, startAuthentication, startRegistration } from '@simplewebauthn/browser';

import './echo';
import 'leaflet/dist/leaflet.css';
import tripMap from './trip-map';

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

    // Trip map with pins per day (Leaflet is loaded only there, see trip-map.js).
    window.Alpine.data('tripMap', tripMap);

    /**
     * Page editor (Tiptap) bound to the Livewire "body" property as Markdown.
     * Tiptap is loaded on demand so pages that only display content stay light.
     * The editor instance lives in the closure, not in Alpine's reactive state:
     * wrapping it in a proxy breaks ProseMirror.
     */
    window.Alpine.data('pageEditor', ({ pageTitles, translations, autosave = false }) => {
        let editor = null;
        let nudgeColumnWidth = null;

        return {
            mode: 'loading',
            selectionTick: 0,
            // Raw HTML has no editor equivalent and would be dropped on save.
            hasUnsupportedContent: false,
            uploadStatus: '',

            async init() {
                try {
                    const editorModule = await import('./editor');
                    const { createPageEditor, hasUnsupportedHtml } = editorModule;
                    nudgeColumnWidth = editorModule.nudgeColumnWidth;

                    editor = createPageEditor(this.$refs.editor, this.$refs.bubble, {
                        markdown: this.$wire.body ?? '',
                        pageTitles,
                        translations,
                        onChange: (markdown) => {
                            this.$wire.$set('body', markdown, false);
                            this.scheduleAutosave();
                        },
                        onFiles: (files) => this.uploadFiles(files),
                        onPickFile: () => this.pickFile(),
                        onPickList: () => this.openListPicker(),
                        searchLinks: (term) => this.$wire.linkTargets(term),
                    });
                    editor.on('selectionUpdate', () => this.selectionTick++);
                    editor.on('transaction', () => this.selectionTick++);
                    this.hasUnsupportedContent = hasUnsupportedHtml(this.$wire.body ?? '');
                    this.mode = this.hasUnsupportedContent ? 'markdown' : 'rich';
                } catch (error) {
                    console.error(error);
                    this.mode = 'markdown';
                }
            },

            destroy() {
                clearTimeout(this.autosaveTimer);
                editor?.destroy();
                editor = null;
            },

            autosaveTimer: null,

            /**
             * Existing pages save title and text a moment after typing stops; the status line
             * next to the save button listens to "page-autosave".
             */
            scheduleAutosave() {
                // Only components with an autosave() method ask for it (pages). Unknown $wire
                // properties are callable proxies, so checking editingId alone is always true.
                if (!autosave || typeof this.$wire.editingId !== 'number') {
                    return;
                }

                clearTimeout(this.autosaveTimer);
                this.autosaveTimer = setTimeout(async () => {
                    const report = (detail) => window.dispatchEvent(new CustomEvent('page-autosave', { detail }));
                    report({ state: 'saving' });

                    try {
                        const at = await this.$wire.autosave(this.$wire.title ?? '', this.$wire.body ?? '');
                        report(at ? { state: 'saved', at } : { state: '' });
                    } catch (error) {
                        report({ state: 'error' });
                    }
                }, 1200);
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

            // "/ Insert list": packing lists, checklists and shopping lists from the server.
            listPicker: { open: false, loading: false, options: [], filter: '' },

            async openListPicker() {
                this.listPicker = { open: true, loading: true, options: [], filter: '' };

                try {
                    this.listPicker.options = await this.$wire.insertableLists();
                } finally {
                    this.listPicker.loading = false;
                }
            },

            listGroups() {
                const term = this.listPicker.filter.trim().toLowerCase();
                const groups = {};

                for (const option of this.listPicker.options) {
                    if (term === '' || option.name.toLowerCase().includes(term)) {
                        (groups[option.group] ??= []).push(option);
                    }
                }

                return Object.entries(groups);
            },

            async insertList(key, live = false) {
                this.listPicker.open = false;

                // "newdb:<template>" creates a fresh collection (database) and embeds it live;
                // everything else is an existing list inserted as checklist or live block.
                const markdown = key.startsWith('newdb:')
                    ? await this.$wire.createDatabase(key.slice(6))
                    : await this.$wire.listMarkdown(key, live);

                if (!editor || !markdown) {
                    return;
                }

                editor.chain().focus().insertContent(markdown, { contentType: 'markdown' }).run();
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

            nudgeTableColumn(delta) {
                if (editor) {
                    nudgeColumnWidth(editor, delta);
                }
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

            // Text colour of the selection; an empty value removes the colour again.
            setTextColor(color) {
                if (!editor) {
                    return;
                }

                if (color) {
                    editor.chain().focus().setMark('textColor', { color }).run();
                } else {
                    editor.chain().focus().unsetMark('textColor').run();
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

                // Wait until Alpine has shown the dialog, so the <video> is laid out before we
                // attach the camera. On iOS Safari, starting playback while the element is still
                // display:none leaves a frozen black frame even after it becomes visible.
                await this.$nextTick();

                if (!this.visible) {
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
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } });

                if (!this.visible) {
                    stream.getTracks().forEach((track) => track.stop());
                    stream = null;

                    return;
                }

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

/**
 * Immediate feedback for every Livewire action: the clicked button (or the submit button of a
 * form) is marked busy until the server answered, and a thin bar appears at the top when a
 * request takes longer than a moment.
 */
const LivewireFeedback = {
    busy: new Set(),
    pending: 0,
    barTimer: null,

    bar() {
        let bar = document.getElementById('e11-progress');

        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'e11-progress';
            bar.setAttribute('aria-hidden', 'true');
            document.body.appendChild(bar);
        }

        return bar;
    },

    mark(element) {
        if (!element || element.hasAttribute('data-busy')) {
            return;
        }

        element.setAttribute('data-busy', '');
        element.setAttribute('aria-busy', 'true');
        this.busy.add(element);
        // Never leave a button stuck if no request follows (e.g. client-only actions).
        setTimeout(() => this.pending === 0 && this.release(element), 400);
    },

    release(element) {
        element.removeAttribute('data-busy');
        element.removeAttribute('aria-busy');
        this.busy.delete(element);
    },

    start() {
        this.pending++;
        clearTimeout(this.barTimer);
        this.barTimer = setTimeout(() => this.bar().classList.add('is-active'), 250);
    },

    done() {
        this.pending = Math.max(0, this.pending - 1);

        if (this.pending === 0) {
            clearTimeout(this.barTimer);
            this.bar().classList.remove('is-active');
            [...this.busy].forEach((element) => this.release(element));
        }
    },
};

document.addEventListener('click', (event) => {
    const trigger = event.target.closest?.('[wire\\:click], [wire\\:click\\.prevent], [wire\\:click\\.stop]');

    if (trigger && !trigger.hasAttribute('wire:confirm')) {
        LivewireFeedback.mark(trigger);
    }
}, true);

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (form?.matches?.('[wire\\:submit], [wire\\:submit\\.prevent]')) {
        LivewireFeedback.mark(event.submitter ?? form.querySelector('[type=submit], button:not([type])'));
    }
}, true);

document.addEventListener('livewire:init', () => {
    window.Livewire.hook('request', ({ succeed, fail }) => {
        LivewireFeedback.start();
        succeed(() => LivewireFeedback.done());
        fail((payload) => {
            LivewireFeedback.done();
            handleExpiredSession(payload);
        });
    });
});

/*
 * Gracefully recover from an expired session (HTTP 419) instead of Livewire's default English
 * "This page has expired." confirm dialog. The app is used like a native app on the home screen,
 * so it is often reopened long after the session lifetime; the first action then fails with 419.
 * We reload the current page silently: the server issues a fresh session + CSRF token, and
 * "remember me" re-authenticates the user on the same URL. A short guard prevents a reload loop.
 */
function handleExpiredSession(payload) {
    if (!payload || payload.status !== 419 || typeof payload.preventDefault !== 'function') {
        return;
    }

    payload.preventDefault();

    let last = 0;
    try {
        last = Number(sessionStorage.getItem('e11-session-expired-reload') || 0);
    } catch {
        last = 0;
    }

    if (Date.now() - last < 8000) {
        return;
    }

    try {
        sessionStorage.setItem('e11-session-expired-reload', String(Date.now()));
    } catch {
        // Ignore storage failures (private mode); the reload still proceeds.
    }

    window.location.reload();
}

/*
 * Physical motion (Apple "Designing Fluid Interfaces"): springs described by damping ratio and
 * response, interruptible (they start from the current value and velocity), plus momentum
 * projection and rubber-banding for gestures.
 */
const Motion = {
    reduced: () => window.matchMedia('(prefers-reduced-motion: reduce)').matches,

    /**
     * Animates a number with a spring; returns a handle whose stop() freezes it where it is.
     */
    spring({ from, to, velocity = 0, damping = 1, response = 0.35, onUpdate, onComplete }) {
        const stiffness = (2 * Math.PI / response) ** 2;
        const friction = (4 * Math.PI * damping) / response;
        let value = from;
        let speed = velocity;
        let last = performance.now();
        let frame = null;

        const step = (now) => {
            const elapsed = Math.min(0.064, (now - last) / 1000);
            last = now;
            const substeps = Math.max(1, Math.ceil(elapsed / 0.004));
            const dt = elapsed / substeps;

            for (let i = 0; i < substeps; i++) {
                speed += (-stiffness * (value - to) - friction * speed) * dt;
                value += speed * dt;
            }

            if (Math.abs(value - to) < 0.5 && Math.abs(speed) < 10) {
                onUpdate(to, 0);
                onComplete?.();

                return;
            }

            onUpdate(value, speed);
            frame = requestAnimationFrame(step);
        };

        frame = requestAnimationFrame(step);

        return {
            stop: () => cancelAnimationFrame(frame),
            get value() { return value; },
            get velocity() { return speed; },
        };
    },

    /** Where a flick would come to rest (Apple's projection, like scroll deceleration). */
    project(velocity, decelerationRate = 0.998) {
        return ((velocity / 1000) * decelerationRate) / (1 - decelerationRate);
    },

    /** Progressive resistance past a boundary. */
    rubberband(overshoot, dimension, constant = 0.55) {
        return (overshoot * dimension * constant) / (dimension + constant * Math.abs(overshoot));
    },

    /** Velocity in px/s from the pointer samples of the last 100 ms. */
    velocity(samples) {
        const now = performance.now();
        const recent = samples.filter((sample) => now - sample.t < 100);

        if (recent.length < 2) {
            return 0;
        }

        const first = recent[0];
        const last = recent[recent.length - 1];

        return ((last.v - first.v) / Math.max(1, last.t - first.t)) * 1000;
    },
};

window.E11Motion = Motion;

document.addEventListener('alpine:init', () => {
    /**
     * Sheet (components/sheet): springs in from the bottom on phones, scales in on larger
     * screens, closes at once on tap (the server follows), and can be dragged down by its
     * grabber or header; a flick is projected to decide between closing and snapping back.
     */
    window.Alpine.data('e11Sheet', (closeMethod) => ({
        offset: 0,
        height: 0,
        animation: null,
        closing: false,
        drag: null,

        isPhone() {
            return window.matchMedia('(max-width: 639px)').matches;
        },

        init() {
            // Start position before the first paint, so the sheet never flashes in its final place.
            if (Motion.reduced()) {
                this.$refs.panel.style.opacity = '0';
                this.$refs.backdrop.style.opacity = '0';
            } else if (this.isPhone()) {
                this.render(window.innerHeight);
            } else {
                this.render(0, 'scale');
            }

            this.$nextTick(() => {
                this.height = this.$refs.panel.offsetHeight || window.innerHeight;

                if (Motion.reduced()) {
                    this.fade(0, 1);
                } else if (this.isPhone()) {
                    this.animateTo(0, 0, { from: this.height, damping: 1, response: 0.35 });
                } else {
                    this.animateTo(1, 0, { from: 0, damping: 1, response: 0.3, property: 'scale' });
                }
            });
        },

        render(value, property = 'offset') {
            const panel = this.$refs.panel;
            const backdrop = this.$refs.backdrop;

            if (property === 'scale') {
                panel.style.transform = `scale(${0.96 + 0.04 * value})`;
                panel.style.opacity = String(Math.max(0, Math.min(1, value)));
                backdrop.style.opacity = String(Math.max(0, Math.min(1, value)));

                return;
            }

            this.offset = value;
            panel.style.transform = `translate3d(0, ${value}px, 0)`;
            backdrop.style.opacity = String(Math.max(0, Math.min(1, 1 - value / Math.max(1, this.height))));
        },

        clear() {
            this.$refs.panel.style.transform = '';
            this.$refs.panel.style.opacity = '';
            this.$refs.backdrop.style.opacity = '';
        },

        animateTo(to, velocity, { from = null, damping = 1, response = 0.35, property = 'offset', onComplete = null } = {}) {
            const start = from ?? (this.animation ? this.animation.value : this.offset);
            this.animation?.stop();
            this.animation = Motion.spring({
                from: start,
                to,
                velocity,
                damping,
                response,
                onUpdate: (value) => this.render(value, property),
                onComplete: () => {
                    this.animation = null;
                    if (onComplete) {
                        onComplete();
                    } else if ((property === 'offset' && to === 0) || (property === 'scale' && to === 1)) {
                        this.clear();
                    }
                },
            });
        },

        fade(from, to, onComplete = null) {
            const panel = this.$refs.panel;
            const backdrop = this.$refs.backdrop;
            [panel, backdrop].forEach((element) => {
                element.style.transition = 'none';
                element.style.opacity = String(from);
            });
            requestAnimationFrame(() => {
                [panel, backdrop].forEach((element) => {
                    element.style.transition = 'opacity 180ms ease';
                    element.style.opacity = String(to);
                });
                setTimeout(() => onComplete?.(), 180);
            });
        },

        close(velocity = 0) {
            if (this.closing) {
                return;
            }

            this.closing = true;
            this.$root.style.pointerEvents = 'none';
            const done = () => this.$wire[closeMethod]();

            if (Motion.reduced()) {
                this.fade(1, 0, done);
            } else if (this.isPhone()) {
                this.animateTo(this.height + 40, velocity, { damping: 1, response: 0.3, onComplete: done });
            } else {
                this.animateTo(0, 0, { from: 1, damping: 1, response: 0.2, property: 'scale', onComplete: done });
            }
        },

        dragStart(event) {
            if (!this.isPhone() || this.closing || event.button > 0 || event.target.closest('button, a, input, select, textarea, [contenteditable]')) {
                return;
            }

            this.animation?.stop();
            this.animation = null;
            this.drag = { startY: event.clientY - this.offset, samples: [{ t: performance.now(), v: this.offset }] };
            event.currentTarget.setPointerCapture(event.pointerId);
        },

        dragMove(event) {
            if (!this.drag) {
                return;
            }

            const raw = event.clientY - this.drag.startY;
            // Down follows the finger 1:1; up resists like a real sheet at its top.
            const value = raw < 0 ? -Motion.rubberband(-raw, this.height) : raw;
            this.drag.samples.push({ t: performance.now(), v: raw });
            this.drag.samples = this.drag.samples.slice(-8);
            this.render(value);
        },

        dragEnd() {
            if (!this.drag) {
                return;
            }

            const velocity = Motion.velocity(this.drag.samples);
            this.drag = null;
            const projected = this.offset + Motion.project(velocity);

            if (projected > this.height * 0.4 || velocity > 900) {
                this.close(velocity);
            } else {
                // Back to the top; a little bounce only because a gesture carried momentum.
                this.animateTo(0, velocity, { damping: 0.8, response: 0.3 });
            }
        },
    }));

    /**
     * Mobile sidebar: follows the finger when swiped to the left and decides by projected
     * momentum whether to close or to spring back.
     */
    window.Alpine.data('e11Drawer', () => ({
        drawerDrag: null,

        backdrop() {
            return document.getElementById('e11-drawer-backdrop') ?? document.createElement('div');
        },

        drawerStart(event) {
            if (window.matchMedia('(min-width: 1024px)').matches || !this.sidebarOpen || event.button > 0) {
                return;
            }

            this.drawerDrag = { x: event.clientX, y: event.clientY, active: false, samples: [], width: this.$el.offsetWidth };
        },

        drawerMove(event) {
            const drag = this.drawerDrag;

            if (!drag) {
                return;
            }

            const dx = event.clientX - drag.x;
            const dy = event.clientY - drag.y;

            if (!drag.active) {
                // Commit to a horizontal swipe only after ~10px, so scrolling and taps still work.
                if (Math.abs(dx) < 10 || Math.abs(dx) < Math.abs(dy)) {
                    if (Math.abs(dy) > 10) {
                        this.drawerDrag = null;
                    }

                    return;
                }

                drag.active = true;
                this.$el.setPointerCapture(event.pointerId);
                this.$el.style.transition = 'none';
            }

            const offset = dx > 0 ? Motion.rubberband(dx, drag.width) : dx;
            drag.offset = offset;
            drag.samples.push({ t: performance.now(), v: dx });
            drag.samples = drag.samples.slice(-8);
            this.$el.style.transform = `translate3d(${offset}px, 0, 0)`;
            this.backdrop().style.opacity = String(Math.max(0, 1 + Math.min(0, offset) / drag.width));
        },

        drawerEnd() {
            const drag = this.drawerDrag;
            this.drawerDrag = null;

            if (!drag?.active) {
                return;
            }

            const velocity = Motion.velocity(drag.samples);
            const shouldClose = (drag.offset ?? 0) + Motion.project(velocity) < -drag.width / 2;
            const drawer = this.$el;
            const backdrop = this.backdrop();

            Motion.spring({
                from: drag.offset ?? 0,
                to: shouldClose ? -drag.width : 0,
                velocity,
                damping: 1,
                response: 0.3,
                onUpdate: (value) => {
                    drawer.style.transform = `translate3d(${value}px, 0, 0)`;
                    backdrop.style.opacity = String(Math.max(0, 1 + Math.min(0, value) / drag.width));
                },
                onComplete: () => {
                    if (shouldClose) {
                        this.sidebarOpen = false;
                    }

                    requestAnimationFrame(() => {
                        drawer.style.transform = '';
                        drawer.style.transition = '';
                        backdrop.style.opacity = '';
                    });
                },
            });
        },
    }));
});

/** A short tap of the vibration motor where the device supports it (Android; ignored elsewhere). */
window.E11Haptic = (duration = 8) => {
    try {
        navigator.vibrate?.(duration);
    } catch {
        // Not allowed here – feedback stays visual.
    }
};

/**
 * Makes a photo smaller before it is uploaded (longest side max. 2400 px, JPEG): phone photos
 * of several MB upload quickly and stay below the server limit. Other files stay as they are.
 */
window.e11ShrinkImage = async (file, maxSize = 2400) => {
    if (!file?.type?.startsWith('image/') || ['image/gif', 'image/svg+xml'].includes(file.type)) {
        return file;
    }

    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, maxSize / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close?.();
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));

        return blob && blob.size < file.size ? new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
    } catch {
        return file;
    }
};

document.addEventListener('alpine:init', () => {
    /**
     * Swipe actions on list rows (touch): the row follows the finger 1:1; on release the
     * projected momentum decides between running the action (the row flies out) and springing
     * back. Crossing the threshold gives a short haptic tick.
     *
     * x-data="e11Swipe({ onRight: () => …, onLeft: () => …, leaveOnRight: true })", the moving
     * part has x-ref="content"; the template can show the actions behind it with `dx`.
     */
    window.Alpine.data('e11Swipe', ({ onRight = null, onLeft = null, leaveOnRight = false, leaveOnLeft = false, enterFromOpposite = false } = {}) => {
        // The position changes every frame: kept outside Alpine's reactivity, only its direction
        // (for the colored actions behind the row) is reactive and changes rarely.
        let offset = 0;
        let rowWidth = 320;

        return {
        dx: 0,
        swiping: false,
        leaving: false,
        gesture: null,
        animation: null,

        width() {
            return rowWidth;
        },

        render(value) {
            offset = value;
            const direction = Math.sign(Math.round(value));

            if (direction !== this.dx) {
                this.dx = direction;
            }

            this.$refs.content.style.transform = value === 0 ? '' : `translate3d(${value}px, 0, 0)`;
        },

        start(event) {
            if (event.pointerType === 'mouse' || event.target.closest('.drag-handle, .task-handle, input, textarea, select, [x-sort\\:item]:not([x-data])')) {
                return;
            }

            this.animation?.stop();
            rowWidth = this.$el.offsetWidth || 320;
            this.gesture = { x: event.clientX - offset, y: event.clientY, active: false, samples: [], armed: false, id: event.pointerId, el: event.currentTarget };
        },

        move(event) {
            const gesture = this.gesture;

            if (!gesture) {
                return;
            }

            const dx = event.clientX - gesture.x;
            const dy = event.clientY - gesture.y;

            if (!gesture.active) {
                if (Math.abs(dx) < 10 || Math.abs(dx) < Math.abs(dy)) {
                    if (Math.abs(dy) > 10) {
                        this.gesture = null;
                    }

                    return;
                }

                gesture.active = true;
                this.swiping = true;
                // Capture on the element that listens, so the finger may leave the row.
                try { gesture.el.setPointerCapture(gesture.id); } catch { /* synthetic or already released pointer */ }
            }

            // Directions without an action resist instead of moving freely.
            const allowed = (dx > 0 && onRight) || (dx < 0 && onLeft);
            const value = allowed ? dx : E11Motion.rubberband(dx, this.width(), 0.15);
            gesture.samples.push({ t: performance.now(), v: dx });
            gesture.samples = gesture.samples.slice(-8);
            this.render(value);

            const armed = allowed && Math.abs(value) > this.width() * 0.35;

            if (armed !== gesture.armed) {
                gesture.armed = armed;
                armed && E11Haptic();
            }
        },

        end() {
            const gesture = this.gesture;
            this.gesture = null;

            if (!gesture?.active) {
                return;
            }

            this.swiping = false;

            // The tap that ends a swipe must not also open the row.
            const swallow = (event) => { event.stopPropagation(); event.preventDefault(); };
            gesture.el.addEventListener('click', swallow, { capture: true, once: true });
            setTimeout(() => gesture.el.removeEventListener('click', swallow, { capture: true }), 400);

            const velocity = E11Motion.velocity(gesture.samples);
            const projected = offset + E11Motion.project(velocity, 0.99);
            const limit = this.width() * 0.35;

            if (projected > limit && offset > 0 && onRight) {
                this.commit(1, velocity, onRight, leaveOnRight);
            } else if (projected < -limit && offset < 0 && onLeft) {
                this.commit(-1, velocity, onLeft, leaveOnLeft);
            } else {
                this.springTo(0, velocity, Math.abs(velocity) > 300 ? 0.8 : 1);
            }
        },

        springTo(target, velocity = 0, damping = 1, onComplete = null) {
            this.animation?.stop();
            this.animation = E11Motion.spring({ from: offset, to: target, velocity, damping, response: 0.3, onUpdate: (value) => this.render(value), onComplete });
        },

        commit(direction, velocity, action, leave) {
            E11Haptic(12);

            if (leave) {
                this.leaving = true;
            }

            // Fly out along the swipe, then act; rows that stay (e.g. moved to another group) come back.
            this.springTo(direction * this.width() * 1.05, velocity, 1, () => {
                Promise.resolve(action()).finally(() => {
                    if (this.$el.isConnected && !leave) {
                        // Paging (e.g. the next month) slides in from the other side.
                        if (enterFromOpposite) {
                            this.render(-direction * this.width() * 0.35);
                        }

                        this.springTo(0);
                    }
                });
            });
        },

        /** Completing by tapping the round checkbox: the row slides out, then the server is told. */
        complete() {
            if (!onRight) {
                return;
            }

            E11Haptic(12);

            if (!leaveOnRight) {
                onRight();

                return;
            }

            this.leaving = true;
            rowWidth = this.$el.offsetWidth || 320;
            this.springTo(this.width() * 1.05, 0, 1, () => onRight());
        },
        };
    });
});

document.addEventListener('alpine:init', () => {
    /**
     * Phone chrome: the page header is the large title; once it has scrolled under the top bar,
     * the bar shows the title small and gets its edge line (a scroll edge instead of a fixed divider).
     */
    window.Alpine.store('chrome', {
        compact: false,
        title: '',
        observer: null,

        watch(header) {
            this.observer?.disconnect();
            this.compact = false;
            this.title = (header.querySelector('h1, h2')?.textContent ?? '').replace(/\s+/g, ' ').trim();
            this.observer = new IntersectionObserver(([entry]) => {
                this.compact = !entry.isIntersecting;
            }, { rootMargin: '-56px 0px 0px 0px', threshold: 0 });
            this.observer.observe(header);
        },
    });
});

// Soft cross-fade after wire:navigate page changes (skipped with reduced motion).
document.addEventListener('livewire:navigated', () => {
    const main = document.getElementById('main-content');

    if (!main || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    main.animate([{ opacity: 0.35, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }], { duration: 220, easing: 'cubic-bezier(0.32, 0.72, 0, 1)' });
});

// Tab rows that scroll sideways fade out at the edge that has more to show (see app.css).
const markScrollableTabs = () => {
    document.querySelectorAll('[role="tablist"]:not(.flex-wrap), nav.overflow-x-auto').forEach((row) => {
        const update = () => {
            const more = row.scrollWidth - row.clientWidth - row.scrollLeft > 4;
            row.classList.toggle('e11-fade-end', more);
            row.classList.toggle('e11-fade-start', row.scrollLeft > 4);
        };

        if (!row.dataset.fadeBound) {
            row.dataset.fadeBound = '1';
            row.addEventListener('scroll', update, { passive: true });
        }

        update();
    });
};

document.addEventListener('livewire:navigated', markScrollableTabs);
window.addEventListener('resize', markScrollableTabs, { passive: true });

// Back links keep the place in a list: a link to the page just left goes back in the history, so
// the list comes back as it was (scroll position, loaded entries) instead of starting at the top.
(() => {
    let stack = [location.href];
    let popped = false;

    window.addEventListener('popstate', () => { popped = true; });
    document.addEventListener('livewire:navigated', () => {
        if (location.href === stack[stack.length - 1]) {
            popped = false;

            return;
        }

        if (popped) {
            stack = stack[stack.length - 2] === location.href ? stack.slice(0, -1) : [location.href];
        } else {
            stack.push(location.href);
        }

        popped = false;
    });
    document.addEventListener('livewire:navigate', (event) => {
        const target = event.detail?.url;
        const previous = stack.length > 1 ? new URL(stack[stack.length - 2]) : null;
        // A plain list link ("← All recipes") also returns to the list as it was filtered.
        const isPrevious = previous && target && (target.href === previous.href || (target.search === '' && target.origin + target.pathname === previous.origin + previous.pathname));

        if (!event.detail?.history && isPrevious) {
            event.preventDefault();
            history.back();
        }
    });
})();

// A page without a header must not keep the previous page's small title.
document.addEventListener('livewire:navigating', () => {
    const chrome = window.Alpine?.store('chrome');

    if (chrome) {
        chrome.observer?.disconnect();
        chrome.compact = false;
        chrome.title = '';
    }
});

// Signs the browser out after the idle time set on the #idle-logout form; activity in any tab
// counts (shared through localStorage). Lives here instead of an inline script so it also
// starts after signing in with wire:navigate under the Content-Security-Policy.
(() => {
    const key = 'e11-last-activity';
    let written = 0;
    let started = false;
    const form = () => document.getElementById('idle-logout');
    const touch = () => {
        const now = Date.now();
        if (now - written < 5000) return;
        written = now;
        try { localStorage.setItem(key, String(now)); } catch (e) {}
    };
    const last = () => { try { return Number(localStorage.getItem(key)) || written; } catch (e) { return written; } };
    const check = () => {
        const limit = Number(form()?.dataset.idleMinutes || 0) * 60000;
        if (limit > 0 && Date.now() - last() > limit) form().submit();
    };
    const start = () => {
        if (started || !form()) return;
        started = true;
        written = 0;
        touch();
        ['pointerdown', 'keydown', 'scroll', 'touchstart'].forEach((name) => window.addEventListener(name, touch, { passive: true }));
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') check(); });
        setInterval(check, 30000);
    };
    document.addEventListener('DOMContentLoaded', start);
    document.addEventListener('livewire:navigated', start);
})();

/**
 * Passkeys: registration in the profile (options and storage through the Livewire component)
 * and sign-in on the login / second-step page (options and check through JSON endpoints).
 * Texts come from data attributes of the element.
 */
document.addEventListener('alpine:init', () => {
    const passkeyError = (error, texts) => (error?.name === 'NotAllowedError' || error?.name === 'AbortError' ? texts.cancelled : texts.failed);

    window.Alpine.data('passkeyRegister', () => ({
        supported: browserSupportsWebAuthn(),
        busy: false,
        error: '',

        async register() {
            this.error = '';
            this.busy = true;

            try {
                const options = await this.$wire.prepare();

                if (!options) {
                    return;
                }

                const credential = await startRegistration({ optionsJSON: JSON.parse(options) });
                await this.$wire.store(JSON.stringify(credential));
            } catch (error) {
                this.error = passkeyError(error, this.$root.dataset);
            } finally {
                this.busy = false;
            }
        },
    }));

    window.Alpine.data('passkeyLogin', () => ({
        supported: browserSupportsWebAuthn(),
        busy: false,
        error: '',

        async signIn() {
            const texts = this.$root.dataset;
            this.error = '';
            this.busy = true;

            try {
                const optionsResponse = await fetch(texts.optionsUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                const credential = await startAuthentication({ optionsJSON: await optionsResponse.json() });
                const response = await fetch(texts.loginUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                    body: JSON.stringify({ credential: JSON.stringify(credential), remember: !!document.getElementById('remember')?.checked }),
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    this.error = data.message || texts.failed;

                    return;
                }

                window.location.href = data.redirect;
            } catch (error) {
                this.error = passkeyError(error, texts);
            } finally {
                this.busy = false;
            }
        },
    }));
});

// Ctrl/⌘ + Enter in a text field sends its form (like in mail apps).
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || !(event.metaKey || event.ctrlKey) || !event.target.matches?.('textarea, input')) {
        return;
    }

    const form = event.target.closest('form');

    if (form) {
        event.preventDefault();
        form.requestSubmit();
    }
});

/*
 * Drafts: forms with data-draft="key" keep what was typed in the browser (localStorage) until
 * they are sent, so a closed tab, a crash or a lost connection does not cost the text. Only
 * text fields bound with wire:model are kept; nothing leaves the device.
 */
const E11Drafts = (() => {
    const prefix = 'e11-draft:';
    const fields = (form) => Array.from(form.querySelectorAll('input[wire\\:model]:not([type=password]):not([type=file]):not([type=checkbox]):not([type=radio]):not([type=hidden]), textarea[wire\\:model]'));
    const storage = () => {
        try {
            return window.localStorage;
        } catch (error) {
            return null;
        }
    };

    const bind = (form) => {
        if (form.dataset.draftBound) {
            return;
        }

        form.dataset.draftBound = '1';
        const key = prefix + form.dataset.draft;
        const store = storage();

        if (!store) {
            return;
        }

        // Restore only into empty fields, then tell Livewire about the values.
        const saved = JSON.parse(store.getItem(key) || 'null');

        if (saved && Date.now() - saved.at < 7 * 24 * 3600 * 1000) {
            let restored = false;

            fields(form).forEach((field) => {
                const name = field.getAttribute('wire:model');

                if (saved.values[name] && !field.value) {
                    field.value = saved.values[name];
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    restored = true;
                }
            });

            if (restored) {
                window.dispatchEvent(new CustomEvent('toast', { detail: { message: form.dataset.draftRestored || 'Draft restored' } }));
            }
        }

        form.addEventListener('input', () => {
            const values = {};
            fields(form).forEach((field) => {
                if (field.value.trim() !== '') {
                    values[field.getAttribute('wire:model')] = field.value;
                }
            });
            Object.keys(values).length ? store.setItem(key, JSON.stringify({ at: Date.now(), values })) : store.removeItem(key);
        });

        form.addEventListener('submit', () => store.removeItem(key));
    };

    const scan = () => document.querySelectorAll('form[data-draft]').forEach(bind);

    return { scan };
})();

document.addEventListener('livewire:navigated', E11Drafts.scan);
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morph.updated', () => E11Drafts.scan());
});

// Installed app on a phone (no browser reload button): pull down at the top to refresh.
(() => {
    const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

    if (!standalone || !('ontouchstart' in window)) {
        return;
    }

    let start = null;
    let direction = null;
    let indicator = null;

    // Inside something that scrolls sideways (sub-menus, tables, carousels) a swipe is never a pull.
    const scrollsSideways = (element) => {
        for (let node = element; node && node !== document.body; node = node.parentElement) {
            const style = window.getComputedStyle(node);

            if (/(auto|scroll)/.test(style.overflowX) && node.scrollWidth > node.clientWidth) {
                return true;
            }
        }

        return false;
    };

    document.addEventListener('touchstart', (event) => {
        const target = event.target;
        start = window.scrollY <= 0 && event.touches.length === 1 && !target.closest('[role=dialog], .ProseMirror, textarea, [data-no-pull]') && !scrollsSideways(target)
            ? { x: event.touches[0].clientX, y: event.touches[0].clientY }
            : null;
        direction = null;
    }, { passive: true });

    document.addEventListener('touchmove', (event) => {
        if (start === null) {
            return;
        }

        const dx = event.touches[0].clientX - start.x;
        const dy = event.touches[0].clientY - start.y;

        // Decide once after a few pixels: only a clearly downward swipe is a pull, a sideways or
        // diagonal one is left alone for the rest of the gesture.
        if (direction === null && Math.hypot(dx, dy) > 10) {
            direction = dy > 0 && Math.abs(dy) > Math.abs(dx) * 2 ? 'pull' : 'other';
        }

        if (direction !== 'pull') {
            indicator?.remove();
            indicator = null;

            return;
        }

        const distance = dy;

        if (distance > 10) {
            indicator ??= Object.assign(document.body.appendChild(document.createElement('div')), { className: 'e11-pull' });
            indicator.style.setProperty('--pull', Math.min(distance, 120) / 120);
            indicator.classList.toggle('is-ready', distance > 80 && Math.abs(dx) < distance / 2);
        }
    }, { passive: true });

    document.addEventListener('touchend', () => {
        if (indicator?.classList.contains('is-ready')) {
            window.location.reload();
        }

        indicator?.remove();
        indicator = null;
        start = null;
        direction = null;
    });
})();

// Recently opened pages for the search palette (this browser only, max. 8).
window.E11Recent = (() => {
    const key = 'e11-recent';
    const read = () => {
        try {
            return JSON.parse(window.localStorage.getItem(key) || '[]');
        } catch (error) {
            return [];
        }
    };

    return {
        list: () => read().filter((item) => item.url !== window.location.pathname + window.location.search).slice(0, 6),
        remember() {
            const title = document.querySelector('header[data-page-header] h2')?.textContent.replace(/\s+/g, ' ').trim();

            if (!title || window.location.pathname === '/dashboard') {
                return;
            }

            const url = window.location.pathname + window.location.search;

            try {
                window.localStorage.setItem(key, JSON.stringify([{ url, title }, ...read().filter((item) => item.url !== url)].slice(0, 8)));
            } catch (error) {
                // Private mode or full storage: no recent list.
            }
        },
    };
})();

document.addEventListener('livewire:navigated', () => window.E11Recent.remember());

// After signing out (login page): forget recent pages and drafts kept in this browser.
if (window.location.pathname === '/login') {
    try {
        Object.keys(window.localStorage).filter((name) => name === 'e11-recent' || name.startsWith('e11-draft:')).forEach((name) => window.localStorage.removeItem(name));
    } catch (error) {
        // Storage not available.
    }
}

// Task and inbox links (data-open-task / data-open-inbox="id") open their window in place. If the
// window does not confirm within two seconds (slow connection, error), the link is followed instead.
document.addEventListener('click', (event) => {
    const link = event.target.closest?.('a[data-open-task], a[data-open-inbox]');

    if (! link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }

    event.preventDefault();
    const kind = link.dataset.openTask !== undefined ? 'task' : 'inbox';
    const id = Number(kind === 'task' ? link.dataset.openTask : link.dataset.openInbox);
    let shown = false;
    const confirm = (e) => { if (Number(e.detail?.id) === id) { shown = true; } };
    window.addEventListener(`${kind}-sheet-shown`, confirm);
    window.dispatchEvent(new CustomEvent(`open-${kind}`, { detail: { id } }));

    setTimeout(() => {
        window.removeEventListener(`${kind}-sheet-shown`, confirm);
        if (! shown) {
            window.Livewire?.navigate ? window.Livewire.navigate(link.href) : (window.location.href = link.href);
        }
    }, 2000);
});
