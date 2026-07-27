/* Homestead PWA static-asset service worker.
 * Authenticated HTML, PHP routes, APIs, health checks, and write requests are never cached.
 */
'use strict';

const CACHE_PREFIX = 'homestead-static-';
const CACHE_NAME = `${CACHE_PREFIX}20260727-1`;
const OFFLINE_URL = '/offline.html';
const STATIC_ASSETS = Object.freeze([
  '/offline.html',
  '/manifest.webmanifest',
  '/assets/css/app.css',
  '/assets/css/app-shell.css',
  '/assets/css/app-shell-base.css',
  '/assets/css/workflow-polish.css',
  '/assets/css/operations-polish.css',
  '/assets/css/mobile-workflows.css',
  '/assets/css/core-pages.css',
  '/assets/css/intelligence-pages.css',
  '/assets/css/access-flow.css',
  '/assets/js/pwa.js',
  '/assets/icons/homestead-icon.svg'
]);

const STATIC_EXTENSION = /\.(?:css|js|svg|png|jpg|jpeg|webp|gif|ico|woff2?|webmanifest)$/i;
const SENSITIVE_PATH = /(?:^|\/)(?:api|bin|database|docs|tests)(?:\/|$)|\.php$/i;

const isSameOrigin = (url) => url.origin === self.location.origin;

const mustUseNetworkOnly = (request, url) => {
  if (request.method !== 'GET') {
    return true;
  }

  if (request.mode === 'navigate') {
    return true;
  }

  if (SENSITIVE_PATH.test(url.pathname)) {
    return true;
  }

  return url.pathname === '/service-worker.js';
};

const networkOnly = async (request) => {
  if (request.method !== 'GET') {
    return fetch(request);
  }

  return fetch(new Request(request, { cache: 'no-store' }));
};

const staticAsset = async (request) => {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request, { ignoreSearch: true });
  const network = fetch(request).then((response) => {
    if (response.ok && response.type === 'basic') {
      void cache.put(request, response.clone());
    }
    return response;
  });

  if (cached !== undefined) {
    void network.catch(() => undefined);
    return cached;
  }

  return network;
};

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(STATIC_ASSETS))
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

  if (request.mode === 'navigate') {
    event.respondWith(
      networkOnly(request).catch(async () => {
        const fallback = await caches.match(OFFLINE_URL, { ignoreSearch: true });
        return fallback ?? Response.error();
      })
    );
    return;
  }

  if (mustUseNetworkOnly(request, url)) {
    event.respondWith(networkOnly(request));
    return;
  }

  if (STATIC_EXTENSION.test(url.pathname)) {
    event.respondWith(staticAsset(request));
  }
});
