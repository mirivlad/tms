/* Service Worker belongs to the TMS origin, not to ChimePort. */
self.addEventListener('push', event => {
  let message = {};
  try { message = event.data ? event.data.json() : {}; } catch (_) { /* ignore malformed payload */ }
  const title = String(message.title || 'TMS');
  const body = String(message.body || '');
  const channel = String(message.channel || 'tms');
  let target = '/notifications';
  if (typeof message.url === 'string') {
    try {
      const parsed = new URL(message.url, self.location.origin);
      if (parsed.origin === self.location.origin && parsed.protocol === 'https:') {
        target = parsed.pathname + parsed.search + parsed.hash;
      }
    } catch (_) { /* open inbox */ }
  }
  event.waitUntil(self.registration.showNotification(title, {
    body,
    tag: channel + '-' + String(message.id || Date.now()),
    data: { target },
    icon: '/favicon.ico'
  }));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const target = event.notification.data?.target || '/notifications';
  event.waitUntil((async () => {
    const clientsList = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of clientsList) {
      if (new URL(client.url).origin === self.location.origin) {
        await client.navigate(target);
        return client.focus();
      }
    }
    return self.clients.openWindow(target);
  })());
});
