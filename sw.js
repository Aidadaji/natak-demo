// Lets phones install the app. It stores nothing: every request goes to the network,
// so sign-in state, coin balances and locked video are always the server's answer.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
