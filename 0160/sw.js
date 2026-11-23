// Servisný súbor © 2026 ETTA STEEL s.r.o. · autor: Daniel Schmiedl · napísané nanovo, bez cudzieho kódu
// Celá appka sa uloží do telefónu a otvorí sa aj bez signálu. Pri signáli si potichu stiahne novšiu verziu.
const VERZIA = "dennik-29";
const SUBORY = ["./", "index.html", "manifest.webmanifest", "ikona-192.png", "ikona-512.png", "ikona-maskable-512.png", "ikona.ico"];
self.addEventListener("install", e => {
  e.waitUntil(caches.open(VERZIA).then(c => c.addAll(SUBORY.map(u => new Request(u, {cache: "reload"})))).then(() => self.skipWaiting()));
});
self.addEventListener("activate", e => {
  e.waitUntil(caches.keys().then(k => Promise.all(k.filter(n => n !== VERZIA).map(n => caches.delete(n))))
    .then(() => self.clients.claim()));
});
self.addEventListener("fetch", e => {
  const u = new URL(e.request.url);
  if (e.request.method !== "GET" || u.origin !== location.origin) return;
  e.respondWith(caches.match(e.request, {ignoreSearch: true}).then(z => {
    const siet = fetch(e.request).then(r => {
      if (r.ok) { const kopia = r.clone(); caches.open(VERZIA).then(c => c.put(e.request, kopia)); }
      return r;
    }).catch(() => z || caches.match("index.html"));
    return z || siet;
  }));
});
