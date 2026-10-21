<script setup>
import Button from '@/Components/Button.vue';
import PortalPage from '@/Components/PortalPage.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { postForCredentials, qualityLabel, useAgoraPublisher } from '@/composables/useAgoraPublisher';
import { clock, longDate } from '@/lib/eventFormat';
import { speakerNav } from '@/lib/portalNav';
import { Head, Link } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';

// The green room: the speaker publishes to a separate Agora channel that
// attendees are never given a token for, and a second, audience-role
// client on that channel plays back what arrives — so the speaker sees and
// hears what the audience would, through Agora, not just a local preview.
// Staff can join the same green room from the Broadcast console.
const props = defineProps({
    event: { type: Object, required: true },
    available: { type: Boolean, required: true },
});

const cameraEl = ref(null);
const screenEl = ref(null);
const monitorEl = ref(null);
const monitorScreenEl = ref(null);

let monitorCreds = null;
let ownUid = null;
let ownScreenUid = null;

const stage = useAgoraPublisher({
    fetchCredentials: async () => {
        const data = await postForCredentials(route('speaker.events.green-room.credentials', props.event.id));
        monitorCreds = data.monitor;
        ownUid = data.uid;
        ownScreenUid = data.screenUid;
        return data;
    },
    cameraEl,
    screenEl,
    onAirLabel: 'in the green room',
});

const {
    preflight, error, status, cameras, microphones, selectedCameraId, selectedMicId,
    previewing, onAir, joining, micMuted, cameraOff, hasCamera, deviceWarning, screenSharing,
    audioLevel, uplinkQuality, presenters,
} = stage;

// --- The loopback monitor ------------------------------------------------

const received = ref({ video: false, audio: false, screen: false });
const downlinkQuality = ref(0);
const monitorLevel = ref(0);
const monitorError = ref(null);
const hearYourself = ref(false);

let monitorClient = null;
let monitorAudio = null;
let monitorTimer = null;

async function startMonitor() {
    monitorError.value = null;
    try {
        const AgoraRTC = (await import('agora-rtc-sdk-ng')).default;
        monitorClient = AgoraRTC.createClient({ mode: 'live', codec: 'vp8' });
        monitorClient.setClientRole('audience');

        monitorClient.on('user-published', async (user, mediaType) => {
            const isCamera = user.uid === ownUid;
            const isScreen = user.uid === ownScreenUid;
            if (!isCamera && !isScreen) return; // staff in the green room: heard by the publisher client
            await monitorClient.subscribe(user, mediaType);

            if (mediaType === 'video') {
                user.videoTrack.play(isScreen ? monitorScreenEl.value : monitorEl.value, { fit: 'contain' });
                received.value = { ...received.value, [isScreen ? 'screen' : 'video']: true };
            } else if (isCamera) {
                monitorAudio = user.audioTrack;
                received.value = { ...received.value, audio: true };
                if (hearYourself.value) monitorAudio.play();
            }
        });
        monitorClient.on('user-unpublished', (user, mediaType) => {
            if (user.uid === ownScreenUid && mediaType === 'video') received.value = { ...received.value, screen: false };
            if (user.uid === ownUid) {
                received.value = { ...received.value, [mediaType === 'video' ? 'video' : 'audio']: false };
                if (mediaType === 'audio') monitorAudio = null;
            }
        });
        monitorClient.on('network-quality', (stats) => { downlinkQuality.value = stats.downlinkNetworkQuality; });

        await monitorClient.join(monitorCreds.appId, monitorCreds.channel, monitorCreds.token, monitorCreds.uid);

        clearInterval(monitorTimer);
        monitorTimer = setInterval(() => {
            monitorLevel.value = monitorAudio ? Math.round(monitorAudio.getVolumeLevel() * 100) : 0;
        }, 200);
    } catch (e) {
        monitorError.value = 'The playback check could not connect. Your own connection may be blocking it; try another network.';
    }
}

async function stopMonitor() {
    clearInterval(monitorTimer);
    monitorAudio?.stop();
    monitorAudio = null;
    received.value = { video: false, audio: false, screen: false };
    monitorLevel.value = 0;
    if (monitorClient) {
        await monitorClient.leave().catch(() => {});
        monitorClient = null;
    }
}

watch(onAir, (now) => (now ? startMonitor() : stopMonitor()));

// Off by default: hearing yourself through speakers feeds back into the mic.
watch(hearYourself, (on) => {
    if (!monitorAudio) return;
    if (on) monitorAudio.play();
    else monitorAudio.stop();
});

onBeforeUnmount(() => {
    stopMonitor();
    stage.goOffAir();
});

const tick = (value) => (value === null ? '…' : value ? '✓' : '✗');
const selectClass = 'field mt-1.5 w-full';

if (props.available) stage.runPreflight();
</script>

<template>
    <PortalLayout area="Speaker portal" current="speaker" :home-href="route('speaker.dashboard')" :nav="speakerNav()">
        <Head :title="`Green room · ${event.name}`" />

        <PortalPage :eyebrow="event.name" title="Test your setup" width="wide">
            <template #aside>
                <p v-if="event.starts_at">The event starts {{ longDate(event.starts_at) }} at {{ clock(event.starts_at) }}.</p>
            </template>

            <div v-if="!available" class="max-w-prose">
                <p class="text-[0.9375rem] text-ink-600">
                    This event isn't presented through the platform's own stage, or it has ended, so there's no green room for it.
                    Our team will brief you on the set-up.
                </p>
                <p class="mt-6"><Link :href="route('speaker.dashboard')" class="link">Back to your schedule</Link></p>
            </div>

            <template v-else>
                <p class="max-w-prose text-[0.9375rem] text-ink-600">
                    The green room is a private copy of the stage. <strong class="font-semibold text-ink-900">Attendees can't see or hear it.</strong>
                    Join it, and the right-hand screen plays back what reaches the audience through the livestream service, so you can check your picture, sound, slides and connection.
                    Our team may join you here for a sound check.
                </p>

                <div class="mt-10 grid gap-8 lg:grid-cols-2">
                    <section aria-labelledby="preview-title">
                        <h2 id="preview-title" class="eyebrow text-ink-500">Your camera</h2>
                        <div class="relative mt-3 aspect-video w-full overflow-hidden bg-ink-950">
                            <div v-show="screenSharing" ref="screenEl" class="absolute inset-0"></div>
                            <div
                                v-show="hasCamera || !screenSharing"
                                ref="cameraEl"
                                :class="screenSharing ? 'absolute bottom-3 right-3 z-10 aspect-video w-1/4 overflow-hidden ring-2 ring-white/80' : 'absolute inset-0'"
                            ></div>
                            <p v-if="!previewing" class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-white/70">
                                Your camera preview appears here.
                            </p>
                            <p v-else-if="cameraOff" class="absolute bottom-3 left-3 z-20 bg-black/70 px-2 py-1 text-xs text-white">Camera off</p>
                        </div>
                        <div class="mt-2 h-1.5 w-full overflow-hidden bg-rule" role="meter" aria-label="Microphone level" :aria-valuenow="audioLevel" aria-valuemin="0" aria-valuemax="100">
                            <div class="h-full bg-verified-500 transition-all" :style="{ width: audioLevel + '%' }"></div>
                        </div>
                    </section>

                    <section aria-labelledby="monitor-title">
                        <h2 id="monitor-title" class="eyebrow text-ink-500">What the audience would receive</h2>
                        <div class="relative mt-3 aspect-video w-full overflow-hidden bg-ink-950">
                            <div v-show="received.screen" ref="monitorScreenEl" class="absolute inset-0"></div>
                            <div
                                ref="monitorEl"
                                :class="received.screen ? 'absolute bottom-3 right-3 z-10 aspect-video w-1/4 overflow-hidden ring-2 ring-white/80' : 'absolute inset-0'"
                            ></div>
                            <p v-if="!onAir" class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-white/70">
                                Join the green room to see the playback.
                            </p>
                            <p v-else-if="!received.video && !received.screen" class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-white/70">
                                Waiting for your picture to arrive…
                            </p>
                        </div>
                        <div class="mt-2 h-1.5 w-full overflow-hidden bg-rule" role="meter" aria-label="Sound reaching the audience" :aria-valuenow="monitorLevel" aria-valuemin="0" aria-valuemax="100">
                            <div class="h-full bg-signal-500 transition-all" :style="{ width: monitorLevel + '%' }"></div>
                        </div>
                        <label class="mt-3 flex items-center gap-2 text-sm text-ink-700">
                            <input v-model="hearYourself" type="checkbox" class="rounded-ctl border-ink-400 text-ink-900 focus:ring-signal-500" :disabled="!onAir" />
                            Play my sound back to me (wear headphones, or it will echo)
                        </label>
                        <p v-if="monitorError" class="mt-3 text-sm text-red-700" role="alert">{{ monitorError }}</p>
                    </section>
                </div>

                <div class="mt-8 flex flex-wrap gap-3">
                    <Button v-if="!previewing" variant="secondary" @click="stage.startPreview().catch(() => {})">Check camera and mic</Button>
                    <template v-else>
                        <Button variant="secondary" size="sm" :aria-pressed="micMuted" @click="stage.toggleMic">{{ micMuted ? 'Unmute mic' : 'Mute mic' }}</Button>
                        <Button variant="secondary" size="sm" :aria-pressed="cameraOff" @click="stage.toggleCamera">{{ cameraOff ? 'Turn camera on' : 'Turn camera off' }}</Button>
                    </template>
                    <Button variant="secondary" size="sm" :aria-pressed="screenSharing" @click="stage.toggleScreenShare">
                        {{ screenSharing ? 'Stop sharing screen' : 'Share screen (slides)' }}
                    </Button>
                    <Button v-if="!onAir" :disabled="joining" @click="stage.goOnAir">{{ joining ? 'Joining…' : 'Join the green room' }}</Button>
                    <Button v-else variant="outline" @click="stage.goOffAir">Leave the green room</Button>
                </div>
                <p v-if="error" class="mt-3 text-sm text-red-700" role="alert">{{ error }}</p>
                <p v-if="deviceWarning" class="mt-3 text-sm text-ink-700" role="status">{{ deviceWarning }}</p>

                <div class="mt-10 grid gap-8 border-t border-ink-900 pt-6 sm:grid-cols-3">
                    <div>
                        <h2 class="eyebrow text-ink-500">Devices</h2>
                        <label class="mt-3 block text-sm text-ink-700" for="gr-camera">Camera</label>
                        <select id="gr-camera" v-model="selectedCameraId" :class="selectClass">
                            <option v-for="c in cameras" :key="c.deviceId" :value="c.deviceId">{{ c.label || 'Camera' }}</option>
                        </select>
                        <label class="mt-4 block text-sm text-ink-700" for="gr-mic">Microphone</label>
                        <select id="gr-mic" v-model="selectedMicId" :class="selectClass">
                            <option v-for="m in microphones" :key="m.deviceId" :value="m.deviceId">{{ m.label || 'Microphone' }}</option>
                        </select>
                    </div>
                    <div>
                        <h2 class="eyebrow text-ink-500">Your side</h2>
                        <ul class="mt-3 space-y-1.5 text-sm text-ink-700">
                            <li>{{ tick(preflight.https) }} Secure connection</li>
                            <li>{{ tick(preflight.mic) }} Microphone found</li>
                            <li>{{ tick(preflight.camera) }} Camera found</li>
                            <li>{{ tick(preflight.token) }} Livestream access</li>
                            <li>Upload quality: {{ onAir ? qualityLabel(uplinkQuality) : '—' }}</li>
                        </ul>
                        <p class="mt-2 text-sm text-ink-500" role="status">{{ status }}</p>
                    </div>
                    <div>
                        <h2 class="eyebrow text-ink-500">Audience side</h2>
                        <ul class="mt-3 space-y-1.5 text-sm text-ink-700">
                            <li>{{ onAir ? tick(received.video) : '…' }} Your picture arrives</li>
                            <li>{{ onAir ? tick(received.audio) : '…' }} Your sound arrives</li>
                            <li v-if="screenSharing">{{ tick(received.screen) }} Your slides arrive</li>
                            <li>Download quality: {{ onAir ? qualityLabel(downlinkQuality) : '—' }}</li>
                            <li v-if="onAir">{{ presenters.length ? `${presenters.length} other ${presenters.length === 1 ? 'person' : 'people'} in the green room` : 'Nobody else in the green room' }}</li>
                        </ul>
                    </div>
                </div>

                <p class="mt-10 text-sm text-ink-600">
                    All good? <a :href="route('speaker.events.stage', event.id)" class="link">Open your stage</a> when it's time to present. Chrome or Edge on a laptop works best.
                </p>
            </template>
        </PortalPage>
    </PortalLayout>
</template>
