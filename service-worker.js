/**
 * Service worker for the registration flow only.
 * Registered with an explicit `{ scope: '/register' }` (see register-offline.js),
 * so it never controls /admin or any other part of the site — navigations to
 * any other page are not intercepted by this worker at all (scope matching
 * happens before this file's fetch handler ever runs).
 *
 * Bump CACHE_VERSION on any change to PRECACHE_URLS or the caching strategy
 * below — the old cache is deleted on activate.
 */
'use strict';

var CACHE_VERSION = 'v4';
var SHELL_CACHE = 'cdti-register-shell-' + CACHE_VERSION;
var OFFLINE_FALLBACK_URL = '/assets/offline-fallback.html';

var PRECACHE_URLS = [
  OFFLINE_FALLBACK_URL,
  '/offline-kiosk.html',
  '/assets/js/offline-fallback.js',
  '/assets/css/main.css',
  '/assets/js/offline-db.js',
  '/assets/js/sync-engine.js',
  '/assets/js/register-offline.js',
  '/assets/js/kiosk-db.js',
  '/assets/js/kiosk-app.js',
  '/assets/vendor/jquery/jquery-3.6.0.min.js',
  '/assets/vendor/bootstrap/css/bootstrap.min.css',
  '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
  '/assets/vendor/uikit/css/uikit.min.css',
  '/assets/vendor/uikit/js/uikit.min.js',
  '/assets/vendor/sweetalert2/sweetalert2.min.css',
  '/assets/vendor/sweetalert2/sweetalert2.all.min.js',
  '/assets/vendor/fontawesome/css/all.min.css',
  '/assets/vendor/fontawesome/webfonts/fa-solid-900.woff2',
  '/assets/vendor/fontawesome/webfonts/fa-regular-400.woff2',
  '/assets/vendor/fontawesome/webfonts/fa-brands-400.woff2',
  '/assets/img/logo.png',
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(function (cache) {
      // Individual adds, not cache.addAll() — one bad/slow URL shouldn't
      // sink the whole precache and leave the worker with nothing cached.
      return Promise.all(
        PRECACHE_URLS.map(function (url) {
          return cache.add(url).catch(function (err) {
            console.warn('[service-worker] precache failed for', url, err);
          });
        })
      );
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (names) {
      return Promise.all(
        names
          .filter(function (n) { return n.indexOf('cdti-register-shell-') === 0 && n !== SHELL_CACHE; })
          .map(function (n) { return caches.delete(n); })
      );
    }).then(function () { return self.clients.claim(); })
  );
});

// Cache-first, background revalidate: instant load from cache, then quietly
// refresh in the background so the *next* visit picks up any change. Used
// for the page shell and vendor libraries — large, effectively static.
function cacheFirstRevalidate(request) {
  return caches.open(SHELL_CACHE).then(function (cache) {
    return cache.match(request).then(function (cached) {
      var networkFetch = fetch(request).then(function (response) {
        if (response && response.ok) cache.put(request, response.clone());
        return response;
      }).catch(function () { return null; });

      if (cached) {
        networkFetch.catch(function () {}); // fire-and-forget revalidation
        return cached;
      }
      return networkFetch.then(function (response) {
        return response || caches.match(OFFLINE_FALLBACK_URL);
      });
    });
  });
}

// Network-first, cache fallback: always run the latest version when online;
// only fall back to whatever was last cached when a real fetch fails. Used
// for this app's own logic (not the vendor libraries) — those get iterated
// on, and a bug fix here needs to take effect on the very next online page
// load rather than waiting on a service-worker update-and-reload cycle.
// App JS paths that get network-first treatment so bug fixes propagate on
// the next online load without waiting for a SW update cycle. Includes all
// kiosk files alongside the standard registration scripts.
var APP_JS_PATHS = [
  '/assets/js/offline-db.js',
  '/assets/js/register-offline.js',
  '/assets/js/sync-engine.js',
  '/assets/js/kiosk-db.js',
  '/assets/js/kiosk-app.js',
];

function networkFirstWithCacheFallback(request) {
  return caches.open(SHELL_CACHE).then(function (cache) {
    return fetch(request).then(function (response) {
      if (response && response.ok) cache.put(request, response.clone());
      return response;
    }).catch(function () {
      return cache.match(request).then(function (cached) {
        return cached || caches.match(OFFLINE_FALLBACK_URL);
      });
    });
  });
}

self.addEventListener('fetch', function (event) {
  var request = event.request;

  // The actual form submission is a mutating request owned entirely by the
  // sync engine — never intercept it here, let the browser handle it (or
  // fail) exactly as it would with no service worker installed at all.
  if (request.method !== 'GET') return;

  var url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (APP_JS_PATHS.indexOf(url.pathname) !== -1) {
    event.respondWith(networkFirstWithCacheFallback(request));
    return;
  }

  // Kiosk navigation (/offline-kiosk.html) is cache-first like the regular
  // register shell — served from precache so it loads even with zero network.
  if (request.mode === 'navigate' || url.pathname.indexOf('/assets/') === 0
      || url.pathname === '/offline-kiosk.html') {
    event.respondWith(cacheFirstRevalidate(request));
    return;
  }
});
