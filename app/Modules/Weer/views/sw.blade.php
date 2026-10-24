// Plaashek Weather's service worker, written by WeerController::serviceWorker. App-shell cache with
// stale-while-revalidate, as Weather Compare's: answer from the cache at once so the page opens with no
// signal, refresh the copy in the background when there is one. The cache name carries a hash of the
// shell files, so a release replaces it. The weather data itself is cached by the page (IndexedDB and
// localStorage); /weer/api is never touched here.
const CACHE_PREFIX = "plaashek-weer-";
const CACHE = @json($cache);
const SHELL = @json($shell, JSON_UNESCAPED_SLASHES);
const REVALIDATE_TIMEOUT_MS = 10000;

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).catch(() => {}));
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(
    keys.filter((k) => k.startsWith(CACHE_PREFIX) && k !== CACHE).map((k) => caches.delete(k)))));
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);
  if (event.request.method !== "GET" || url.origin !== self.location.origin) return;
  if (url.pathname.startsWith("/weer/api/") || url.pathname === "/weer/sw.js") return;

  // The page is cached by path only: its state lives in the hash.
  const isPageLoad = event.request.mode === "navigate";
  const cacheKey = isPageLoad ? url.origin + url.pathname : event.request;

  const abort = new AbortController();
  const timer = setTimeout(() => abort.abort(), REVALIDATE_TIMEOUT_MS);
  const update = fetch(event.request, { signal: abort.signal })
    .then(async (res) => {
      // A redirect (signed out: off to the login page) is not cached as the page.
      if (res.ok && !res.redirected) {
        const cache = await caches.open(CACHE);
        await cache.put(cacheKey, res.clone());
      }
      return res;
    })
    .catch(() => null)
    .finally(() => clearTimeout(timer));
  event.waitUntil(update);

  event.respondWith(
    caches.open(CACHE)
      .then((cache) => cache.match(cacheKey, { ignoreSearch: isPageLoad }))
      .then((cached) => cached || update.then((res) => res || Response.error())),
  );
});
