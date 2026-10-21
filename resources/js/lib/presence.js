import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Page heartbeat for "live now" analytics and the Live page's online count
 * (PresenceController). One per tab: it pings on load, after each Inertia
 * navigation and every 30 seconds while the tab is visible. The Live page
 * keeps pinging in a background tab, since people listen with it hidden.
 *
 * The admin area is skipped (staff aren't counted), except the admin
 * "attendee view" of the Live page, which only reads the count.
 */

const INTERVAL_MS = 30_000;

// Attendees on the current Live page, or null anywhere else.
export const liveAudience = ref(null);

let liveEventId = null;
let pending = null;
let started = false;

function currentRoute() {
    try {
        return route().current() ?? null;
    } catch {
        return null;
    }
}

function shouldPing() {
    const name = currentRoute();
    if (name?.startsWith('stage.')) return false;
    if (name?.startsWith('admin.') && !liveEventId) return false;
    return liveEventId !== null || document.visibilityState === 'visible';
}

async function ping() {
    if (!shouldPing()) return;

    const eventId = liveEventId;
    try {
        const { data } = await window.axios.post(route('presence.ping'), {
            route: currentRoute(),
            path: window.location.pathname,
            event_id: eventId,
        });
        // Ignore a reply that arrives after leaving the Live page.
        if (eventId !== null && eventId === liveEventId) liveAudience.value = data.online ?? null;
    } catch {
        // Analytics must never get in the way: a failed ping is simply skipped.
    }
}

// Coalesces the pings fired together (navigation + a page mounting).
function schedulePing(delay = 250) {
    clearTimeout(pending);
    pending = setTimeout(ping, delay);
}

function readXsrfToken() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : null;
}

function leave() {
    const token = readXsrfToken();
    if (!token) return;
    try {
        fetch(route('presence.leave'), {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: { 'X-XSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        });
    } catch {
        // Nothing to do: the row expires on its own after the live window.
    }
}

export function startPresence() {
    if (started || typeof window === 'undefined') return;
    started = true;

    schedulePing();
    router.on('navigate', () => schedulePing());
    setInterval(ping, INTERVAL_MS);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') schedulePing();
    });
    window.addEventListener('pagehide', leave);
}

/** Called by the Live page while it's open; pass null when it closes. */
export function setLiveEvent(eventId) {
    liveEventId = eventId ?? null;
    liveAudience.value = null;
    if (liveEventId !== null) schedulePing(0);
}
