import { router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, shallowRef, watch } from 'vue';

/**
 * A presenter on the event's Agora channel — the staff Broadcast console
 * and the remote speaker Stage page both drive one of these.
 *
 * A presenter runs two Agora clients: the camera client publishes mic +
 * camera under `uid`, and, while sharing, a second client publishes the
 * screen (and any tab/system audio) under `screenUid`. That lets the
 * audience see the slides and the presenter at the same time; a single
 * Agora client can only publish one video track.
 *
 * `fetchCredentials()` must resolve to the JSON from AgoraStage::
 * publisherCredentials(): { appId, channel, uid, token, screenUid,
 * screenToken, expiresAt, screenUidOffset, audienceUidMin }.
 *
 * `cameraEl` / `screenEl` are template refs the local preview plays into.
 */
export function useAgoraPublisher({ fetchCredentials, cameraEl, screenEl, onAirLabel = 'broadcasting' }) {
    const isSecureContext = window.isSecureContext;
    const preflight = ref({ https: isSecureContext, mic: null, camera: null, token: null });
    const error = ref(null);
    const status = ref('');

    const cameras = ref([]);
    const microphones = ref([]);
    const selectedCameraId = ref(null);
    const selectedMicId = ref(null);

    const previewing = ref(false);
    const onAir = ref(false);
    const joining = ref(false);
    const micMuted = ref(false);
    const cameraOff = ref(false);
    // A camera or mic that won't start (in use elsewhere, blocked, missing)
    // doesn't stop the broadcast: the presenter goes on without it and can
    // retry from its toggle. deviceWarning says why; it's kept apart from
    // `error`, which is for things that stop them going on air.
    const hasCamera = ref(false);
    const hasMic = ref(false);
    const deviceProblems = ref({ camera: null, mic: null });
    const deviceWarning = computed(() => {
        const problems = [deviceProblems.value.camera, deviceProblems.value.mic].filter(Boolean);
        return problems.length ? `${problems.join(' ')} You can still go on air and share your screen.` : null;
    });
    const screenSharing = ref(false);
    const othersMuted = ref(false);
    const audioLevel = ref(0);
    const uplinkQuality = ref(0);
    // Other presenters on the channel (camera uids), for "who's on stage".
    const presenters = ref([]);
    const layout = shallowRef({ screenUidOffset: 500000000, audienceUidMin: 1000000000 });

    let AgoraRTC = null;
    let cameraClient = null;
    let screenClient = null;
    let micTrack = null;
    let cameraTrack = null;
    let screenVideoTrack = null;
    let screenAudioTrack = null;
    let credentials = null;
    let renewalTimer = null;
    let levelTimer = null;
    const remoteAudio = new Map(); // uid → remote audio track

    async function loadSdk() {
        if (!AgoraRTC) {
            AgoraRTC = (await import('agora-rtc-sdk-ng')).default;
            AgoraRTC.setLogLevel?.(3); // warnings and errors only
        }
        return AgoraRTC;
    }

    const isScreenUid = (uid) => uid >= layout.value.screenUidOffset && uid < layout.value.audienceUidMin;
    const isOwnUid = (uid) => credentials && (uid === credentials.uid || uid === credentials.screenUid);

    // --- Devices ---------------------------------------------------------

    async function runPreflight() {
        error.value = null;

        if (!isSecureContext) {
            error.value = 'This page must be loaded over HTTPS for the camera and microphone to work.';
            return;
        }

        try {
            await loadSdk();
            // getDevices() opens the camera and mic to read their labels, so
            // one that's busy fails it. Listing without that still shows
            // what's plugged in; the preview then says which one won't start.
            const devices = await AgoraRTC.getDevices().catch(() => AgoraRTC.getDevices(true));
            cameras.value = devices.filter((d) => d.kind === 'videoinput');
            microphones.value = devices.filter((d) => d.kind === 'audioinput');
            // Before permission is granted the ids can be blank; pick again
            // once the real ones show up.
            if (!cameras.value.some((d) => d.deviceId === selectedCameraId.value)) selectedCameraId.value = cameras.value[0]?.deviceId || null;
            if (!microphones.value.some((d) => d.deviceId === selectedMicId.value)) selectedMicId.value = microphones.value[0]?.deviceId || null;
            preflight.value.mic = microphones.value.length > 0;
            preflight.value.camera = cameras.value.length > 0;
        } catch {
            error.value = 'Could not detect a camera or microphone. Check the browser permissions and try again.';
            preflight.value.mic = false;
            preflight.value.camera = false;
        }
    }

    function deviceProblem(e, device, retry) {
        if (e?.code === 'PERMISSION_DENIED') return `The browser blocked the ${device}. Allow access in the address bar, then choose ${retry}.`;
        if (e?.code === 'DEVICE_NOT_FOUND') return `No ${device} found. Plug one in, then choose ${retry}.`;
        return `Your ${device} is in use by another app. Close it, then choose ${retry}.`;
    }

    // Each device starts on its own, so one that fails leaves the other
    // working. Returns whether the track is there.
    async function acquireMic() {
        try {
            micTrack ??= await AgoraRTC.createMicrophoneAudioTrack({ microphoneId: selectedMicId.value || undefined, AEC: true, ANS: true });
        } catch (e) {
            deviceProblems.value = { ...deviceProblems.value, mic: deviceProblem(e, 'microphone', '"Unmute mic"') };
            micMuted.value = true;
            return false;
        }
        deviceProblems.value = { ...deviceProblems.value, mic: null };
        hasMic.value = true;
        preflight.value.mic = true;
        return true;
    }

    async function acquireCamera() {
        try {
            cameraTrack ??= await AgoraRTC.createCameraVideoTrack({ cameraId: selectedCameraId.value || undefined, encoderConfig: '720p_2' });
        } catch (e) {
            deviceProblems.value = { ...deviceProblems.value, camera: deviceProblem(e, 'camera', '"Turn camera on"') };
            cameraOff.value = true;
            return false;
        }
        deviceProblems.value = { ...deviceProblems.value, camera: null };
        hasCamera.value = true;
        preflight.value.camera = true;
        cameraTrack.play(cameraEl.value, { fit: 'cover', mirror: true });
        return true;
    }

    async function startPreview() {
        error.value = null;
        await loadSdk();

        await acquireMic();
        await acquireCamera();

        clearInterval(levelTimer);
        levelTimer = setInterval(() => {
            audioLevel.value = micTrack && !micMuted.value ? Math.round(micTrack.getVolumeLevel() * 100) : 0;
        }, 200);

        // Device labels only appear once permission is granted.
        await runPreflight();
        preflight.value.mic = hasMic.value;
        preflight.value.camera = hasCamera.value;
        previewing.value = true;
    }

    // Switching device mid-preview or mid-broadcast swaps the source in
    // place; the published track (and the audience's view) stays up.
    watch(selectedCameraId, (id) => { if (cameraTrack && id) cameraTrack.setDevice(id).catch(() => {}); });
    watch(selectedMicId, (id) => { if (micTrack && id) micTrack.setDevice(id).catch(() => {}); });

    // --- Tokens ----------------------------------------------------------

    async function getCredentials() {
        const creds = await fetchCredentials();
        credentials = creds;
        layout.value = { screenUidOffset: creds.screenUidOffset, audienceUidMin: creds.audienceUidMin };
        return creds;
    }

    function scheduleRenewal(expiresAt) {
        clearTimeout(renewalTimer);
        const msLeft = new Date(expiresAt).getTime() - Date.now();
        // Renew with a third of the lifetime (at most 5 minutes) to spare.
        const delay = Math.max(msLeft - Math.min(5 * 60 * 1000, msLeft / 3), 10000);
        renewalTimer = setTimeout(renewTokens, delay);
    }

    async function renewTokens() {
        try {
            const creds = await getCredentials();
            await cameraClient?.renewToken(creds.token);
            if (screenClient?.connectionState === 'CONNECTED') await screenClient.renewToken(creds.screenToken);
            scheduleRenewal(creds.expiresAt);
        } catch (e) {
            status.value = e?.status === 404
                ? 'This stage link has been withdrawn — you will drop off the stream shortly.'
                : 'Could not renew the connection — you may be disconnected shortly.';
        }
    }

    // --- Clients ---------------------------------------------------------

    function createCameraClient() {
        const client = AgoraRTC.createClient({ mode: 'live', codec: 'vp8' });
        client.setClientRole('host');

        client.on('connection-state-change', (state) => {
            status.value = state === 'CONNECTED' ? '' : `Connection: ${state.toLowerCase()}`;
        });
        client.on('network-quality', (stats) => { uplinkQuality.value = stats.uplinkNetworkQuality; });
        client.on('token-privilege-will-expire', renewTokens);
        client.on('token-privilege-did-expire', renewTokens);

        // Other presenters: keep a roster, and hear them (a panel needs to
        // hear each other). Audience members never appear here — in live
        // mode only hosts trigger these events.
        client.on('user-joined', (user) => {
            if (isOwnUid(user.uid) || isScreenUid(user.uid)) return;
            if (!presenters.value.includes(user.uid)) presenters.value = [...presenters.value, user.uid];
        });
        client.on('user-left', (user) => {
            presenters.value = presenters.value.filter((uid) => uid !== user.uid);
            remoteAudio.delete(user.uid);
        });
        client.on('user-published', async (user, mediaType) => {
            if (mediaType !== 'audio' || isOwnUid(user.uid)) return;
            await client.subscribe(user, 'audio');
            remoteAudio.set(user.uid, user.audioTrack);
            if (!othersMuted.value) user.audioTrack.play();
        });
        client.on('user-unpublished', (user, mediaType) => {
            if (mediaType === 'audio') remoteAudio.delete(user.uid);
        });

        return client;
    }

    async function publishScreen() {
        if (!screenClient) {
            screenClient = AgoraRTC.createClient({ mode: 'live', codec: 'vp8' });
            screenClient.setClientRole('host');
        }
        if (screenClient.connectionState === 'DISCONNECTED') {
            await screenClient.join(credentials.appId, credentials.channel, credentials.screenToken, credentials.screenUid);
        }
        await screenClient.publish([screenVideoTrack, screenAudioTrack].filter(Boolean));
    }

    async function unpublishScreen() {
        if (screenClient && screenClient.connectionState !== 'DISCONNECTED') {
            await screenClient.unpublish().catch(() => {});
            await screenClient.leave().catch(() => {});
        }
    }

    // --- Going on / off air ----------------------------------------------

    async function goOnAir() {
        error.value = null;
        joining.value = true;

        try {
            if (!previewing.value) await startPreview();

            let creds;
            try {
                creds = await getCredentials();
                preflight.value.token = true;
            } catch (e) {
                preflight.value.token = false;
                throw e;
            }

            cameraClient ??= createCameraClient();
            // Low-resolution second stream: attendees take it for the small
            // corner tile while a screen share fills the player.
            await cameraClient.enableDualStream().catch(() => {});
            await cameraClient.join(creds.appId, creds.channel, creds.token, creds.uid);
            // Whatever devices started; with none, they still join as a
            // host (on the roster) and can present by sharing the screen.
            const tracks = [micTrack, cameraTrack].filter(Boolean);
            if (tracks.length) await cameraClient.publish(tracks);

            // Shared the screen before going on air: it goes out now too.
            if (screenVideoTrack) await publishScreen();

            scheduleRenewal(creds.expiresAt);
            onAir.value = true;
        } catch (e) {
            error.value ??= e?.message || 'Could not go on air. Check the camera and microphone permissions.';
            await cameraClient?.leave().catch(() => {});
        } finally {
            joining.value = false;
        }
    }

    async function goOffAir() {
        clearTimeout(renewalTimer);
        await unpublishScreen();
        if (cameraClient) {
            await cameraClient.unpublish().catch(() => {});
            await cameraClient.leave().catch(() => {});
        }
        remoteAudio.clear();
        presenters.value = [];
        onAir.value = false;
        // The camera/mic preview keeps running so they can go on air again.
    }

    // --- Controls ----------------------------------------------------------

    // setMuted keeps the track published (no renegotiation); the audience
    // sees a name tile instead of the camera while it's off.
    async function toggleMic() {
        if (!micTrack) return retryDevice(acquireMic, () => micTrack, micMuted);
        micMuted.value = !micMuted.value;
        await micTrack.setMuted(micMuted.value);
    }

    async function toggleCamera() {
        if (!cameraTrack) return retryDevice(acquireCamera, () => cameraTrack, cameraOff);
        cameraOff.value = !cameraOff.value;
        await cameraTrack.setMuted(cameraOff.value);
    }

    // Turning on a device that never started: try it again, and if it
    // comes up while on air, publish it alongside what's already out.
    let retrying = false;
    async function retryDevice(acquire, track, offFlag) {
        if (retrying) return;
        retrying = true;
        try {
            await loadSdk();
            if (!(await acquire())) return;
            offFlag.value = false;
            if (onAir.value && cameraClient?.connectionState === 'CONNECTED') {
                await cameraClient.publish(track()).catch(() => {
                    error.value = 'The device started but could not be put on air. Go off air and on again.';
                });
            }
        } finally {
            retrying = false;
        }
    }

    function toggleOthersMuted() {
        othersMuted.value = !othersMuted.value;
        remoteAudio.forEach((track) => (othersMuted.value ? track.stop() : track.play()));
    }

    async function startScreenShare() {
        error.value = null;
        await loadSdk();

        let tracks;
        try {
            // 'auto' offers "share tab/system audio" where the browser
            // supports it (e.g. a video clip in the slides).
            tracks = await AgoraRTC.createScreenVideoTrack({ encoderConfig: '1080p_2', optimizationMode: 'detail' }, 'auto');
        } catch (e) {
            if (e?.code !== 'PERMISSION_DENIED') status.value = 'Screen sharing failed or is not supported in this browser.';
            return; // Cancelling the picker lands here too.
        }

        [screenVideoTrack, screenAudioTrack] = Array.isArray(tracks) ? tracks : [tracks, null];
        screenVideoTrack.on('track-ended', stopScreenShare); // the browser's own "Stop sharing"
        screenSharing.value = true;

        // Wait for the preview element to render (it's shown while sharing).
        await nextTick();
        screenVideoTrack.play(screenEl.value, { fit: 'contain' });

        if (onAir.value) {
            try {
                await publishScreen();
            } catch (e) {
                error.value = 'Could not put the screen share on air.';
                await stopScreenShare();
            }
        }
    }

    async function stopScreenShare() {
        await unpublishScreen();
        screenVideoTrack?.close();
        screenAudioTrack?.close();
        screenVideoTrack = null;
        screenAudioTrack = null;
        screenSharing.value = false;
    }

    function toggleScreenShare() {
        return screenSharing.value ? stopScreenShare() : startScreenShare();
    }

    // --- Leaving the page mid-broadcast --------------------------------

    // Navigating to another page (e.g. a sidebar link) would unmount this
    // one and silently drop the stream; closing the tab would too. Form
    // posts and partial reloads that stay on this page are fine.
    const removeBeforeVisit = router.on('before', (event) => {
        const visit = event.detail.visit;
        if (!onAir.value || visit.method !== 'get') return;
        if (new URL(visit.url, window.location.href).pathname === window.location.pathname) return;
        if (!window.confirm(`You are ${onAirLabel}. Leaving this page takes you off air. Leave anyway?`)) {
            event.preventDefault();
        }
    });

    function onBeforeUnload(e) {
        if (!onAir.value) return;
        e.preventDefault();
        e.returnValue = '';
    }
    window.addEventListener('beforeunload', onBeforeUnload);

    onBeforeUnmount(async () => {
        removeBeforeVisit();
        window.removeEventListener('beforeunload', onBeforeUnload);
        clearInterval(levelTimer);
        clearTimeout(renewalTimer);
        await unpublishScreen();
        await cameraClient?.leave().catch(() => {});
        micTrack?.close();
        cameraTrack?.close();
        screenVideoTrack?.close();
        screenAudioTrack?.close();
    });

    return {
        preflight,
        error,
        status,
        cameras,
        microphones,
        selectedCameraId,
        selectedMicId,
        previewing,
        onAir,
        joining,
        micMuted,
        cameraOff,
        hasCamera,
        hasMic,
        deviceWarning,
        screenSharing,
        othersMuted,
        audioLevel,
        uplinkQuality,
        presenters,
        runPreflight,
        startPreview,
        goOnAir,
        goOffAir,
        toggleMic,
        toggleCamera,
        toggleScreenShare,
        toggleOthersMuted,
    };
}

/** Agora's 0–6 network quality score, in words. */
export function qualityLabel(score) {
    return ['Unknown', 'Excellent', 'Good', 'Fair', 'Poor', 'Very poor', 'Disconnected'][score] ?? 'Unknown';
}

/**
 * POST to a credentials endpoint. axios sends Laravel's XSRF-TOKEN cookie
 * back as the CSRF header on its own (the layout has no csrf-token meta
 * tag). The HTTP status is kept on the error so callers can tell a
 * withdrawn stage link (404) from a network blip.
 */
export async function postForCredentials(url) {
    try {
        const { data } = await window.axios.post(url, {}, { headers: { Accept: 'application/json' } });
        return data;
    } catch (e) {
        const status = e?.response?.status;
        const err = new Error(status === 404
            ? 'This link is no longer valid. Ask the organisers for a new one.'
            : 'Could not get permission to go on air. Try again in a moment.');
        err.status = status;
        throw err;
    }
}
