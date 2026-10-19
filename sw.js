/* DevTech Staff Portal — service worker: shows phone alerts sent by the portal and opens the right page when tapped */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
  let m = {};
  try { m = e.data ? e.data.json() : {}; } catch { m = { title: e.data ? e.data.text() : 'Staff portal' }; }
  // A before-due reminder stays on screen with buttons to snooze it or escalate it.
  const rem = m.rid && m.token ? { rid: m.rid, token: m.token } : null;
  e.waitUntil(self.registration.showNotification(m.title || 'Staff portal', {
    body: m.body || '',
    icon: 'assets/logo-mark.png',
    badge: 'assets/logo-mark.png',
    tag: m.tag || undefined,
    renotify: !!m.tag,
    requireInteraction: !!rem,
    actions: rem ? [{ action: 'snooze', title: 'Remind in 10 min' }, { action: 'escalate', title: 'Escalate' }] : [],
    data: { link: m.link || '', rem },
  }));
});

/** Answers a reminder from its alert button, then shows what happened. */
async function answerReminder(rem, action) {
  let msg = 'Could not reach the portal. Open it to answer the reminder.';
  try {
    const r = await fetch(new URL('api.php?action=reminder_act', self.registration.scope).href, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: rem.rid, token: rem.token, action, minutes: 10 }),
    }).then((x) => x.json());
    msg = r.message || r.error || msg;
  } catch { /* offline: keep the default message */ }
  await self.registration.showNotification(action === 'snooze' ? 'Snoozed' : 'Escalated', {
    body: msg, icon: 'assets/logo-mark.png', badge: 'assets/logo-mark.png', tag: 'rem-' + rem.rid,
  });
}

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const rem = e.notification.data?.rem;
  if (rem && (e.action === 'snooze' || e.action === 'escalate')) {
    e.waitUntil(answerReminder(rem, e.action));
    return;
  }
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
