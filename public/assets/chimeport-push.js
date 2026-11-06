/* TMS browser push: only TMS URL and TMS session are visible to users. */
(() => {
  const root = document.getElementById('tms-push');
  if (!root) return;
  const button = root.querySelector('[data-tms-push-toggle]');
  const status = root.querySelector('[data-tms-push-status]');
  const csrf = root.dataset.csrf || '';
  const say = text => { status.textContent = text; };
  const available = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  if (!available || !window.isSecureContext) {
    button.disabled = true;
    say('Этот браузер не поддерживает push-уведомления или соединение не защищено.');
    return;
  }

  const keyBytes = base64 => {
    const normalized = base64.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, '=');
    return Uint8Array.from(atob(padded), char => char.charCodeAt(0));
  };
  const request = async (path, options = {}) => {
    const response = await fetch(path, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      ...options
    });
    if (!response.ok) throw new Error('HTTP ' + response.status);
    return response.json();
  };
  let registration = null;
  let active = false;
  const refresh = async () => {
    registration = await navigator.serviceWorker.register('/chimeport-sw.js', { scope: '/' });
    const subscription = await registration.pushManager.getSubscription();
    active = !!subscription;
    button.textContent = active ? 'Отключить уведомления на этом устройстве' : 'Включить уведомления на этом устройстве';
    say(active ? 'Push-уведомления включены в этом браузере.' : 'Push-уведомления пока не включены.');
  };

  button.addEventListener('click', async () => {
    button.disabled = true;
    try {
      if (!registration) await refresh();
      if (active) {
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) {
          await request('/api/notifications/push/subscription', {
            method: 'DELETE', body: JSON.stringify({ endpoint: subscription.endpoint })
          });
          await subscription.unsubscribe();
        }
      } else {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
          say('Разрешение на уведомления не выдано.');
          return;
        }
        const config = await request('/api/notifications/push/config');
        const subscription = await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: keyBytes(config.public_key)
        });
        const json = subscription.toJSON();
        try {
          await request('/api/notifications/push/subscription', {
            method: 'POST',
            body: JSON.stringify({ endpoint: subscription.endpoint, keys: json.keys })
          });
        } catch (error) {
          await subscription.unsubscribe();
          throw error;
        }
      }
      await refresh();
    } catch (error) {
      say('Не удалось изменить подписку. Проверьте соединение и попробуйте ещё раз.');
    } finally {
      button.disabled = false;
    }
  });

  refresh().catch(() => {
    say('Не удалось проверить состояние push-уведомлений.');
  });
})();
