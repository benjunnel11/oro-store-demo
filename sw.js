const CACHE_NAME = 'oro-store-v1';
const PRECACHE = [
    '/oro-store/auth/login.php',
    '/oro-store/style.css',
    '/oro-store/admin/admin_layout.css',
    '/oro-store/cashier/cashier_styles.css'
];

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys => Promise.all(
            keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
        )).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', e => {
    if (e.request.method !== 'GET') return;
    e.respondWith(
        fetch(e.request).then(response => {
            if (response.ok && e.request.url.match(/\.(css|js|png|jpg|woff2?)$/)) {
                const clone = response.clone();
                caches.open(CACHE_NAME).then(cache => cache.put(e.request, clone));
            }
            return response;
        }).catch(() => caches.match(e.request))
    );
});
