const FONTS_CACHE = 'ladna-google-fonts-v1';
const GOOGLE_FONT_HOSTS = new Set(['fonts.googleapis.com', 'fonts.gstatic.com']);

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => Promise.all(
                cacheNames
                    .filter((cacheName) => cacheName.startsWith('ladna-google-fonts-') && cacheName !== FONTS_CACHE)
                    .map((cacheName) => caches.delete(cacheName)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (GOOGLE_FONT_HOSTS.has(url.hostname)) {
        event.respondWith(cacheFirst(request));

        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => offlineResponse()),
        );
    }
});

async function cacheFirst(request) {
    const cache = await caches.open(FONTS_CACHE);
    const cachedResponse = await cache.match(request);

    if (cachedResponse) {
        return cachedResponse;
    }

    const response = await fetch(request);

    if (response.ok) {
        cache.put(request, response.clone());
    }

    return response;
}

function offlineResponse() {
    return new Response(`<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#3B223F">
    <title>Ladna offline</title>
    <style>
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; background: #FAF8F5; color: #2B2B2F; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        main { width: min(100%, 520px); border: 1px solid #E7DDC9; border-radius: 16px; background: white; box-shadow: 0 24px 64px rgb(59 34 63 / 0.12); padding: 28px; }
        h1 { margin: 0 0 12px; color: #2B1731; font-size: 36px; line-height: 1.05; }
        p { margin: 0; color: rgb(77 49 82 / 0.78); font-size: 16px; line-height: 1.7; }
        .uk { margin-top: 16px; padding-top: 16px; border-top: 1px solid #E7DDC9; }
    </style>
</head>
<body>
    <main>
        <h1>Ladna is offline</h1>
        <p>Check your internet connection and reload the page. Fresh studio data is loaded online.</p>
        <p class="uk">Немає з'єднання з інтернетом. Перевірте підключення й оновіть сторінку.</p>
    </main>
</body>
</html>`, {
        headers: {
            'Content-Type': 'text/html; charset=UTF-8',
            'Cache-Control': 'no-store',
        },
    });
}
