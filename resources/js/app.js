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
});
