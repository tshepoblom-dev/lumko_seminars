import { onBeforeUnmount, ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * C1 — subscribes to private channels when Pusher is actually available
 * (see resources/js/echo.js), and falls back to Inertia partial-reload
 * polling whenever it isn't: no VITE_PUSHER_APP_KEY, the connection drops,
 * or a channel's subscription is refused (403, or the 419 that used to
 * happen on every channel). Every live page should use this instead of
 * talking to window.Echo directly, so the fallback is never something each
 * page has to remember to add.
 *
 * "Connected" means every channel has confirmed its subscription, not just
 * that the socket is open: an open socket with a refused channel receives
 * nothing, and must keep polling.
 *
 * Either one channel:
 *   useRealtime(`event.${id}`, { '.question.updated': (e) => ... }, options)
 * or several sharing one fallback:
 *   useRealtime([{ name: `event.${id}`, handlers: {...} }, { name: ..., handlers: {...} }], options)
 *
 * @param {object} [options]
 * @param {string[]} [options.only] Inertia `only` keys to reload while polling
 * @param {Function} [options.poll] custom poll step returning a promise (instead of `only`)
 * @param {number} [options.pollMs] poll interval when the tab is visible (default 10s)
 * @param {number} [options.pollMsHidden] poll interval when the tab is hidden (default 15s)
 */
export function useRealtime(channelOrSpecs, handlersOrOptions = {}, maybeOptions = {}) {
    const specs = Array.isArray(channelOrSpecs)
        ? channelOrSpecs
        : [{ name: channelOrSpecs, handlers: handlersOrOptions }];
    const options = Array.isArray(channelOrSpecs) ? handlersOrOptions : maybeOptions;

    const pollMs = options.pollMs ?? 10000;
    const pollMsHidden = options.pollMsHidden ?? 15000;
    const only = options.only ?? [];

    // connecting | connected | unavailable | failed
    const state = ref('connecting');
    const connected = ref(false);

    const subscribed = new Set();
    const subscribedNames = [];
    let pollTimer = null;
    let visibilityHandler = null;
    let unbindConnection = () => {};
    let stopped = false;

    function stopPolling() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function pollOnce() {
        if (options.poll) return Promise.resolve(options.poll()).catch(() => {});
        return new Promise((resolve) => {
            router.reload({ only, preserveScroll: true, preserveState: true, onFinish: resolve });
        });
    }

    function startPolling() {
        stopPolling();
        if (stopped || (!only.length && !options.poll)) return;

        const tick = async () => {
            pollTimer = null;
            await pollOnce();
            // Only keep polling while still not connected — a (re)subscribe
            // stops this loop.
            if (!stopped && state.value !== 'connected' && !pollTimer) {
                pollTimer = setTimeout(tick, document.hidden ? pollMsHidden : pollMs);
            }
        };

        pollTimer = setTimeout(tick, document.hidden ? pollMsHidden : pollMs);
    }

    function markConnected() {
        connected.value = true;
        state.value = 'connected';
        stopPolling();
    }

    function markDown(newState) {
        connected.value = false;
        state.value = newState;
        if (!pollTimer) startPolling();
    }

    function subscribe() {
        if (!window.Echo || window.__realtime?.available === false) {
            state.value = 'unavailable';
            startPolling();
            return;
        }

        const pusherConnection = window.Echo.connector?.pusher?.connection;

        specs.forEach(({ name, handlers = {} }) => {
            const channel = window.Echo.private(name);
            subscribedNames.push(name);

            Object.entries(handlers).forEach(([event, handler]) => channel.listen(event, handler));

            // Fires again after every reconnect (Pusher resubscribes).
            channel.subscribed?.(() => {
                subscribed.add(name);
                if (subscribed.size === specs.length) markConnected();
            });
            channel.error?.(() => {
                subscribed.delete(name);
                markDown('failed');
            });
        });

        if (pusherConnection?.bind) {
            const onDropped = () => {
                subscribed.clear();
                markDown('failed');
            };
            pusherConnection.bind('unavailable', onDropped);
            pusherConnection.bind('disconnected', onDropped);
            pusherConnection.bind('failed', onDropped);
            unbindConnection = () => {
                pusherConnection.unbind('unavailable', onDropped);
                pusherConnection.unbind('disconnected', onDropped);
                pusherConnection.unbind('failed', onDropped);
            };

            // Until every channel confirms, keep the page fresh by polling.
            startPolling();
        } else {
            // No connector detail exposed (e.g. a test double) — assume
            // connected rather than polling needlessly.
            markConnected();
        }

        visibilityHandler = () => {
            // Re-pace an in-flight polling fallback for the new visibility
            // state; connected mode is unaffected.
            if (state.value !== 'connected' && pollTimer) startPolling();
        };
        document.addEventListener('visibilitychange', visibilityHandler);
    }

    subscribe();

    function unsubscribe() {
        stopped = true;
        stopPolling();
        unbindConnection();
        if (visibilityHandler) document.removeEventListener('visibilitychange', visibilityHandler);
        if (window.Echo) subscribedNames.forEach((name) => window.Echo.leave(name));
    }

    onBeforeUnmount(unsubscribe);

    return { state, connected, unsubscribe };
}
