<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, computed } from 'vue';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import Button from '@/Components/Button.vue';
import Modal from '@/Components/Modal.vue';
import JourneyRail from '@/Components/JourneyRail.vue';
import SpeakerConnect from '@/Components/SpeakerConnect.vue';
import PartnerStrip from '@/Components/PartnerStrip.vue';
import TimeRuler from '@/Components/TimeRuler.vue';
import { clock, credentialStages, longDate, minutes } from '@/lib/eventFormat';

const props = defineProps({
    registration: Object,
    event: Object,
    payment: Object,
    ticket: Object,
    journey: Object,
    programme: Array,
    speakers: Array,
    speakerConnect: { type: Object, default: null },
    partners: { type: Array, default: () => [] },
    resourcesAvailableCount: Number,
    networkingOptedIn: Boolean,
    announcements: Array,
});

const page = usePage();
const firstName = computed(() => (page.props.auth?.user?.name ?? '').split(' ')[0]);

const now = ref(new Date());
let timer;

onMounted(() => { timer = setInterval(() => { now.value = new Date(); }, 1000); });
onUnmounted(() => clearInterval(timer));

// Countdown as three tabular figures rather than a sentence.
const countdown = computed(() => {
    const diff = new Date(props.event.starts_at) - now.value;
    if (diff <= 0) return null;
    return {
        days: Math.floor(diff / 86400000),
        hours: Math.floor((diff % 86400000) / 3600000),
        minutes: Math.floor((diff % 3600000) / 60000),
    };
});

// "at 07:45 on Friday 13 November" — when the Live page's player opens.
const opensAtLabel = computed(() => {
    if (!props.event.opens_at) return 'shortly before the start';
    return `at ${clock(props.event.opens_at)} on ${longDate(props.event.opens_at)}`;
});

const inPerson = computed(() => props.registration.attendance_mode === 'in_person');
const ticketConfirmed = computed(() => inPerson.value && props.ticket?.status === 'confirmed');

const stages = computed(() => credentialStages({
    inPerson: inPerson.value,
    paymentStatus: props.payment?.status ?? null,
    proofUploaded: ['under_review', 'approved'].includes(props.payment?.status),
    ticketConfirmed: props.ticket?.status === 'confirmed',
    complimentary: props.registration.complimentary,
}));

// Minutes past midnight in the event timezone, for the ruler's "now" mark while live.
const nowMinutes = computed(() => {
    if (!props.event.is_live) return null;
    return minutes(clock(now.value.toISOString()));
});

const stepTone = {
    complete: { dot: 'bg-ink-900', label: 'Done', text: 'text-ink-500' },
    current: { dot: 'bg-signal-500', label: 'Now', text: 'text-ink-900 font-medium' },
    upcoming: { dot: 'bg-rule-strong', label: 'Later', text: 'text-ink-500' },
};

const shortcuts = computed(() => [
    { label: 'Resources', href: route('resources.index'), meta: props.resourcesAvailableCount ? `${props.resourcesAvailableCount} available` : 'None yet' },
    { label: 'Networking', href: route('networking.index'), meta: props.networkingOptedIn ? 'Opted in' : 'Opt in' },
    ...(props.event.has_ended ? [{ label: 'Feedback', href: route('feedback.create'), meta: 'Tell us how it went' }] : []),
    { label: 'Account', href: route('attendee.preferences.edit'), meta: 'Details and preferences' },
    // Invited as a guest after registering: where the code goes.
    // Not once a ticket is confirmed: /redeem would refuse it.
    ...(!props.registration.complimentary && !ticketConfirmed.value && !props.event.has_ended ? [{ label: 'Invitation code', href: route('voucher.redeem'), meta: 'Redeem a guest invitation' }] : []),
]);

// Online → in person (an event that became in person only).
const confirmingSwitch = ref(false);
const switching = ref(false);

function switchToInPerson() {
    router.post(route('registration.switch-to-in-person'), {}, {
        onStart: () => { switching.value = true; },
        onFinish: () => { switching.value = false; confirmingSwitch.value = false; },
    });
}

const pad = (n) => String(n).padStart(2, '0');

const initials = (name) => name
    .replace(/^(Dr|Prof|Mr|Mrs|Ms|Adv)\.?\s+/i, '')
    .split(/\s+/)
    .filter((w) => /^[A-Za-z]/.test(w))
    .map((w) => w[0])
    .slice(0, 2)
    .join('');
</script>

<template>
    <AttendeeLayout>
    <Head title="My Dashboard" />

    <!-- Private stage: the attendee's own view of the event. -->
    <section class="stage border-t border-white/10">
        <div class="shell grid gap-10 pb-12 pt-10 sm:pt-14 lg:grid-cols-12 lg:items-end lg:gap-6 lg:pb-16">
            <div class="lg:col-span-7">
                <p class="eyebrow text-white/60">{{ event.name }} &middot; {{ registration.attendance_mode_label }}</p>
                <h1 class="display mt-4 text-display-md text-white">
                    Welcome<template v-if="firstName">, {{ firstName }}</template>
                </h1>
                <p class="tnum mt-4 text-white/70">
                    {{ longDate(event.starts_at) }} &middot; {{ clock(event.starts_at) }}<template v-if="event.venue_name && inPerson"> &middot; {{ event.venue_name }}</template>
                </p>
            </div>

            <div class="lg:col-span-5">
                <div v-if="countdown" class="flex items-end gap-6 sm:gap-8">
                    <p aria-hidden="true" v-for="unit in [['days', countdown.days], ['hours', countdown.hours], ['min', countdown.minutes]]" :key="unit[0]">
                        <span class="tnum block font-display text-5xl leading-none text-white sm:text-6xl" style="font-stretch: 72%; font-weight: 800">{{ unit[0] === 'days' ? countdown.days : pad(unit[1]) }}</span>
                        <span class="eyebrow mt-2 block text-white/60">{{ unit[0] }}</span>
                    </p>
                    <p class="sr-only">{{ countdown.days }} days, {{ countdown.hours }} hours and {{ countdown.minutes }} minutes until the event</p>
                </div>
                <p v-else-if="event.is_live" class="flex items-center gap-3 font-display text-3xl text-white" style="font-stretch: 84%; font-weight: 760">
                    <span aria-hidden="true" class="h-3 w-3 animate-pulse rounded-full bg-verified-500 motion-reduce:animate-none"></span>
                    Live now
                </p>
                <p v-else-if="event.has_ended" class="font-display text-2xl text-white/80" style="font-stretch: 86%">This event has ended.</p>

                <JourneyRail :stages="stages" on-stage class="mt-8" />
            </div>
        </div>
    </section>

    <div class="shell pb-20 pt-10 sm:pt-12">
        <!-- The one thing to do next. -->
        <section
            v-if="journey.nextStep"
            class="grid gap-5 border-t-2 border-signal-500 bg-white p-5 shadow-lift sm:grid-cols-[1fr_auto] sm:items-center sm:p-7"
            aria-labelledby="next-step-title"
        >
            <div>
                <p class="eyebrow text-signal-700">Your next step</p>
                <h2 id="next-step-title" class="mt-2 text-2xl text-ink-900">{{ journey.nextStep.label }}</h2>
                <p class="mt-1 text-sm text-ink-600">{{ journey.nextStep.hint }}</p>
            </div>
            <Button :href="journey.nextStep.url" arrow class="w-full sm:w-auto">Continue</Button>
        </section>
        <section v-else class="border-t-2 border-verified-500 bg-paper p-5 sm:p-7">
            <p class="font-medium text-ink-900">You're all caught up. Thank you for being part of {{ event.name }}.</p>
        </section>

        <section v-if="event.within_live_window && !event.has_ended" class="stage mt-6 grid gap-5 p-6 sm:grid-cols-[1fr_auto] sm:items-center sm:p-8">
            <p class="font-display text-2xl text-white" style="font-stretch: 86%; font-weight: 740">
                {{ event.is_live ? 'The session is live now.' : 'The live player is open.' }}
            </p>
            <Button :href="route('live.show')" variant="inverse" size="lg" arrow>Join live</Button>
        </section>
        <!-- Registered online for an event that became in person only. -->
        <section
            v-else-if="registration.can_switch_to_in_person"
            class="mt-6 grid gap-5 border-t-2 border-signal-500 bg-white p-6 shadow-lift sm:grid-cols-[1fr_auto] sm:items-center"
            aria-labelledby="in-person-only-title"
        >
            <div>
                <p class="eyebrow text-signal-700">A change to this event</p>
                <h2 id="in-person-only-title" class="mt-2 text-2xl text-ink-900">This event is now in person only</h2>
                <p class="mt-2 text-sm text-ink-600">
                    You registered to watch online. Switch to in person to take a seat<template v-if="event.venue_name"> at {{ event.venue_name }}</template>.
                </p>
                <p class="mt-1 text-sm text-ink-600">Can't make it? The session is recorded, and we'll let you know when the recording is released.</p>
            </div>
            <Button class="w-full sm:w-auto" @click="confirmingSwitch = true">Switch to in person (R{{ event.in_person_price.toFixed(0) }})</Button>
        </section>
        <!-- Before the live window: where and when to join, so it's never a surprise. -->
        <section v-else-if="!event.has_ended && event.offers_online" class="mt-6 grid gap-5 border border-rule bg-white p-6 sm:grid-cols-[1fr_auto] sm:items-center">
            <div>
                <p class="eyebrow text-ink-400">Watching online</p>
                <p class="mt-2 font-medium text-ink-900">
                    The live player opens <span class="tnum">{{ opensAtLabel }}</span>, on the Live page.
                </p>
                <p class="mt-1 text-sm text-ink-600">You can send in questions from there once it opens. Keep an eye on your email for reminders.</p>
            </div>
            <Button :href="route('live.show')" variant="outline" arrow>Go to the Live page</Button>
        </section>
        <section v-else-if="event.has_ended && event.recording_url" class="mt-6 grid gap-5 border border-rule bg-white p-6 sm:grid-cols-[1fr_auto] sm:items-center">
            <p class="font-medium text-ink-900">Missed it? Watch the recording.</p>
            <Button :href="event.recording_url" external variant="outline" arrow>Watch recording</Button>
        </section>

        <div class="mt-14 grid gap-14 lg:grid-cols-12 lg:gap-6">
            <div class="space-y-14 lg:col-span-7">
                <!-- Journey -->
                <section aria-labelledby="journey-title">
                    <h2 id="journey-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">Your event journey</h2>
                    <ol>
                        <li v-for="step in journey.steps" :key="step.key" class="border-b border-rule">
                            <Link :href="step.url" class="group flex items-center gap-4 py-3.5 text-sm">
                                <span aria-hidden="true" class="h-2 w-2 shrink-0 rounded-full" :class="stepTone[step.status].dot"></span>
                                <span class="flex-1 group-hover:underline" :class="stepTone[step.status].text">{{ step.label }}</span>
                                <span class="eyebrow text-ink-400">{{ stepTone[step.status].label }}</span>
                            </Link>
                        </li>
                    </ol>
                </section>

                <!-- Programme -->
                <section v-if="programme.length" aria-labelledby="dash-programme-title">
                    <!-- The only place the programme is shown: the public home
                         page leaves it out for registered attendees to see here. -->
                    <h2 id="dash-programme-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">Programme</h2>
                    <TimeRuler :sessions="programme" :now-minutes="nowMinutes" decorative class="mt-6" />
                    <ol class="mt-4">
                        <li v-for="(session, i) in programme" :key="i" class="grid grid-cols-[2.25rem_6.5rem_1fr] items-baseline gap-2 border-b border-rule py-3 text-sm">
                            <span class="tnum text-meta font-semibold text-ink-400">{{ pad(i + 1) }}</span>
                            <span class="tnum text-ink-500">{{ session.start_time }}&ndash;{{ session.end_time }}</span>
                            <span>
                                <span class="font-medium text-ink-900">{{ session.title }}</span>
                                <span v-if="session.speaker_name" class="block text-meta text-signal-700">{{ session.speaker_name }}</span>
                                <span v-if="session.description" class="mt-1 block max-w-prose text-meta text-ink-600">{{ session.description }}</span>
                            </span>
                        </li>
                    </ol>
                </section>
            </div>

            <div class="space-y-14 lg:col-span-4 lg:col-start-9">
                <!-- The credential: view it, or keep the PDF (also emailed when it was confirmed). -->
                <section v-if="ticketConfirmed" aria-labelledby="dash-ticket-title">
                    <h2 id="dash-ticket-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">Your ticket</h2>
                    <p class="flex items-center gap-2 pt-4 text-sm font-medium text-ink-900">
                        <span aria-hidden="true" class="h-2 w-2 rounded-full bg-verified-500"></span>
                        Confirmed<template v-if="registration.complimentary"> &middot; complimentary</template>
                    </p>
                    <p class="mt-1 text-meta text-ink-500">Show the QR code at the door, on your phone or printed.</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <Button :href="route('ticket.show')" size="sm">View ticket</Button>
                        <Button :href="route('ticket.pdf')" native variant="secondary" size="sm">Download PDF</Button>
                    </div>
                </section>

                <!-- Shortcuts -->
                <section aria-labelledby="shortcuts-title">
                    <h2 id="shortcuts-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">Your space</h2>
                    <ul>
                        <li v-for="s in shortcuts" :key="s.label" class="border-b border-rule">
                            <Link :href="s.href" class="group flex items-center justify-between gap-4 py-3.5">
                                <span>
                                    <span class="block text-sm font-medium text-ink-900 group-hover:underline">{{ s.label }}</span>
                                    <span class="block text-meta text-ink-500">{{ s.meta }}</span>
                                </span>
                                <span aria-hidden="true" class="text-ink-400 transition-transform group-hover:translate-x-0.5 group-hover:text-ink-900">&rarr;</span>
                            </Link>
                        </li>
                    </ul>
                </section>

                <!-- Speakers -->
                <section v-if="speakers.length" aria-labelledby="dash-speakers-title">
                    <h2 id="dash-speakers-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">Speakers</h2>
                    <p v-if="speakerConnect?.open" class="pt-3 text-meta text-ink-500">
                        Want to talk to a speaker after the event? Connect with the ones you'd like to hear from. Only those speakers receive your contact details.
                    </p>
                    <ul>
                        <li v-for="speaker in speakers" :key="speaker.id" class="flex items-center gap-4 border-b border-rule py-3.5">
                            <img v-if="speaker.photo_url" :src="speaker.photo_url" :alt="speaker.name" class="h-12 w-10 shrink-0 object-cover" />
                            <span v-else aria-hidden="true" class="flex h-12 w-10 shrink-0 items-center justify-center bg-ink-900 font-display text-sm text-white" style="font-stretch: 80%; font-weight: 700">{{ initials(speaker.name) }}</span>
                            <span class="min-w-0 flex-1 text-sm">
                                <span class="block font-medium text-ink-900">{{ speaker.name }}</span>
                                <span class="block text-meta text-ink-500">{{ speaker.title }}<template v-if="speaker.organisation"> &middot; {{ speaker.organisation }}</template></span>
                            </span>
                            <span v-if="speakerConnect" class="shrink-0">
                                <SpeakerConnect :speaker="speaker" :event-id="speakerConnect.event_id" :open="speakerConnect.open" />
                            </span>
                        </li>
                    </ul>
                </section>

                <!-- Partners -->
                <section v-if="partners.length" aria-labelledby="dash-partners-title">
                    <h2 id="dash-partners-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">Partners</h2>
                    <p class="pt-3 text-meta text-ink-500">
                        The businesses supporting this event. Open one to see what they offer; nothing is shared with them unless you choose to.
                    </p>
                    <PartnerStrip class="mt-4" :groups="partners" compact />
                </section>

                <!-- Announcements -->
                <section aria-labelledby="announcements-title">
                    <h2 id="announcements-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">Announcements</h2>
                    <p v-if="!announcements.length" class="py-4 text-sm text-ink-500">No announcements yet.</p>
                    <article v-for="(a, i) in announcements" :key="i" class="border-b border-rule py-4">
                        <p class="eyebrow text-ink-400">{{ a.type_label }} &middot; <span class="tnum">{{ a.published_at }}</span></p>
                        <h3 class="mt-1.5 font-sans text-[0.9375rem] font-semibold text-ink-900" style="font-stretch: 100%">{{ a.title }}</h3>
                        <p class="mt-1 text-sm text-ink-600">{{ a.body }}</p>
                    </article>
                </section>
            </div>
        </div>
    </div>

    <Modal :show="confirmingSwitch" title="Switch to in person" @close="confirmingSwitch = false">
        <div class="space-y-3 text-sm text-ink-800">
            <p>
                Your registration changes from online to in person<template v-if="event.venue_name">, at {{ event.venue_name }}</template>,
                on {{ longDate(event.starts_at) }}.
            </p>
            <p>
                An in-person place is R{{ event.in_person_price.toFixed(0) }}. Next you'll see how to pay by EFT; your ticket is issued once your payment is approved.
                If you've been given an invitation code, use it under "Invitation code" instead: it's free.
            </p>
        </div>
        <template #footer>
            <Button variant="secondary" @click="confirmingSwitch = false">Cancel</Button>
            <Button :disabled="switching" @click="switchToInPerson">{{ switching ? 'Switching…' : 'Switch to in person' }}</Button>
        </template>
    </Modal>
    </AttendeeLayout>
</template>
