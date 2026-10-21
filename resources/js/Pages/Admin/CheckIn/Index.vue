<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

const eventRoute = useEventRoute();

const props = defineProps({
    event: Object,
});

const form = useForm({ code: '' });

// Last scan/submit result, shown as a banner (success / already / invalid).
const banner = ref(null); // { kind: 'success'|'info'|'error', message: string }

function submitCode(code, { fromScanner = false } = {}) {
    form.code = code;

    form.post(eventRoute('admin.check-in.store'), {
        preserveScroll: true,
        onSuccess: (page) => {
            const flash = page.props.flash ?? {};
            if (flash.success) banner.value = { kind: 'success', message: flash.success };
            else if (flash.info) banner.value = { kind: 'info', message: flash.info };
            form.code = '';
            beep(flash.success ? 880 : 440);
        },
        onError: () => {
            banner.value = { kind: 'error', message: form.errors.code ?? 'Invalid code.' };
            beep(220);
        },
        onFinish: () => {
            // Continuous scan mode: keep the camera running after a result.
            if (fromScanner && continuousScan.value) {
                setTimeout(() => resumeScanning(), 1200);
            }
        },
    });
}

function submitManual() {
    if (!form.code) return;
    submitCode(form.code);
}

function beep(freq) {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.frequency.value = freq;
        osc.connect(gain);
        gain.connect(ctx.destination);
        gain.gain.setValueAtTime(0.15, ctx.currentTime);
        osc.start();
        osc.stop(ctx.currentTime + 0.12);
    } catch {
        // Audio not available (autoplay policy, unsupported browser) — the
        // visual banner is the primary result indicator regardless.
    }
}

// --- Camera scanning (html5-qrcode), dynamically imported so the library
// is only downloaded when a camera is actually available/used. ---
const cameraAvailable = ref(true);
const scanning = ref(false);
const torchOn = ref(false);
const torchSupported = ref(false);
const continuousScan = ref(true);
const scannerError = ref(null);

let scanner = null;
let scanningPaused = false;

async function startScanning() {
    scannerError.value = null;

    try {
        const { Html5Qrcode } = await import('html5-qrcode');

        scanner = new Html5Qrcode('qr-reader');

        await scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            (decodedText) => {
                if (scanningPaused) return;
                scanningPaused = true;
                submitCode(decodedText, { fromScanner: true });
            },
            () => {
                // Per-frame "no QR found" callback — expected constantly
                // while aiming the camera, not an error.
            },
        );

        scanning.value = true;

        const capabilities = scanner.getRunningTrackCapabilities?.();
        torchSupported.value = !!capabilities?.torch;
    } catch (e) {
        cameraAvailable.value = false;
        scannerError.value = e?.message ?? 'Could not access the camera. Use manual entry below.';
    }
}

function resumeScanning() {
    scanningPaused = false;
}

async function stopScanning() {
    if (scanner && scanning.value) {
        try {
            await scanner.stop();
            scanner.clear();
        } catch {
            // already stopped
        }
    }
    scanning.value = false;
    torchOn.value = false;
}

async function toggleTorch() {
    if (!scanner || !torchSupported.value) return;

    try {
        await scanner.applyVideoConstraints({ advanced: [{ torch: !torchOn.value }] });
        torchOn.value = !torchOn.value;
    } catch {
        torchSupported.value = false;
    }
}

onBeforeUnmount(() => {
    stopScanning();
});
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Check-in</h2>
        </template>

        <Head title="Check-in" />

        <div class="py-12">
            <div class="mx-auto max-w-md space-y-4 sm:px-6 lg:px-8">
                <p v-if="event?.name" class="text-sm text-gray-600">Checking in for: <strong>{{ event.name }}</strong></p>

                <!-- Success/info results are already announced by the shared
                     FlashMessages live region; only errors (which the server
                     doesn't flash) need to announce themselves. -->
                <div
                    v-if="banner"
                    :role="banner.kind === 'error' ? 'alert' : null"
                    class="rounded-lg p-3 text-sm font-medium"
                    :class="{
                        'bg-green-50 text-green-800': banner.kind === 'success',
                        'bg-blue-50 text-blue-800': banner.kind === 'info',
                        'bg-red-50 text-red-800': banner.kind === 'error',
                    }"
                >
                    {{ banner.message }}
                </div>

                <div class="rounded-lg bg-white p-6 shadow">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-700">Camera scan</h3>
                        <label class="flex items-center gap-2 text-xs text-gray-500">
                            <input v-model="continuousScan" type="checkbox" />
                            Continuous scan
                        </label>
                    </div>

                    <div id="qr-reader" class="mt-3 overflow-hidden rounded" :class="{ hidden: !scanning }"></div>

                    <p v-if="!cameraAvailable" class="mt-2 text-sm text-red-600">{{ scannerError }}</p>

                    <div class="mt-3 flex gap-2">
                        <button
                            v-if="!scanning"
                            type="button"
                            class="rounded bg-ink-900 px-4 py-2 text-sm text-white"
                            @click="startScanning"
                        >
                            Start camera scan
                        </button>
                        <button
                            v-else
                            type="button"
                            class="rounded border border-gray-300 px-4 py-2 text-sm text-gray-700"
                            @click="stopScanning"
                        >
                            Stop camera
                        </button>
                        <button
                            v-if="scanning && torchSupported"
                            type="button"
                            class="rounded border border-gray-300 px-4 py-2 text-sm text-gray-700"
                            @click="toggleTorch"
                        >
                            {{ torchOn ? 'Torch off' : 'Torch on' }}
                        </button>
                    </div>
                </div>

                <form class="space-y-3 rounded-lg bg-white p-6 shadow" @submit.prevent="submitManual">
                    <label for="manual-code" class="block text-sm font-medium">Manual entry</label>
                    <input
                        id="manual-code"
                        v-model="form.code"
                        type="text"
                        placeholder="Paste or type the ticket code"
                        class="w-full rounded border-gray-300"
                        :aria-invalid="form.errors.code ? 'true' : null"
                        :aria-describedby="form.errors.code ? 'manual-code-error' : null"
                    />
                    <p v-if="form.errors.code" id="manual-code-error" class="text-sm text-red-600">{{ form.errors.code }}</p>
                    <button type="submit" class="rounded bg-ink-900 px-4 py-2 text-sm text-white">
                        Check in
                    </button>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
