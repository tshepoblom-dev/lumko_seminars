<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useSlots, watch } from 'vue';
import { clock } from '@/lib/eventFormat';

const props = defineProps({
    provider: String,
    url: String,
    eventId: Number,
    channelName: String,
    // C3 — secondary/fallback provider, shown as a "having trouble?" switch.
    secondaryProvider: String,
    secondaryUrl: String,
    recordingUrl: String,
    // Agora only: camera uid → presenter name, for tile labels.
    presenters: { type: [Object, Array], default: () => ({}) },
    // Agora only: before the lead window the token request is refused, so
    // show when the player opens instead of a Join button.
    withinLiveWindow: { type: Boolean, default: true },
    opensAt: { type: String, default: null },
    // The side panel (chat & Q&A) laid over the right of the stage, when the
    // page passes a #panel slot. Collapsed, it leaves a button to reopen it.
    panelOpen: { type: Boolean, default: false },
    panelBadge: { type: Number, default: 0 },
});

const emit = defineEmits(['update:panelOpen']);
const slots = useSlots();
const panelShown = computed(() => !!slots.panel && props.panelOpen);

const showingSecondary = ref(false);

function embedUrlFor(provider, url) {
    if (provider === 'youtube' && url) {
        const match = url.match(/(?:v=|youtu\.be\/|embed\/)([\w-]{11})/);
        const id = match ? match[1] : null;
        // C3 — youtube-nocookie.com, not youtube.com, for embeds.
        return id ? `https://www.youtube-nocookie.com/embed/${id}` : null;
    }
    if (provider === 'facebook' && url) {
        return `https://www.facebook.com/plugins/video.php?href=${encodeURIComponent(url)}&show_text=false`;
    }
    return null;
}

const activeProvider = computed(() => (showingSecondary.value ? props.secondaryProvider : props.provider));
const activeUrl = computed(() => (showingSecondary.value ? props.secondaryUrl : props.url));
const embedUrl = computed(() => embedUrlFor(activeProvider.value, activeUrl.value));
const hasSecondary = computed(() => !!(props.secondaryProvider && props.secondaryUrl) && !showingSecondary.value);

// --- Before the live window ---------------------------------------------

const windowReached = ref(false);
let windowTimer = null;
const playerOpen = computed(() => props.withinLiveWindow || windowReached.value);
const opensAtLabel = computed(() => (props.opensAt ? clock(props.opensAt) : null));

onMounted(() => {
    if (props.withinLiveWindow || !props.opensAt) return;
    const ms = new Date(props.opensAt).getTime() - Date.now();
    // setTimeout can't wait longer than ~24.8 days; a page left open that
    // long can just be reloaded.
    if (ms <= 0) windowReached.value = true;
    else if (ms < 2 ** 31 - 1) windowTimer = setTimeout(() => { windowReached.value = true; }, ms);
});

// --- Agora audience -----------------------------------------------------

const agoraState = ref('idle'); // idle | joining | joined | error
const agoraError = ref(null);
const agoraMuted = ref(false);
const agoraVolume = ref(100);
const networkQuality = ref(null);

// One entry per presenter stream on the channel. Tracks live outside Vue's
// reactivity (Agora objects don't like being proxied).
const streams = ref([]); // { uid, kind: 'camera' | 'screen', hasVideo, hasAudio }
const tracks = new Map(); // uid → { video, audio, el }
let layoutHints = { screenUidOffset: 500000000, audienceUidMin: 1000000000 };

let AgoraRTC = null;
let client = null;
let tokenRenewalTimer = null;
let leaving = false;

async function loadSdk() {
    if (AgoraRTC) return AgoraRTC;
    const mod = await import('agora-rtc-sdk-ng');
    AgoraRTC = mod.default;
    return AgoraRTC;
}

const isScreenUid = (uid) => uid >= layoutHints.screenUidOffset && uid < layoutHints.audienceUidMin;
const cameraUidOf = (uid) => (isScreenUid(uid) ? uid - layoutHints.screenUidOffset : uid);

function presenterName(uid) {
    const cameraUid = cameraUidOf(uid);
    return props.presenters?.[cameraUid] ?? (cameraUid < 100000 ? 'Host' : 'Speaker');
}

function upsertStream(uid, changes) {
    const existing = streams.value.find((s) => s.uid === uid);
    if (existing) {
        Object.assign(existing, changes);
    } else {
        streams.value.push({ uid, kind: isScreenUid(uid) ? 'screen' : 'camera', hasVideo: false, hasAudio: false, ...changes });
    }
}

// --- Layout: the screen fills the frame with cameras as corner tiles;
// with no screen, cameras share the frame in a grid.

const liveStreams = computed(() => streams.value.filter((s) => s.hasVideo || (s.kind === 'camera' && s.hasAudio)));
const mainScreen = computed(() => liveStreams.value.find((s) => s.kind === 'screen' && s.hasVideo) ?? null);
const waitingForHost = computed(() => agoraState.value === 'joined' && liveStreams.value.length === 0);

const tiles = computed(() => {
    const list = liveStreams.value;

    if (mainScreen.value) {
        const others = list.filter((s) => s !== mainScreen.value);
        return [
            { stream: mainScreen.value, role: 'main', style: { inset: '0' } },
            ...others.map((stream, i) => ({
                stream,
                role: 'corner',
                // Clear of the control bar along the bottom.
                style: { right: `calc(0.75rem + ${i} * (22% + 0.5rem))`, bottom: '4.25rem', width: '22%', aspectRatio: '16 / 9' },
            })),
        ];
    }

    const cols = Math.ceil(Math.sqrt(list.length));
    const rows = Math.ceil(list.length / cols) || 1;
    return list.map((stream, i) => ({
        stream,
        role: 'grid',
        style: {
            left: `${(i % cols) * (100 / cols)}%`,
            top: `${Math.floor(i / cols) * (100 / rows)}%`,
            width: `${100 / cols}%`,
            height: `${100 / rows}%`,
        },
    }));
});

// Each tile's element is stable per uid (one v-for, keyed by uid), so a
// track only needs replaying when it or its element changes.
function bindTile(uid, el) {
    const entry = tracks.get(uid);
    if (!entry) return;
    entry.el = el ?? null;
    playVideo(uid);
}

function playVideo(uid) {
    const entry = tracks.get(uid);
    if (!entry?.video || !entry.el || entry.playingIn === entry.el) return;
    entry.video.play(entry.el, { fit: isScreenUid(uid) ? 'contain' : 'cover' });
    entry.playingIn = entry.el;
}

// Corner tiles take the presenter's low-resolution stream (if they sent one).
watch(mainScreen, (screen) => {
    if (!client) return;
    streams.value.filter((s) => s.kind === 'camera').forEach((s) => {
        client.setRemoteVideoStreamType(s.uid, screen ? 1 : 0).catch(() => {});
    });
});

// --- Joining ------------------------------------------------------------

async function fetchToken() {
    const response = await fetch(route('live.agora-token', props.eventId), { headers: { Accept: 'application/json' } });
    if (!response.ok) {
        throw new Error(response.status === 403
            ? 'The live session has not opened yet.'
            : 'Could not get a viewing token for this session.');
    }
    return response.json();
}

function scheduleRenewal(expiresAt) {
    if (tokenRenewalTimer) clearTimeout(tokenRenewalTimer);
    const msUntilExpiry = new Date(expiresAt).getTime() - Date.now();
    const delay = Math.max(msUntilExpiry - 5 * 60 * 1000, 10000);
    tokenRenewalTimer = setTimeout(renewToken, delay);
}

async function renewToken() {
    try {
        const { token, expiresAt } = await fetchToken();
        await client.renewToken(token);
        scheduleRenewal(expiresAt);
    } catch (e) {
        // Will try again on the SDK's own did-expire event, or the viewer
        // can just rejoin manually if the connection drops.
    }
}

function createClient() {
    const c = AgoraRTC.createClient({ mode: 'live', codec: 'vp8' });
    c.setClientRole('audience');

    c.on('user-published', async (user, mediaType) => {
        await c.subscribe(user, mediaType);
        const entry = tracks.get(user.uid) ?? {};
        tracks.set(user.uid, entry);

        if (mediaType === 'video') {
            entry.video = user.videoTrack;
            entry.playingIn = null;
            upsertStream(user.uid, { hasVideo: true });
            if (mainScreen.value && !isScreenUid(user.uid)) c.setRemoteVideoStreamType(user.uid, 1).catch(() => {});
            playVideo(user.uid);
        }
        if (mediaType === 'audio') {
            entry.audio = user.audioTrack;
            upsertStream(user.uid, { hasAudio: true });
            entry.audio.setVolume(agoraVolume.value);
            if (!agoraMuted.value) entry.audio.play();
        }
    });

    // A camera turned off (or a mic muted) unpublishes that track; the
    // presenter's tile stays, showing their name, until they leave.
    c.on('user-unpublished', (user, mediaType) => {
        const entry = tracks.get(user.uid);
        if (mediaType === 'video') {
            if (entry) { entry.video = null; entry.playingIn = null; }
            upsertStream(user.uid, { hasVideo: false });
        } else {
            if (entry) entry.audio = null;
            upsertStream(user.uid, { hasAudio: false });
        }
    });
    c.on('user-left', (user) => {
        tracks.delete(user.uid);
        streams.value = streams.value.filter((s) => s.uid !== user.uid);
    });
    c.on('network-quality', (stats) => {
        networkQuality.value = stats.downlinkNetworkQuality;
    });
    c.on('connection-state-change', (state) => {
        if (state === 'RECONNECTING') agoraState.value = 'joining';
        if (state === 'CONNECTED') agoraState.value = 'joined';
        if (state === 'DISCONNECTED' && !leaving) agoraState.value = 'error';
    });
    c.on('token-privilege-will-expire', renewToken);
    c.on('token-privilege-did-expire', renewToken);

    return c;
}

async function joinAgora() {
    agoraState.value = 'joining';
    agoraError.value = null;

    try {
        await loadSdk();
        client ??= createClient();

        const { appId, channel, uid, token, expiresAt, screenUidOffset, audienceUidMin } = await fetchToken();
        if (screenUidOffset) layoutHints = { screenUidOffset, audienceUidMin };
        await client.join(appId, channel, token, uid);
        scheduleRenewal(expiresAt);

        agoraState.value = 'joined';
    } catch (e) {
        agoraError.value = e?.message ?? 'Could not join the live session.';
        agoraState.value = 'error';
    }
}

function toggleMute() {
    agoraMuted.value = !agoraMuted.value;
    tracks.forEach(({ audio }) => {
        if (!audio) return;
        if (agoraMuted.value) audio.stop();
        else audio.play();
    });
}

function setVolume(value) {
    agoraVolume.value = value;
    tracks.forEach(({ audio }) => audio?.setVolume(value));
}

// --- Full screen --------------------------------------------------------

// The browser's own full screen where it exists; iPhone Safari only allows
// that for <video>, so there the player covers the page instead.
const playerEl = ref(null);
const nativeFullscreen = ref(false);
const pseudoFullscreen = ref(false);
const isFullscreen = computed(() => nativeFullscreen.value || pseudoFullscreen.value);

const fullscreenElement = () => document.fullscreenElement ?? document.webkitFullscreenElement ?? null;

function syncFullscreen() {
    nativeFullscreen.value = !!playerEl.value && fullscreenElement() === playerEl.value;
    if (!nativeFullscreen.value) screen.orientation?.unlock?.();
    showControls();
}

async function enterFullscreen() {
    const el = playerEl.value;
    const request = el?.requestFullscreen ?? el?.webkitRequestFullscreen;
    if (request) {
        try {
            await request.call(el);
            // Phones: turn the stream sideways where the browser allows it.
            await screen.orientation?.lock?.('landscape').catch(() => {});
            return;
        } catch {
            // Refused (e.g. inside an iframe): fall through to the cover mode.
        }
    }
    pseudoFullscreen.value = true;
    document.documentElement.style.overflow = 'hidden';
    showControls();
}

function exitFullscreen() {
    if (pseudoFullscreen.value) {
        pseudoFullscreen.value = false;
        document.documentElement.style.overflow = '';
        showControls();
        return;
    }
    (document.exitFullscreen ?? document.webkitExitFullscreen)?.call(document)?.catch?.(() => {});
}

function toggleFullscreen() {
    return isFullscreen.value ? exitFullscreen() : enterFullscreen();
}

function onKeydown(e) {
    if (e.key === 'Escape' && pseudoFullscreen.value) exitFullscreen();
}

onMounted(() => {
    document.addEventListener('fullscreenchange', syncFullscreen);
    document.addEventListener('webkitfullscreenchange', syncFullscreen);
    document.addEventListener('keydown', onKeydown);
});

// --- Floating controls: fade out after a few idle seconds while watching
// or in full screen, back on any movement, tap or keyboard focus.

const CONTROLS_IDLE_MS = 3000;
const controlsVisible = ref(true);
const hoveringControls = ref(false);
const keyboardInControls = ref(false);
let controlsTimer = null;

function showControls() {
    controlsVisible.value = true;
    clearTimeout(controlsTimer);
    controlsTimer = setTimeout(() => {
        const watching = agoraState.value === 'joined' || isFullscreen.value;
        if (watching && !hoveringControls.value && !keyboardInControls.value) {
            controlsVisible.value = false;
        }
    }, CONTROLS_IDLE_MS);
}

function onPlayerPointerMove(e) {
    if (e.pointerType === 'mouse') showControls();
}

// A tap on the video itself shows or hides the bar (touch has no hover).
function onPlayerPointerDown(e) {
    const onVideo = !e.target.closest('button, a, input, textarea, [data-player-controls], [data-side-panel], [data-stage-overlay]');
    if (e.pointerType !== 'mouse' && onVideo && controlsVisible.value && (agoraState.value === 'joined' || isFullscreen.value)) {
        clearTimeout(controlsTimer);
        controlsVisible.value = false;
        return;
    }
    showControls();
}

// Keyboard focus holds the bar open; a mouse click leaving focus on a
// button doesn't (it would never fade again).
function onControlsFocusIn(e) {
    keyboardInControls.value = e.target.matches?.(':focus-visible') ?? false;
    showControls();
}

function onControlsFocusOut(e) {
    if (!e.currentTarget.contains(e.relatedTarget)) {
        keyboardInControls.value = false;
        showControls();
    }
}

function onControlsPointerEnter(e) {
    if (e.pointerType === 'mouse') hoveringControls.value = true;
}

function onControlsPointerLeave() {
    hoveringControls.value = false;
    showControls();
}

watch(agoraState, showControls);

onBeforeUnmount(() => {
    leaving = true;
    if (tokenRenewalTimer) clearTimeout(tokenRenewalTimer);
    if (windowTimer) clearTimeout(windowTimer);
    clearTimeout(controlsTimer);
    document.removeEventListener('fullscreenchange', syncFullscreen);
    document.removeEventListener('webkitfullscreenchange', syncFullscreen);
    document.removeEventListener('keydown', onKeydown);
    if (pseudoFullscreen.value) document.documentElement.style.overflow = '';
    client?.leave();
});
</script>

<template>
    <div>
        <!-- The stage: the stream, plus whatever the page lays over it (side
             panel, poll card, floating reactions). Full screen takes all of it. -->
        <div
            ref="playerEl"
            data-player
            class="relative w-full overflow-hidden bg-ink-950"
            :class="[
                pseudoFullscreen ? 'fixed inset-0 z-50 h-[100dvh] w-screen' : 'aspect-video',
                isFullscreen && !controlsVisible ? 'cursor-none' : '',
            ]"
            :style="{ '--panel-w': 'min(22rem, 45%)' }"
            @pointermove="onPlayerPointerMove"
            @pointerdown="onPlayerPointerDown"
            @keydown="showControls"
        >
            <!-- The stream itself steps left of the side panel while it's open,
                 so nothing plays underneath it. -->
            <div
                data-stage-content
                class="absolute inset-y-0 left-0"
                :style="{ right: panelShown ? 'var(--panel-w)' : '0' }"
            >
                <iframe
                    v-if="embedUrl"
                    :src="embedUrl"
                    class="h-full w-full"
                    allow="autoplay; encrypted-media; picture-in-picture"
                    allowfullscreen
                ></iframe>

                <div
                    v-else-if="activeProvider === 'agora'"
                    class="relative flex h-full w-full items-center justify-center"
                >
                    <div v-if="agoraState === 'idle' && !playerOpen" class="relative z-10 max-w-sm px-6 text-center text-white">
                        <p class="font-medium">The live player opens{{ opensAtLabel ? ` at ${opensAtLabel}` : ' shortly before the start' }}.</p>
                        <p class="mt-2 text-sm text-white/70">This page updates by itself. There's no need to refresh.</p>
                    </div>
                    <div v-else-if="agoraState === 'idle'" class="relative z-10 text-center text-white">
                        <button class="rounded-ctl bg-white px-6 py-3 font-medium text-ink-900 hover:bg-signal-50" @click="joinAgora">Join live session</button>
                    </div>
                    <div v-else-if="agoraState === 'joining'" class="relative z-10 text-center text-white">
                        <p>Connecting…</p>
                    </div>
                    <div v-else-if="agoraState === 'error'" class="relative z-10 max-w-sm text-center text-white">
                        <p class="text-sm text-red-300">{{ agoraError || 'Connection lost.' }}</p>
                        <button class="mt-3 rounded bg-white/10 px-4 py-2 text-sm" @click="joinAgora">Try again</button>
                        <button v-if="hasSecondary" class="mt-2 block w-full text-sm underline" @click="showingSecondary = true">
                            Watch on {{ secondaryProvider }} instead
                        </button>
                    </div>
                    <p v-if="waitingForHost" class="absolute left-1/2 top-3 z-20 -translate-x-1/2 rounded bg-black/60 px-3 py-1 text-xs text-white">
                        Waiting for the host to start…
                    </p>

                    <!-- Presenter tiles: one element per stream, positioned by role. -->
                    <div v-show="agoraState === 'joined'" class="absolute inset-0">
                        <div
                            v-for="tile in tiles"
                            :key="tile.stream.uid"
                            class="absolute overflow-hidden bg-ink-900"
                            :class="tile.role === 'corner' ? 'z-10 ring-2 ring-white/80' : ''"
                            :style="tile.style"
                        >
                            <div :ref="(el) => bindTile(tile.stream.uid, el)" class="absolute inset-0"></div>
                            <div
                                v-if="!tile.stream.hasVideo"
                                class="absolute inset-0 flex items-center justify-center px-2 text-center text-white"
                                :class="tile.role === 'corner' ? 'text-xs' : 'text-lg'"
                            >
                                {{ presenterName(tile.stream.uid) }}
                            </div>
                            <span
                                v-else-if="tile.stream.kind === 'camera' && (tile.role !== 'main') && liveStreams.length > 1"
                                class="absolute bottom-1 left-1 max-w-[90%] truncate bg-black/60 px-1.5 py-0.5 text-[0.6875rem] text-white"
                            >
                                {{ presenterName(tile.stream.uid) }}
                            </span>
                        </div>
                    </div>

                    <!-- Floating controls: fade out while watching, back on any movement. -->
                    <div
                        v-if="playerOpen || agoraState !== 'idle'"
                        data-player-controls
                        class="absolute inset-x-0 bottom-0 z-30 flex flex-wrap items-center gap-2 bg-gradient-to-t from-black/80 to-transparent px-3 pb-3 pt-8 motion-safe:transition-opacity motion-safe:duration-300"
                        :class="controlsVisible ? 'opacity-100' : 'pointer-events-none opacity-0'"
                        @pointerenter="onControlsPointerEnter"
                        @pointerleave="onControlsPointerLeave"
                        @focusin="onControlsFocusIn"
                        @focusout="onControlsFocusOut"
                    >
                        <div v-if="agoraState === 'joined'" class="flex items-center gap-2 rounded bg-black/60 px-2">
                            <button type="button" class="min-h-9 px-1 text-sm text-white" @click="toggleMute">{{ agoraMuted ? 'Unmute' : 'Mute' }}</button>
                            <input
                                type="range" min="0" max="100" :value="agoraVolume"
                                class="h-1 w-20"
                                aria-label="Volume"
                                @input="setVolume($event.target.valueAsNumber)"
                            />
                        </div>
                        <div v-if="$slots.controls" class="flex flex-wrap items-center gap-1.5">
                            <slot name="controls" />
                        </div>
                        <button
                            type="button"
                            class="ml-auto inline-flex h-9 w-9 items-center justify-center rounded bg-black/60 text-white hover:bg-black/80"
                            :aria-label="isFullscreen ? 'Exit full screen' : 'Full screen'"
                            :title="isFullscreen ? 'Exit full screen' : 'Full screen'"
                            @click="toggleFullscreen"
                        >
                            <svg v-if="!isFullscreen" aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />
                            </svg>
                            <svg v-else aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div v-else class="flex h-full w-full items-center justify-center text-white">
                    Livestream not yet configured for this event.
                </div>

                <!-- Poll card, floating reactions: over the stream, clear of the panel. -->
                <slot name="stage" />
            </div>

            <div
                v-if="$slots.panel"
                v-show="panelOpen"
                data-side-panel
                class="absolute inset-y-0 right-0 z-40"
                :style="{ width: 'var(--panel-w)' }"
            >
                <slot name="panel" />
            </div>
            <button
                v-if="$slots.panel && !panelOpen"
                type="button"
                class="absolute right-3 top-3 z-40 inline-flex min-h-9 items-center gap-2 rounded-ctl bg-black/70 px-3 text-sm font-medium text-white backdrop-blur hover:bg-black/85"
                @click="emit('update:panelOpen', true)"
            >
                Chat &amp; Q&amp;A
                <span v-if="panelBadge" class="tnum rounded-full bg-white px-1.5 text-xs font-semibold text-ink-900">{{ panelBadge }}</span>
            </button>
        </div>

        <button
            v-if="hasSecondary && activeProvider === 'agora' && agoraState !== 'error'"
            type="button"
            class="mt-2 text-sm text-white/70 underline underline-offset-4 hover:text-white"
            @click="showingSecondary = true"
        >
            Having trouble? Watch on {{ secondaryProvider }}
        </button>
        <button
            v-if="showingSecondary"
            type="button"
            class="mt-2 text-sm text-white/70 underline underline-offset-4 hover:text-white"
            @click="showingSecondary = false"
        >
            Back to the main stream
        </button>

        <p v-if="recordingUrl" class="mt-2 text-sm">
            <a :href="recordingUrl" class="link" target="_blank" rel="noopener">Watch the recording</a>
        </p>
    </div>
</template>
