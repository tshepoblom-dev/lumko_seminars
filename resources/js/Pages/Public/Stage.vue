<script setup>
import Button from '@/Components/Button.vue';
import PresenterLivePanel from '@/Components/Live/PresenterLivePanel.vue';
import { postForCredentials, qualityLabel, useAgoraPublisher } from '@/composables/useAgoraPublisher';
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { clock, longDate } from '@/lib/eventFormat';

// The remote speaker's stage, reached either by the link staff create on
// the Broadcast console (no account) or from the speaker portal (signed
// in). No attendee navigation: one job, presenting on the event's
// livestream. The server hands over the URLs for whichever way in this is;
// poll URLs carry a __POLL__ placeholder for the poll id.
const props = defineProps({
    event: Object,
    speaker: Object,
    endpoints: Object,
    backUrl: { type: String, default: null },
});

const pollUrl = (template, id) => template.replace('__POLL__', encodeURIComponent(id));

const cameraEl = ref(null);
const screenEl = ref(null);

const stage = useAgoraPublisher({
    fetchCredentials: () => postForCredentials(props.endpoints.credentials),
    cameraEl,
    screenEl,
    onAirLabel: 'on stage',
});

const {
    preflight, error, status, cameras, microphones, selectedCameraId, selectedMicId,
    previewing, onAir, joining, micMuted, cameraOff, hasCamera, deviceWarning, screenSharing, othersMuted,
    audioLevel, uplinkQuality, presenters,
} = stage;

const startsAt = computed(() => (props.event.starts_at
    ? `on ${longDate(props.event.starts_at)} at ${clock(props.event.starts_at)} (SAST)`
    : null));

function leaveStage() {
    if (confirm('Leave the stage? Attendees will stop seeing and hearing you.')) stage.goOffAir();
}

// --- Polls, questions and chat -------------------------------------------

// The presenter channels are authorised by the stage itself (the link, or
// the speaker's portal session), not /broadcasting/auth, which is for
// staff and attendees. Set before the panel subscribes.
window.__realtimeAuthEndpoint = props.endpoints.broadcastingAuth;
onBeforeUnmount(() => { delete window.__realtimeAuthEndpoint; });

const asJson = { headers: { Accept: 'application/json' } };
const presenterApi = {
    load: () => window.axios.get(props.endpoints.live, asJson).then((r) => r.data),
    createPoll: (data) => window.axios.post(props.endpoints.polls, data, asJson).then((r) => r.data),
    openPoll: (id) => window.axios.post(pollUrl(props.endpoints.pollOpen, id), {}, asJson).then((r) => r.data),
    closePoll: (id) => window.axios.post(pollUrl(props.endpoints.pollClose, id), {}, asJson).then((r) => r.data),
};

const selectClass ='mt-1.5 w-full rounded-ctl border border-white/30 bg-ink-950 px-3 py-2 text-sm text-white focus:border-white focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500';

stage.runPreflight();
</script>

<template>
    <Head title="Speaker stage">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <main id="main" class="stage min-h-screen">
        <div class="shell pb-16 pt-10 sm:pt-14">
            <a v-if="backUrl" :href="backUrl" class="link mb-6 inline-block text-sm text-white/75">← Back to your speaker portal</a>
            <p class="eyebrow text-white/60">Speaker stage · {{ event.name }}</p>
            <h1 class="display mt-3 text-display-sm text-white">Welcome, {{ speaker.name }}</h1>
            <p class="mt-3 max-w-prose text-[0.9375rem] leading-relaxed text-white/75">
                Check your camera and microphone, share your slides if you have any, then join the stage.
                <strong class="font-semibold text-white">Attendees see and hear you as soon as you join</strong>, so keep your mic muted until it's your turn.
                Keep this page open while you present.
            </p>
            <p v-if="startsAt" class="mt-2 text-sm text-white/60">
                {{ event.is_live ? 'The event is live now.' : `The event starts ${startsAt}.` }}
            </p>

            <div class="mt-8 grid gap-8 lg:grid-cols-12">
                <section class="lg:col-span-8" aria-label="Your video">
                    <div class="relative aspect-video w-full overflow-hidden bg-ink-950 ring-1 ring-white/15">
                        <div v-show="screenSharing" ref="screenEl" data-preview="screen" class="absolute inset-0"></div>
                        <div
                            v-show="hasCamera || !screenSharing"
                            ref="cameraEl"
                            data-preview="camera"
                            :class="screenSharing
                                ? 'absolute bottom-3 right-3 z-10 aspect-video w-1/4 overflow-hidden ring-2 ring-white/80'
                                : 'absolute inset-0'"
                        ></div>
                        <p v-if="!previewing" class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-white/70">
                            Your camera preview appears here.
                        </p>
                        <p v-else-if="!hasCamera && !screenSharing" class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-white/70">
                            No camera. Attendees see your name.
                        </p>
                        <p v-else-if="cameraOff" class="absolute bottom-3 left-3 z-20 bg-black/70 px-2 py-1 text-xs text-white">
                            Camera off. Attendees see your name.
                        </p>
                        <span v-if="onAir" class="absolute left-3 top-3 z-20 bg-red-700 px-2 py-0.5 text-xs font-semibold tracking-wide text-white">ON STAGE</span>
                    </div>

                    <div class="mt-2 h-1.5 w-full overflow-hidden bg-white/10" role="meter" aria-label="Microphone level" :aria-valuenow="audioLevel" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-full bg-verified-500 transition-all" :style="{ width: audioLevel + '%' }"></div>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <Button v-if="!previewing" variant="inverse" @click="stage.startPreview().catch(() => {})">Check camera and mic</Button>
                        <template v-else>
                            <Button variant="ghost" size="sm" :aria-pressed="micMuted" @click="stage.toggleMic">{{ micMuted ? 'Unmute mic' : 'Mute mic' }}</Button>
                            <Button variant="ghost" size="sm" :aria-pressed="cameraOff" @click="stage.toggleCamera">{{ cameraOff ? 'Turn camera on' : 'Turn camera off' }}</Button>
                        </template>
                        <Button variant="ghost" size="sm" :aria-pressed="screenSharing" @click="stage.toggleScreenShare">
                            {{ screenSharing ? 'Stop sharing screen' : 'Share screen (slides)' }}
                        </Button>
                    </div>
                    <p v-if="deviceWarning" class="mt-3 text-sm text-amber-200" role="status">{{ deviceWarning }}</p>
                    <p class="mt-3 text-sm text-white/60">
                        With screen sharing on, attendees see your slides with your camera in the corner. To play a video with sound, share a browser tab and tick "Share tab audio".
                    </p>

                    <div class="mt-10 border-t border-white/25 pt-5" aria-labelledby="stage-audience-title">
                        <h2 id="stage-audience-title" class="eyebrow text-white/60">Your audience</h2>
                        <p class="mt-2 text-sm text-white/75">
                            Launch a poll and it pops up over the stream for everyone. Questions appear here once a moderator approves them.
                        </p>
                        <PresenterLivePanel class="mt-4" :event-id="event.id" :api="presenterApi" tone="dark" />
                    </div>
                </section>

                <aside class="space-y-8 lg:col-span-4">
                    <div class="border-t border-white/25 pt-5">
                        <p class="eyebrow text-white/60">{{ onAir ? 'You are on stage' : 'Ready when you are' }}</p>
                        <Button
                            v-if="!onAir"
                            variant="inverse"
                            size="lg"
                            class="mt-4 w-full"
                            :disabled="joining"
                            @click="stage.goOnAir"
                        >
                            {{ joining ? 'Joining…' : 'Join the stage' }}
                        </Button>
                        <Button v-else variant="ghost" size="lg" class="mt-4 w-full" @click="leaveStage">Leave the stage</Button>
                        <p v-if="error" class="mt-3 text-sm text-red-300" role="alert">{{ error }}</p>
                        <p class="mt-3 text-sm text-white/75" role="status">
                            {{ status || (onAir ? 'Connected' : 'Not connected') }} · Upload quality: {{ qualityLabel(uplinkQuality) }}
                        </p>
                        <template v-if="onAir">
                            <p class="mt-1 text-sm text-white/75">
                                {{ presenters.length === 0 ? 'No other presenters on stage yet.' : presenters.length === 1 ? '1 other presenter on stage.' : `${presenters.length} other presenters on stage.` }}
                            </p>
                            <button v-if="presenters.length" type="button" class="link mt-1 text-sm" @click="stage.toggleOthersMuted">
                                {{ othersMuted ? 'Unmute the other presenters' : 'Mute the other presenters' }}
                            </button>
                        </template>
                    </div>

                    <div class="border-t border-white/25 pt-5">
                        <p class="eyebrow text-white/60">Devices</p>
                        <label class="mt-4 block text-sm text-white/75" for="stage-camera">Camera</label>
                        <select id="stage-camera" v-model="selectedCameraId" :class="selectClass">
                            <option v-for="c in cameras" :key="c.deviceId" :value="c.deviceId">{{ c.label || 'Camera' }}</option>
                        </select>
                        <label class="mt-4 block text-sm text-white/75" for="stage-mic">Microphone</label>
                        <select id="stage-mic" v-model="selectedMicId" :class="selectClass">
                            <option v-for="m in microphones" :key="m.deviceId" :value="m.deviceId">{{ m.label || 'Microphone' }}</option>
                        </select>
                    </div>

                    <div class="border-t border-white/25 pt-5">
                        <p class="eyebrow text-white/60">Checks</p>
                        <ul class="mt-3 space-y-1.5 text-sm text-white/75">
                            <li>{{ preflight.https ? '✓' : '✗' }} Secure connection</li>
                            <li>{{ preflight.mic === null ? '…' : preflight.mic ? '✓' : '✗' }} Microphone found</li>
                            <li>{{ preflight.camera === null ? '…' : preflight.camera ? '✓' : '✗' }} Camera found</li>
                            <li>{{ preflight.token === null ? '…' : preflight.token ? '✓' : '✗' }} Stage access</li>
                        </ul>
                        <p class="mt-3 text-sm text-white/60">Chrome or Edge on a laptop works best. Use headphones if you can.</p>
                        <p class="mt-3 text-sm text-white/60">
                            Presenting is covered by our
                            <a :href="route('legal.terms')" target="_blank" rel="noopener" class="link">Terms &amp; Conditions</a>
                            and
                            <a :href="route('legal.privacy')" target="_blank" rel="noopener" class="link">Privacy Policy</a>.
                        </p>
                    </div>
                </aside>
            </div>
        </div>
    </main>
</template>
