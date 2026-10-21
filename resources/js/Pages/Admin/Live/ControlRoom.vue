<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { clock, longDate } from '@/lib/eventFormat';

const eventRoute = useEventRoute();

const props = defineProps({ event: Object, sessions: Array });

// Attendees on the Live page now (the presence heartbeat), refreshed every 15s.
const online = ref(null);
let onlineTimer = null;
async function loadOnline() {
    if (document.visibilityState !== 'visible') return;
    try {
        const { data } = await window.axios.get(eventRoute('admin.live.online'));
        online.value = data.online;
    } catch {
        // Keep the last figure; the next tick tries again.
    }
}
onMounted(() => {
    loadOnline();
    onlineTimer = setInterval(loadOnline, 15000);
});
onBeforeUnmount(() => clearInterval(onlineTimer));

function goLive() {
    router.post(eventRoute('admin.live.go-live'));
}
function endLive() {
    router.post(eventRoute('admin.live.end-live'));
}
function openPlayer() {
    if (confirm('Open the player now? Every registered attendee can join from the Live page until you close it (or end live). Nothing is on screen until a host starts broadcasting.')) {
        router.post(eventRoute('admin.live.player.open'), {}, { preserveScroll: true });
    }
}
function closePlayer() {
    router.post(eventRoute('admin.live.player.close'), {}, { preserveScroll: true });
}
const opensAt = computed(() => (props.event.player_opens_at ? `${longDate(props.event.player_opens_at)} at ${clock(props.event.player_opens_at)}` : null));function setSession(sessionId) {
    router.post(eventRoute('admin.live.set-session', { programmeSession: sessionId }));
}
function startSession(sessionId) {
    router.post(eventRoute('admin.live.sessions.start', { programmeSession: sessionId }));
}
function endSession(sessionId) {
    router.post(eventRoute('admin.live.sessions.end', { programmeSession: sessionId }));
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Live control room</h2>
        </template>

        <Head title="Control room" />

        <div class="py-12">
            <div class="mx-auto max-w-2xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-white p-6 shadow">
                    <p class="font-semibold">{{ event.name }}</p>
                    <p class="mt-1 text-sm" :class="event.is_live ? 'text-red-600' : 'text-gray-500'">
                        {{ event.is_live ? 'LIVE' : 'Not live' }}
                    </p>
                    <p class="mt-2 text-sm text-gray-700">
                        Online on the Live page now:
                        <strong class="tabular-nums" data-testid="control-room-online">{{ online ?? '…' }}</strong>
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button v-if="!event.is_live" class="rounded bg-red-600 px-4 py-2 text-sm text-white" @click="goLive">
                            Go live
                        </button>
                        <button v-else class="rounded bg-gray-700 px-4 py-2 text-sm text-white" @click="endLive">
                            End live
                        </button>
                        <Link
                            v-if="event.livestream_provider === 'agora'"
                            :href="eventRoute('admin.live.broadcast.show')"
                            class="rounded border border-ink-900 px-4 py-2 text-sm text-ink-900"
                        >
                            Open broadcast console
                        </Link>
                        <a
                            :href="eventRoute('admin.live.preview')"
                            target="_blank"
                            rel="noopener"
                            class="rounded border border-ink-900 px-4 py-2 text-sm text-ink-900"
                        >
                            Open attendee view<span aria-hidden="true"> &#8599;&#xFE0E;</span><span class="sr-only"> (opens in a new tab)</span>
                        </a>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-6 shadow">
                    <p class="font-semibold">Attendee player</p>
                    <p class="mt-1 text-sm" :class="event.player_open || event.is_live ? 'text-green-700' : 'text-gray-600'">
                        <template v-if="event.is_live">Open: the event is live.</template>
                        <template v-else-if="event.player_open">Open early: registered attendees can join now.</template>
                        <template v-else>Closed until {{ opensAt ?? 'shortly before the start' }}.</template>
                    </p>
                    <p class="mt-1 text-xs text-gray-600">
                        Open it early to rehearse with test attendees. It doesn't make the event live or send the "Starting now" email.
                    </p>
                    <div v-if="!event.is_live" class="mt-3">
                        <button v-if="!event.player_open" class="rounded border border-ink-900 px-4 py-2 text-sm text-ink-900" @click="openPlayer">
                            Open the player now
                        </button>
                        <button v-else class="rounded border border-gray-400 px-4 py-2 text-sm text-gray-800" @click="closePlayer">
                            Close the player
                        </button>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-6 shadow">
                    <p class="font-semibold">Sessions</p>
                    <ul class="mt-3 divide-y divide-gray-100">
                        <li v-for="session in sessions" :key="session.id" class="flex items-center justify-between py-2">
                            <span :class="{ 'font-semibold': session.id === event.current_session_id }">
                                {{ session.title }}
                                <span v-if="session.status === 'live'" class="ml-1 text-xs text-red-600">● live</span>
                                <span v-else-if="session.status === 'ended'" class="ml-1 text-xs text-gray-500">ended</span>
                            </span>
                            <span class="space-x-3 text-sm">
                                <button
                                    v-if="session.status !== 'live'"
                                    class="text-green-700"
                                    @click="startSession(session.id)"
                                >
                                    Start
                                </button>
                                <button
                                    v-else
                                    class="text-gray-600"
                                    @click="endSession(session.id)"
                                >
                                    End
                                </button>
                                <button
                                    class="text-signal-700"
                                    :disabled="session.id === event.current_session_id"
                                    @click="setSession(session.id)"
                                >
                                    Show
                                </button>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
