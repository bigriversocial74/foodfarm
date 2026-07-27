# Homestead installable web app

Homestead can be installed from its public landing page or sign-in page as a progressive web app (PWA).

## Install behavior

- The web app manifest opens the authenticated household dashboard.
- The installed app uses standalone display mode when the browser and operating system support it.
- App shortcuts open Pantry, Planning, and Alerts.
- The public landing page exposes an Install Homestead action only after the browser confirms installation is available.
- Registration failures do not block or alter normal web-app use.

## Privacy and cache boundary

The service worker is intentionally static-only.

It may cache:

- shared CSS;
- the PWA registration script;
- the manifest;
- the Homestead icon; and
- the generic offline page.

It must not cache:

- HTML navigation responses;
- authenticated household pages;
- PHP routes;
- API or protected health responses;
- database, documentation, test, or command paths;
- POST or other write requests; or
- cross-origin resources.

When navigation fails offline, Homestead displays a generic offline screen. Household data is not exposed from an offline page cache.

## Update behavior

- The service worker script bypasses browser update caching.
- Static cache names are versioned.
- Old Homestead static caches are deleted during activation.
- Existing cached static assets may be served while a fresh copy is retrieved.

## Validation

Run:

```bash
php tests/pwa-policy-test.php
```

The test validates required files, manifest fields and icon declarations, static-asset allowlisting, network-only sensitive routes, and manifest/service-worker wiring on the landing and sign-in entry points.
