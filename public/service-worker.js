// Minimal service worker: required by some browsers for "Add to Home Screen"
// installability. No caching strategy yet — that's a later, deliberate step.
self.addEventListener('fetch', () => {});
