import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Real-time bootstrap (Pusher).
 *
 * This module must NEVER throw at import time: if the Pusher key was not
 * baked into the build (or Echo fails to initialise) the whole Inertia app
 * would otherwise fail to mount. In that case a chainable no-op stub with the
 * same surface is installed and `window.__realtime.available` is false so
 * pages can fall back to polling.
 */
function makeStub() {
    const channel = {
        listen() { return channel; },
        listenForWhisper() { return channel; },
        stopListening() { return channel; },
        subscribed() { return channel; },
        error() { return channel; },
        notification() { return channel; },
    };

    return {
        isStub: true,
        private: () => channel,
        channel: () => channel,
        join: () => channel,
        leave() {},
        leaveChannel() {},
        leaveAllChannels() {},
        disconnect() {},
    };
}

/**
 * Private-channel authorisation through axios, so a page can choose where
 * it's authorised: the signed-in user's /broadcasting/auth by default, or
 * window.__realtimeAuthEndpoint when a page sets it first — the speaker
 * stage has no account and authorises with its stage link instead, which
 * (unlike /broadcasting/auth) is CSRF-checked. axios sends Laravel's
 * XSRF-TOKEN cookie back as X-XSRF-TOKEN by itself.
 */
function authorizeChannel({ socketId, channelName }, callback) {
    const endpoint = window.__realtimeAuthEndpoint ?? '/broadcasting/auth';
    window.axios
        .post(endpoint, { socket_id: socketId, channel_name: channelName }, { headers: { Accept: 'application/json' } })
        .then(({ data }) => callback(null, data))
        .catch((error) => callback(error instanceof Error ? error : new Error(String(error)), null));
}

const key = import.meta.env.VITE_PUSHER_APP_KEY;

if (key) {
    try {
        window.Pusher = Pusher;
        window.Echo = new Echo({
            broadcaster: 'pusher',
            key,
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
            forceTLS: true,
            channelAuthorization: { customHandler: authorizeChannel },
        });
        window.__realtime = { available: true };
    } catch (error) {
        console.warn('Real-time (Pusher) failed to initialise; falling back to a no-op client.', error);
        window.Echo = makeStub();
        window.__realtime = { available: false };
    }
} else {
    window.Echo = makeStub();
    window.__realtime = { available: false };
}
