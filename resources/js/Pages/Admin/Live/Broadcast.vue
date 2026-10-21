<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import CopyValue from '@/Components/CopyValue.vue';
import PresenterLivePanel from '@/Components/Live/PresenterLivePanel.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { postForCredentials, qualityLabel, useAgoraPublisher } from '@/composables/useAgoraPublisher';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const eventRoute = useEventRoute();

const props = defineProps({
    event: Object,
    speakers: { type: Array, default: () => [] },
});

const cameraEl = ref(null);
const screenEl = ref(null);

// The green room: a sound check with speakers (from their speaker portal)
// on a separate channel attendees never see. Chosen before joining.
const inGreenRoom = ref(false);

const stage = useAgoraPublisher({
    fetchCredentials: () => postForCredentials(eventRoute('admin.live.broadcast.token') + (inGreenRoom.value ? '?greenroom=1' : '')),
    cameraEl,
    screenEl,
});

const {
    preflight, error, status, cameras, microphones, selectedCameraId, selectedMicId,
    previewing, onAir, joining, micMuted, cameraOff, hasCamera, deviceWarning, screenSharing, othersMuted,
    audioLevel, uplinkQuality, presenters,
} = stage;

async function startBroadcast() {
    await stage.goOnAir();
    if (onAir.value && !inGreenRoom.value) {
        router.post(eventRoute('admin.live.broadcast.start'), {}, { preserveScroll: true, preserveState: true });
    }
}

async function stopBroadcast() {
    await stage.goOffAir();
    if (inGreenRoom.value) return;
    if (confirm('Also end the event\'s live state for attendees? (Choose Cancel to just go off air and come back later.)')) {
        router.post(eventRoute('admin.live.broadcast.stop'), {}, { preserveScroll: true, preserveState: true });
    }
}

// --- Polls, Q&A and chat, without leaving the console (leaving = off air) ---

const asJson = { headers: { Accept: 'application/json' } };
const presenterApi = {
    load: () => window.axios.get(eventRoute('admin.live.broadcast.live-state'), asJson).then((r) => r.data),
    createPoll: (data) => window.axios.post(eventRoute('admin.live.polls.store'), data, asJson).then((r) => r.data),
    openPoll: (id) => window.axios.post(eventRoute('admin.live.polls.open', { poll: id }), {}, asJson).then((r) => r.data),
    closePoll: (id) => window.axios.post(eventRoute('admin.live.polls.close', { poll: id }), {}, asJson).then((r) => r.data),
    moderate: (action, id) => window.axios.post(eventRoute(`admin.live.questions.${action}`, { question: id }), {}, asJson).then((r) => r.data),
    hideChat: (id) => window.axios.post(eventRoute('admin.live.chat.hide', { chatMessage: id }), {}, asJson).then((r) => r.data),
};

// --- Remote speakers ------------------------------------------------------

const speakerByUid = computed(() => Object.fromEntries(props.speakers.map((s) => [s.uid, s])));
const onStageNames = computed(() => presenters.value.map((uid) => speakerByUid.value[uid]?.name ?? `Staff host #${uid}`));
const freshLinks = reactive({}); // speaker id → the link, shown once after creating it
const linkBusy = ref(null);
const linkError = ref(null);

async function createLink(speaker) {
    if (speaker.has_stage_link && !confirm(`Replace ${speaker.name}'s stage link? The old link stops working.`)) return;

    linkBusy.value = speaker.id;
    linkError.value = null;
    try {
        const { data } = await window.axios.post(
            eventRoute('admin.live.broadcast.stage-link.store', { speaker: speaker.id }),
            {},
            { headers: { Accept: 'application/json' } },
        );
        freshLinks[speaker.id] = data.url;
        router.reload({ only: ['speakers'] });
    } catch {
        linkError.value = `Could not create a stage link for ${speaker.name}.`;
    } finally {
        linkBusy.value = null;
    }
}

function revokeLink(speaker) {
    if (!confirm(`Withdraw ${speaker.name}'s stage link? They won't be able to join with it again.`)) return;
    delete freshLinks[speaker.id];
    router.delete(eventRoute('admin.live.broadcast.stage-link.destroy', { speaker: speaker.id }), { preserveScroll: true });
}

const check = (value) => (value === null ? '…' : value ? '✓' : '✗');

stage.runPreflight();
</script>

<template>
    <AdminLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Broadcast — {{ event.name }}</h2>
                <a
                    :href="eventRoute('admin.live.preview')"
                    target="_blank"
                    rel="noopener"
                    class="text-sm font-medium text-signal-700 underline underline-offset-4"
                >
                    Open attendee view<span aria-hidden="true"> &#8599;&#xFE0E;</span><span class="sr-only"> (opens in a new tab)</span>
                </a>
            </div>
        </template>

        <Head title="Broadcast console" />

        <div class="py-8">
            <div class="mx-auto grid max-w-6xl gap-6 px-4 sm:px-6 lg:grid-cols-3 lg:px-8">
                <!-- Stage preview + controls -->
                <div class="space-y-6 lg:col-span-2">
                    <div class="rounded-lg bg-white p-6 shadow">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-700">Your stage</h3>
                            <p class="text-xs text-gray-600">
                                {{ screenSharing ? 'Attendees see your screen, with your camera in the corner.' : 'Attendees see your camera.' }}
                            </p>
                        </div>

                        <div class="relative mt-3 aspect-video w-full overflow-hidden rounded bg-gray-900">
                            <div v-show="screenSharing" ref="screenEl" data-preview="screen" class="absolute inset-0"></div>
                            <div
                                v-show="hasCamera || !screenSharing"
                                ref="cameraEl"
                                data-preview="camera"
                                :class="screenSharing
                                    ? 'absolute bottom-3 right-3 z-10 aspect-video w-1/4 overflow-hidden rounded ring-2 ring-white/80'
                                    : 'absolute inset-0'"
                            ></div>
                            <p v-if="!previewing" class="absolute inset-0 flex items-center justify-center text-sm text-white/70">
                                Camera preview is off
                            </p>
                            <p v-else-if="!hasCamera && !screenSharing" class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-white/70">
                                No camera — attendees see your name
                            </p>
                            <p v-else-if="cameraOff" class="absolute bottom-3 right-3 z-20 rounded bg-black/70 px-2 py-1 text-xs text-white">
                                Camera off — attendees see your name
                            </p>
                            <span v-if="onAir && inGreenRoom" class="absolute left-3 top-3 z-20 rounded bg-gray-800 px-2 py-0.5 text-xs font-semibold text-white">GREEN ROOM</span>
                            <span v-else-if="onAir" class="absolute left-3 top-3 z-20 rounded bg-red-700 px-2 py-0.5 text-xs font-semibold text-white">ON AIR</span>
                        </div>

                        <div class="mt-2 h-1.5 w-full overflow-hidden rounded bg-gray-100" role="meter" aria-label="Microphone level" :aria-valuenow="audioLevel" aria-valuemin="0" aria-valuemax="100">
                            <div class="h-full bg-green-600 transition-all" :style="{ width: audioLevel + '%' }"></div>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button v-if="!previewing" type="button" class="rounded bg-gray-200 px-4 py-2 text-sm" @click="stage.startPreview().catch(() => {})">
                                Preview camera and mic
                            </button>
                            <template v-else>
                                <button type="button" class="rounded border px-4 py-2 text-sm" :aria-pressed="micMuted" @click="stage.toggleMic">
                                    {{ micMuted ? 'Unmute mic' : 'Mute mic' }}
                                </button>
                                <button type="button" class="rounded border px-4 py-2 text-sm" :aria-pressed="cameraOff" @click="stage.toggleCamera">
                                    {{ cameraOff ? 'Turn camera on' : 'Turn camera off' }}
                                </button>
                            </template>
                            <button type="button" class="rounded border px-4 py-2 text-sm" :aria-pressed="screenSharing" @click="stage.toggleScreenShare">
                                {{ screenSharing ? 'Stop sharing screen' : 'Share screen (slides)' }}
                            </button>
                        </div>
                        <p v-if="deviceWarning" class="mt-3 text-sm text-amber-800" role="status">{{ deviceWarning }}</p>
                        <p class="mt-2 text-xs text-gray-600">
                            To play a video with sound from your slides, share a browser tab and tick "Share tab audio".
                        </p>
                    </div>

                    <div class="rounded-lg bg-white p-6 shadow">
                        <h3 class="text-sm font-semibold text-gray-700">Audience</h3>
                        <p class="mt-1 text-xs text-gray-600">Run polls, answer questions and keep an eye on the chat without leaving this page.</p>
                        <PresenterLivePanel class="mt-3" :event-id="event.id" staff :api="presenterApi" />
                    </div>

                    <div class="rounded-lg bg-white p-6 shadow">
                        <h3 class="text-sm font-semibold text-gray-700">Devices</h3>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="block text-xs text-gray-600" for="broadcast-camera">Camera</label>
                                <select id="broadcast-camera" v-model="selectedCameraId" class="mt-1 w-full rounded border-gray-300 text-sm">
                                    <option v-for="c in cameras" :key="c.deviceId" :value="c.deviceId">{{ c.label || 'Camera' }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-600" for="broadcast-mic">Microphone</label>
                                <select id="broadcast-mic" v-model="selectedMicId" class="mt-1 w-full rounded border-gray-300 text-sm">
                                    <option v-for="m in microphones" :key="m.deviceId" :value="m.deviceId">{{ m.label || 'Microphone' }}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Go live, checks, speakers -->
                <div class="space-y-6">
                    <div class="rounded-lg bg-white p-6 shadow">
                        <p class="text-sm text-gray-700">
                            Status: <span class="font-medium">{{ status || (onAir ? 'On air' : 'Off air') }}</span>
                        </p>
                        <p class="text-sm text-gray-700">Upload quality: {{ qualityLabel(uplinkQuality) }}</p>
                        <p class="text-sm text-gray-700">Event: {{ event.is_live ? 'LIVE for attendees' : 'not live for attendees' }}</p>

                        <label class="mt-4 flex items-start gap-2 text-sm text-gray-700">
                            <input v-model="inGreenRoom" type="checkbox" class="mt-0.5 rounded border-gray-300" :disabled="onAir || joining" />
                            <span>
                                Green room: a sound check with speakers. Attendees can't see or hear it.
                                <span class="block text-xs text-gray-600">Speakers join it from "Test your setup" in their speaker portal.</span>
                            </span>
                        </label>

                        <button
                            v-if="!onAir"
                            type="button"
                            :class="[
                                'mt-4 w-full rounded px-6 py-2.5 text-sm font-semibold text-white disabled:opacity-60',
                                inGreenRoom ? 'bg-gray-800' : 'bg-red-700',
                            ]"
                            :disabled="joining"
                            @click="startBroadcast"
                        >
                            {{ joining ? 'Connecting…' : inGreenRoom ? 'Join the green room' : 'Start broadcast' }}
                        </button>
                        <button
                            v-else
                            type="button"
                            :class="[
                                'mt-4 w-full rounded border px-6 py-2.5 text-sm font-semibold',
                                inGreenRoom ? 'border-gray-800 text-gray-800' : 'border-red-700 text-red-700',
                            ]"
                            @click="stopBroadcast"
                        >
                            {{ inGreenRoom ? 'Leave the green room' : 'Stop broadcast' }}
                        </button>
                        <p v-if="error" class="mt-3 text-sm text-red-700" role="alert">{{ error }}</p>
                    </div>

                    <div class="rounded-lg bg-white p-6 shadow">
                        <h3 class="text-sm font-semibold text-gray-700">Pre-flight checklist</h3>
                        <ul class="mt-3 space-y-1 text-sm">
                            <li :class="preflight.https ? 'text-green-700' : 'text-red-700'">{{ check(preflight.https) }} Secure connection (HTTPS)</li>
                            <li :class="preflight.mic === false ? 'text-red-700' : 'text-gray-700'">{{ check(preflight.mic) }} Microphone detected</li>
                            <li :class="preflight.camera === false ? 'text-red-700' : 'text-gray-700'">{{ check(preflight.camera) }} Camera detected</li>
                            <li :class="preflight.token === false ? 'text-red-700' : 'text-gray-700'">{{ check(preflight.token) }} Broadcast token</li>
                        </ul>
                    </div>

                    <div class="rounded-lg bg-white p-6 shadow">
                        <h3 class="text-sm font-semibold text-gray-700">Remote speakers</h3>
                        <p class="mt-1 text-xs text-gray-600">
                            Send a speaker their stage link. It lets them present from their own computer with camera, mic and screen share. No account needed. Attendees see them as soon as they join.
                        </p>

                        <p v-if="onAir" class="mt-3 text-sm text-gray-700">
                            On stage with you:
                            <span class="font-medium">{{ onStageNames.length ? onStageNames.join(', ') : 'nobody yet' }}</span>
                        </p>
                        <button v-if="onAir && presenters.length" type="button" class="mt-1 text-xs text-signal-700 underline" @click="stage.toggleOthersMuted">
                            {{ othersMuted ? 'Unmute the other presenters (for you)' : 'Mute the other presenters (for you)' }}
                        </button>

                        <p v-if="linkError" class="mt-3 text-sm text-red-700" role="alert">{{ linkError }}</p>

                        <p v-if="!speakers.length" class="mt-3 text-sm text-gray-600">
                            No speakers are linked to this event yet. Add them under Plan → Speakers.
                        </p>
                        <ul v-else class="mt-3 divide-y divide-gray-100">
                            <li v-for="speaker in speakers" :key="speaker.id" class="py-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-sm font-medium text-gray-800">
                                        {{ speaker.name }}
                                        <span v-if="presenters.includes(speaker.uid)" class="ml-1 text-xs text-red-700">● on stage</span>
                                    </span>
                                    <span class="space-x-3 text-sm">
                                        <button type="button" class="text-signal-700 disabled:opacity-50" :disabled="linkBusy === speaker.id" @click="createLink(speaker)">
                                            {{ speaker.has_stage_link ? 'New link' : 'Create link' }}
                                        </button>
                                        <button v-if="speaker.has_stage_link" type="button" class="text-red-700" @click="revokeLink(speaker)">
                                            Withdraw
                                        </button>
                                    </span>
                                </div>
                                <p v-if="speaker.has_stage_link && !freshLinks[speaker.id]" class="mt-1 text-xs text-gray-600">
                                    A link is active. For security it's shown only once. Create a new link if it's lost.
                                </p>
                                <dl v-if="freshLinks[speaker.id]">
                                    <CopyValue label="Stage link (copy it now)" :value="freshLinks[speaker.id]" />
                                </dl>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
