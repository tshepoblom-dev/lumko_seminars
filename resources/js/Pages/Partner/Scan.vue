<script setup>
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import PortalPage from '@/Components/PortalPage.vue';
import TextInput from '@/Components/TextInput.vue';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import { Head } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, ref } from 'vue';

// Leads at the stand. Scan the attendee's ticket QR (or type the code),
// then hand the device to the attendee: they read the consent sentence and
// choose. Nothing is shared until they tap "Yes"; the screen shows only
// their first name and organisation until then.
const props = defineProps({
    partner: Object,
    event: Object,
    tier: { type: String, default: null },
    features: { type: Array, default: () => [] },
    standUrl: { type: String, default: null },
    standQrSvg: { type: String, default: null },
});

const state = ref('idle'); // idle | scanning | asking | done
const message = ref(null);
const error = ref(null);
const pending = ref(null); // { scan_id, first_name, organisation, consent_text }
const code = ref('');
const busy = ref(false);
const askHeading = ref(null);

const videoEl = ref(null);
let scanner = null;

const asJson = { headers: { Accept: 'application/json' } };
const base = () => [props.partner.slug, props.event.id];

async function startCamera() {
    error.value = null;
    message.value = null;
    state.value = 'scanning';
    await nextTick();

    try {
        const QrScanner = (await import('qr-scanner')).default;
        scanner = new QrScanner(videoEl.value, (result) => lookup(result.data), {
            returnDetailedScanResult: true,
            highlightScanRegion: true,
            maxScansPerSecond: 4,
        });
        await scanner.start();
    } catch {
        state.value = 'idle';
        error.value = 'The camera could not start. Allow camera access in your browser, or type the code below.';
        stopCamera();
    }
}

function stopCamera() {
    scanner?.stop();
    scanner?.destroy();
    scanner = null;
}

async function lookup(raw) {
    if (busy.value) return;
    busy.value = true;
    error.value = null;
    message.value = null;
    stopCamera();

    try {
        const { data } = await window.axios.post(route('partner.events.scan.lookup', base()), { code: raw }, asJson);
        pending.value = data;
        state.value = 'asking';
        code.value = '';
        await nextTick();
        askHeading.value?.focus();
    } catch (e) {
        state.value = 'idle';
        if (e?.response?.status === 409) message.value = e.response.data.message;
        else if (e?.response?.status === 429) error.value = 'Too many scans in a minute. Wait a moment, then try again.';
        else error.value = e?.response?.data?.message ?? 'That code could not be checked. Try again.';
    } finally {
        busy.value = false;
    }
}

async function answer(yes) {
    busy.value = true;
    try {
        const url = route(yes ? 'partner.events.scan.confirm' : 'partner.events.scan.decline', [...base(), pending.value.scan_id]);
        const { data } = yes
            ? await window.axios.post(url, {}, asJson)
            : await window.axios.delete(url, asJson);
        message.value = data.message;
    } catch {
        error.value = 'That took too long, so nothing was saved. Please scan again.';
    } finally {
        pending.value = null;
        state.value = 'done';
        busy.value = false;
    }
}

onBeforeUnmount(stopCamera);
</script>

<template>
    <PartnerLayout :partner="partner" :event="event" :features="features">
        <Head :title="`Scan · ${event.name}`" />

        <PortalPage :eyebrow="event.name" title="Scan at your stand" width="wide">
            <!-- The attendee's turn: shown full width, nothing else to tap by mistake. -->
            <section v-if="state === 'asking' && pending" class="stage -mx-4 px-6 py-10 sm:mx-0 sm:px-10" aria-labelledby="ask-title">
                <p class="eyebrow text-white/60">Please hand this screen to the attendee</p>
                <h2 id="ask-title" ref="askHeading" tabindex="-1" class="display mt-4 text-display-sm text-white focus:outline-none">
                    Hi {{ pending.first_name }}<template v-if="pending.organisation"> from {{ pending.organisation }}</template>
                </h2>
                <p class="mt-5 max-w-prose text-lg leading-relaxed text-white/85">{{ pending.consent_text }}</p>
                <div class="mt-8 flex flex-wrap gap-4">
                    <Button variant="inverse" size="lg" :disabled="busy" @click="answer(true)">Yes, share my details</Button>
                    <Button variant="ghost" size="lg" :disabled="busy" @click="answer(false)">No thanks</Button>
                </div>
            </section>

            <div v-else class="grid gap-10 lg:grid-cols-12">
                <section class="lg:col-span-7" aria-labelledby="scan-title">
                    <h2 id="scan-title" class="sr-only">Scan a ticket</h2>
                    <p class="max-w-prose text-[0.9375rem] text-ink-600">
                        Scan the QR code on an attendee's ticket (on their phone or printed PDF). They then confirm on your screen whether to share their details with {{ partner.name }}.
                    </p>

                    <p v-if="message" class="mt-6 border-t-2 border-verified-500 bg-paper p-4 text-sm text-ink-900" role="status">{{ message }}</p>
                    <p v-if="error" class="mt-6 border-t-2 border-red-700 bg-paper p-4 text-sm text-red-800" role="alert">{{ error }}</p>

                    <div v-show="state === 'scanning'" class="relative mt-6 aspect-square w-full max-w-md overflow-hidden bg-ink-950">
                        <video ref="videoEl" class="h-full w-full object-cover" muted playsinline></video>
                    </div>
                    <div class="mt-6 flex flex-wrap gap-3">
                        <Button v-if="state !== 'scanning'" @click="startCamera">{{ state === 'done' ? 'Scan the next ticket' : 'Start the camera' }}</Button>
                        <Button v-else variant="secondary" @click="stopCamera(); state = 'idle'">Stop the camera</Button>
                    </div>

                    <form class="mt-10 max-w-md border-t border-rule pt-6" @submit.prevent="lookup(code)">
                        <FormField id="scan-code" label="Or type the ticket code" hint="The code printed under the QR on their ticket." v-slot="{ id, describedBy }">
                            <TextInput :id="id" v-model="code" autocomplete="off" autocapitalize="off" spellcheck="false" required :aria-describedby="describedBy" />
                        </FormField>
                        <Button type="submit" variant="secondary" class="mt-4" :disabled="busy || !code.trim()">Look up</Button>
                    </form>
                </section>

                <aside v-if="standQrSvg" class="lg:col-span-5" aria-labelledby="stand-qr-title">
                    <h2 id="stand-qr-title" class="eyebrow text-ink-500">Your stand QR</h2>
                    <p class="mt-2 text-sm text-ink-600">
                        Print or show this at your stand. Attendees scan it with their own phone and can connect with you from your partner page.
                    </p>
                    <!-- eslint-disable-next-line vue/no-v-html -- server-generated SVG -->
                    <div class="mt-4 inline-block border border-rule bg-white p-3" role="img" :aria-label="`QR code linking to ${partner.name}'s partner page`" v-html="standQrSvg"></div>
                    <p class="mt-2 break-all text-meta text-ink-500">{{ standUrl }}</p>
                </aside>
            </div>
        </PortalPage>
    </PartnerLayout>
</template>
