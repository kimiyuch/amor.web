// Network-first cache for the app shell and the SDK from esm.sh. API calls are never cached.
const CACHE = "amor-v2";
// Keep in sync with the import in extract.js.
const SDK = "https://esm.sh/@anthropic-ai/sdk@0.133.0?bundle";
const SHELL = [
  "./", "index.html", "styles.css", "app.js", "extract.js", "calendar.js", "manifest.webmanifest",
  "icons/icon.svg", "icons/mascot.svg", "fonts/figtree-600.woff2", "icons/icon-192.png", "icons/icon-512.png", "icons/icon-maskable-512.png", "icons/apple-touch-icon.png",
];

// Caches an esm.sh module and everything it imports, so the SDK also loads offline.
async function cacheModuleGraph(cache, entry) {
  const seen = new Set();
  const queue = [entry];
  while (queue.length && seen.size < 60) {
    const url = queue.shift();
    if (seen.has(url)) continue;
    seen.add(url);
    const response = await fetch(url);
    if (!response.ok) throw new Error(`${url}: ${response.status}`);
    await cache.put(url, response.clone());
    const code = await response.text();
    for (const [, spec] of code.matchAll(/(?:\bfrom|\bimport)\s*\(?\s*["']((?:\/|\.\.?\/)[^"']+)["']/g)) {
      queue.push(new URL(spec, response.url).href);
    }
  }
}

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => Promise.all([cache.addAll(SHELL), cacheModuleGraph(cache, SDK)]))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  const { request } = event;
  if (request.method !== "GET") return;
  const url = new URL(request.url);
  const cacheable = url.origin === self.location.origin || url.hostname === "esm.sh";
  if (!cacheable) return; // api.anthropic.com, Google: straight to the network

  event.respondWith(
    fetch(request)
      .then((response) => {
        if (response.ok) {
          const copy = response.clone();
          caches.open(CACHE).then((cache) => cache.put(request, copy));
        }
        return response;
      })
      .catch(async () => (await caches.match(request, { ignoreSearch: url.origin === self.location.origin })) ??
        (request.mode === "navigate" ? caches.match("index.html") : Response.error())),
  );
});
