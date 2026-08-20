const CACHE_NAME = "sierra-crm-v30";
const APP_SHELL = [
  "./assets/crm.css?v=20260813-lazy-lead-details-v1",
  "./assets/crm.js?v=20260813-lazy-lead-details-v1",
  "./assets/crm-navigation.js?v=20260812-fast-navigation-v3",
  "./assets/icon.svg?v=20260819-sierra-icon-v1",
  "./assets/icon-180.png?v=20260819-sierra-icon-v1",
  "./assets/icon-192.png?v=20260819-sierra-icon-v1",
  "./assets/icon-512.png?v=20260819-sierra-icon-v1",
];

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)));
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
    ))
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  if (event.request.method !== "GET") {
    return;
  }

  const url = new URL(event.request.url);

  if (url.pathname.includes("/api/") || url.pathname.endsWith("/index.php") || url.pathname.endsWith("/login.php")) {
    return;
  }

  const isStaticAsset = url.pathname.includes("/assets/") || url.pathname.endsWith("/manifest.webmanifest");

  if (!isStaticAsset) {
    return;
  }

  event.respondWith(
    fetch(event.request).then((response) => {
      if (response.ok && url.origin === self.location.origin) {
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
      }
      return response;
    }).catch(() => caches.match(event.request))
  );
});

self.addEventListener("push", (event) => {
  let data = {};

  try {
    data = event.data ? event.data.json() : {};
  } catch (error) {
    data = { body: event.data ? event.data.text() : "Novo evento no CRM." };
  }

  const title = data.title || "Sierra";
  const options = {
    body: data.body || "Você tem uma nova atualização.",
    icon: data.icon || "./assets/icon-192.png?v=20260819-sierra-icon-v1",
    badge: data.badge || "./assets/icon-192.png?v=20260819-sierra-icon-v1",
    tag: data.tag || "crm-notification",
    renotify: data.renotify === true,
    data: { url: data.url || "./index.php" },
  };

  const notifyOpenClients = data.event === "lead-reply" && data.lead_id
    ? self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clientList) => {
      clientList.forEach((client) => {
        client.postMessage({ type: "crm-lead-reply", leadId: String(data.lead_id) });
      });
    })
    : Promise.resolve();

  event.waitUntil(Promise.all([
    self.registration.showNotification(title, options),
    notifyOpenClients,
  ]));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const targetUrl = new URL(event.notification.data?.url || "./index.php", self.registration.scope).href;

  event.waitUntil(
    clients.matchAll({ type: "window", includeUncontrolled: true }).then((clientList) => {
      const existing = clientList.find((client) => "focus" in client);

      if (existing) {
        existing.navigate(targetUrl);
        return existing.focus();
      }

      return clients.openWindow(targetUrl);
    })
  );
});
