// Offline shell: static files come from the network first and fall back to the
// cache when offline. API calls are never cached.
const CACHE = "saagi-shell-v15";
const SHELL = [
  "/", "/index.html", "/submit.html", "/track.html", "/feed.html", "/cabinet.html", "/community.html", "/privacy.html",
  "/staff/", "/staff/workspace.html", "/admin/",
  "/css/app.css",
  "/js/common.js", "/js/home.js", "/js/submit.js", "/js/track.js", "/js/feed.js", "/js/cabinet.js", "/js/community.js",
  "/js/portal.js", "/js/staff.js", "/js/workspace.js", "/js/admin.js",
  "/data/cabinet.json", "/manifest.json",
  "/assets/logo.png", "/assets/icons/icon.svg", "/assets/icons/icon-192.png", "/assets/icons/icon-512.png",
];

self.addEventListener("install", (e) => e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL))));

self.addEventListener("activate", (e) => e.waitUntil(
  caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))),
));

self.addEventListener("fetch", (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== "GET" || url.origin !== location.origin || url.pathname.startsWith("/api")) return;
  e.respondWith(fetch(e.request).catch(() => caches.match(e.request)));
});
