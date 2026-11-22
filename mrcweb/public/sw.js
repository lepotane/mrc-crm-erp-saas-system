/* MRC PWA service worker — yalnızca uygulama kabuğunu ve statik varlıkları önbelleğe alır.
 * API yanıtları, oturum/veri bayatlamasın diye ASLA önbelleğe alınmaz. */
const CACHE = 'mrc-shell-v4';
const SHELL = ['/', '/manifest.webmanifest?v=2', '/icons/icon-192.png?v=2', '/icons/icon-512.png?v=2'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/api/')) return;

  // Gezinmeler: önce ağ, yoksa önbellekteki uygulama kabuğu.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('/')),
    );
    return;
  }

  // Statik varlıklar (build/, ikonlar, manifest): önce önbellek.
  event.respondWith(
    caches.match(req).then((cached) => cached || fetch(req).then((res) => {
      if (res.ok) {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(req, copy));
      }
      return res;
    })),
  );
});

// İstemciden gelen ``SKIP_WAITING`` mesajıyla yeni worker hemen devralır.
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

// Bildirime tıklanınca uygulamayı açar / varsa mevcut sekmeyi odaklar.
// Yalnızca aynı origin'e ait yollara izin verilir (açık yönlendirme yok).
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  let target = '/';
  try {
    const raw = (event.notification.data && event.notification.data.url) || '/';
    const url = new URL(raw, self.location.origin);
    if (url.origin === self.location.origin) {
      target = url.pathname + url.search + url.hash;
    }
  } catch {
    target = '/';
  }

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      const targetPath = new URL(target, self.location.origin).pathname;
      const existing = clients.find((client) => {
        try {
          return new URL(client.url).pathname === targetPath;
        } catch {
          return false;
        }
      });
      if (existing) return existing.focus();
      return self.clients.openWindow(target);
    }),
  );
});
