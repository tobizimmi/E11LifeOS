import sort from '@alpinejs/sort';

import './echo';

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/service-worker.js');
    });
}

document.addEventListener('alpine:init', () => {
    window.Alpine.plugin(sort);
});
