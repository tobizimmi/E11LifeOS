import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// VITE_REVERB_PATH lets Reverb run behind a reverse-proxy path prefix
// (e.g. "/E11LifeOS" when the app isn't on its own subdomain) — Apache
// strips that prefix before forwarding to the internal Reverb port, so the
// browser-facing path and Reverb's own internal path differ deliberately.
const reverbPath = import.meta.env.VITE_REVERB_PATH ?? '';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    wsPath: reverbPath,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
