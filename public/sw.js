/*
 * Service worker for the member site - static assets only.
 *
 * It exists for two reasons: the site becomes installable (the manifest alone
 * is not enough on Android), and the CSS/JS/fonts that every page needs are
 * served from the cache on a repeat visit instead of the network.
 *
 * It NEVER caches HTML, media (/files/) or anything under /admin: a cached
 * member page or a gated photo would be served to the next person who opens
 * the app, and the media route is token-gated on purpose. Only versioned
 * /assets/ URLs are handled, so a deploy (which bumps ?v=) replaces them.
 */
const CACHE = 'amethyst-assets-v1';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    let url;
    try {
        url = new URL(request.url);
    } catch (e) {
        return;
    }

    if (url.origin !== self.location.origin) {
        return;
    }

    // Immutable build assets only. HTML, /files/ and /admin fall through.
    if (!/\/assets\/(css|js|fonts|img)\//.test(url.pathname)) {
        return;
    }

    event.respondWith(
        caches.open(CACHE).then((cache) =>
            cache.match(request).then((cached) => {
                const network = fetch(request)
                    .then((response) => {
                        if (response && response.ok) {
                            cache.put(request, response.clone());
                        }
                        return response;
                    })
                    .catch(() => cached);

                return cached || network;
            })
        )
    );
});
