// Service worker: offline view of the shopping list, dashboard, tasks and inbox, and push notifications.
// - Built assets (/build/…) are cached on first use (their file names change with every build).
// - These pages are fetched from the network first; without a connection the last
//   loaded version is shown. Everything else always goes to the network.
// The page cache is cleared on the login/welcome pages (see resources/js/app.js).
const ASSET_CACHE = 'e11lifeos-assets-v1';
const PAGE_CACHE = 'e11lifeos-pages';
const OFFLINE_PAGES = ['shopping-list', 'dashboard', 'tasks', 'inbox'];

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('e11lifeos-assets-') && key !== ASSET_CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const scope = self.registration.scope;

    if (request.method !== 'GET' || !request.url.startsWith(scope)) {
        return;
    }

    const path = request.url.slice(scope.length).split(/[?#]/)[0];

    if (path.startsWith('build/')) {
        event.respondWith(
            caches.open(ASSET_CACHE).then(async (cache) => {
                const cached = await cache.match(request);

                if (cached) {
                    return cached;
                }

                const response = await fetch(request);

                if (response.ok) {
                    cache.put(request, response.clone());
                }

                return response;
            }),
        );

        return;
    }

    // Full page loads and wire:navigate fetches (both return the whole page).
    if (OFFLINE_PAGES.includes(path) && (request.mode === 'navigate' || request.headers.has('X-Livewire-Navigate'))) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok && !response.redirected) {
                        const copy = response.clone();
                        caches.open(PAGE_CACHE).then((cache) => cache.put(scope + path, copy));
                    }

                    return response;
                })
                .catch(async () => (await caches.match(scope + path, { cacheName: PAGE_CACHE })) ?? Response.error()),
        );
    }
});

// Push notifications carry no payload: fetch the texts from the app and show them.
// Every push must show a notification (Safari revokes the permission otherwise).
self.addEventListener('push', (event) => {
    const scope = self.registration.scope;

    event.waitUntil(
        fetch(scope + 'push/pending', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : { messages: [] }))
            .catch(() => ({ messages: [] }))
            .then(({ messages }) => {
                if (!messages || messages.length === 0) {
                    messages = [{ title: 'E11LifeOS', body: '', url: scope, tag: 'e11-generic' }];
                }

                return Promise.all(messages.map((message) => self.registration.showNotification(message.title, {
                    body: message.body,
                    tag: message.tag,
                    icon: scope + 'icons/icon-192.png',
                    badge: scope + 'icons/icon-192.png',
                    data: { url: message.url },
                })));
            }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || self.registration.scope;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const open = windows.find((client) => client.url.startsWith(self.registration.scope));

            if (open) {
                return open.focus().then((client) => client.navigate(url));
            }

            return self.clients.openWindow(url);
        }),
    );
});
