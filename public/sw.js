self.addEventListener('install', function (event) {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', function () {
    // A fetch handler is required for Android to offer installation.
    // Pages stay on the network so sign-in and reports are never served stale.
});
