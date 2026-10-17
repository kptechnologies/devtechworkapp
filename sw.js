/* DevTech Staff Portal — service worker: shows phone alerts sent by the portal and opens the right page when tapped */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
  let m = {};
  try { m = e.data ? e.data.json() : {}; } catch { m = { title: e.data ? e.data.text() : 'Staff portal' }; }
  e.waitUntil(self.registration.showNotification(m.title || 'Staff portal', {
    body: m.body || '',
    icon: 'assets/logo-mark.png',
    badge: 'assets/logo-mark.png',
    tag: m.tag || undefined,
    renotify: !!m.tag,
    data: { link: m.link || '' },
  }));
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = new URL('./#/' + (e.notification.data?.link || ''), self.registration.scope).href;
  e.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const win = wins.find((w) => w.url.startsWith(self.registration.scope));
    if (win) {
      await win.focus();
      try { await win.navigate(url); } catch { win.postMessage({ go: e.notification.data?.link || '' }); }
      return;
    }
    await self.clients.openWindow(url);
  })());
});
