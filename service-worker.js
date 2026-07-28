/* Homestead PWA static-asset service worker.
 * Authenticated HTML, PHP routes, APIs, health checks, and write requests
 * are never cached.
 */
'use strict';

const SCOPE_PATH = new URL(self.registration.scope).pathname.replace(/\/$/, '');
const SCOPE_KEY = SCOPE_PATH.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'root';
const CACHE_PREFIX = `homestead-static-${SCOPE_KEY}-`;
const CACHE_NAME = `${CACHE_PREFIX}20260727-3`;
const scopedPath = (path) => `${SCOPE_PATH}/${path.replace(/^\//, '')}`;
const OFFLINE_URL = scopedPath('offline.html');

const STATIC_EXTENSION =
    /\.(?:css|js|svg|png|jpg|jpeg|webp|gif|ico|woff2?|webmanifest)$/i;
const SENSITIVE_PATH =
    /(?:^|\/)(?:api|bin|database|docs|tests)(?:\/|$)|\.php$/i;

const isSameOrigin = (url) => url.origin === self.location.origin;

const networkOnly = async (request) => {
    return fetch(new Request(request, { cache: 'no-store' }));
};

const networkFirstStatic = async (request) => {
    const cache = await caches.open(CACHE_NAME);

    try {
        const response = await fetch(new Request(request, { cache: 'no-store' }));
        if (response.ok && response.type === 'basic') {
            await cache.put(request, response.clone());
        }
        return response;
    } catch (error) {
        const cached = await cache.match(request, { ignoreSearch: true });
        if (cached !== undefined && cached !== null) {
            return cached;
        }
        throw error;
    }
};

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(async (cache) => {
                try {
                    const response = await fetch(
                        new Request(OFFLINE_URL, { cache: 'no-store' })
                    );
                    if (response.ok) {
                        await cache.put(OFFLINE_URL, response);
                    }
                } catch (error) {
                    // Installation remains valid even when the offline file
                    // is temporarily unavailable.
                }
            })
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (!isSameOrigin(url)) {
        return;
    }

    if (request.method !== 'GET' || SENSITIVE_PATH.test(url.pathname)) {
        event.respondWith(networkOnly(request));
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            networkOnly(request).catch(async () => {
                const fallback = await caches.match(
                    OFFLINE_URL,
                    { ignoreSearch: true }
                );
                return fallback ?? Response.error();
            })
        );
        return;
    }

    if (STATIC_EXTENSION.test(url.pathname)) {
        event.respondWith(networkFirstStatic(request));
    }
});
