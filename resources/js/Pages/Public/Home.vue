<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Button from '@/Components/Button.vue';
import EventFacts from '@/Components/EventFacts.vue';
import JourneyRail from '@/Components/JourneyRail.vue';
import StagePhoto from '@/Components/StagePhoto.vue';
import SeriesBrief from '@/Components/SeriesBrief.vue';
import PartnerStrip from '@/Components/PartnerStrip.vue';
import { clock, dateParts, duration, longDate, mapsUrl, rand } from '@/lib/eventFormat';

const props = defineProps({
    event: Object,
    series: Object,
});

const minutesLong = computed(() => {
    if (!props.event) return '';
    return duration(clock(props.event.starts_at), clock(props.event.ends_at));
});

const date = computed(() => (props.event ? dateParts(props.event.starts_at) : null));
const directions = computed(() => (props.event ? mapsUrl(props.event.venue_name, props.event.venue_address) : null));
const inPersonPrice = computed(() => (props.event ? rand(props.event.in_person_price) : ''));
const onlinePrice = computed(() => (props.event ? rand(props.event.online_price) : ''));
// An in-person-only event never mentions online (event settings).
const onlineOffered = computed(() => props.event?.offers_online !== false);

const initials = (name) => name
    .replace(/^(Dr|Prof|Mr|Mrs|Ms|Adv)\.?\s+/i, '')
    .split(/\s+/)
    .filter((w) => /^[A-Za-z]/.test(w))
    .map((w) => w[0])
    .slice(0, 2)
    .join('');

const route_explainer = [
    { key: 'register', label: 'Register', state: 'todo' },
    { key: 'attend', label: 'In person', state: 'todo' },
    { key: 'pay', label: 'Pay by EFT', state: 'todo' },
    { key: 'verify', label: 'Verify', state: 'todo' },
    { key: 'ticket', label: 'Ticket', state: 'todo' },
];

// The numbered sections actually on the page, in order. Indexes are counted
// from what is shown, so hiding one (Attend after the event) leaves no gap.
// The programme is deliberately absent: attendees see it on their dashboard.
const sections = computed(() => {
    const e = props.event;
    if (!e) return [];
    return [
        { id: 'brief', label: null, show: true },
        { id: 'speakers', label: 'Speakers', show: e.speakers.length > 0 },
        { id: 'partners', label: 'Partners', show: (e.partners ?? []).length > 0 },
        { id: 'attend', label: 'Attend', show: !e.has_ended },
        { id: 'venue', label: 'Venue', show: !!e.venue_name },
        { id: 'series', label: 'The series', show: !!props.series },
    ]
        .filter((s) => s.show)
        .map((s, i) => ({ ...s, index: String(i + 1).padStart(2, '0') }));
});

const indexOf = (id) => sections.value.find((s) => s.id === id)?.index ?? '';
const navSections = computed(() => sections.value.filter((s) => s.label));
</script>

<template>
    <PublicLayout tone="stage">
        <Head :title="event ? event.name : 'Lumko Events'" />

        <template v-if="event" #nav>
            <ul class="flex items-center gap-8 text-sm">
                <li v-for="s in navSections" :key="s.id">
                    <a
                        :href="`#${s.id}`"
                        class="text-white/70 transition-colors hover:text-white"
                    >{{ s.label }}</a>
                </li>
            </ul>
        </template>

        <template v-if="event">
            <!-- While the event is live: the way in, above everything else.
                 /live itself still requires sign-in and a registration. -->
            <Link
                v-if="event.is_live"
                :href="route('live.show')"
                class="group block border-b border-white/15 bg-ink-950 text-white"
            >
                <span class="shell flex min-h-11 items-center gap-3 py-2 text-sm">
                    <span aria-hidden="true" class="h-2 w-2 shrink-0 animate-pulse rounded-full bg-red-500 motion-reduce:animate-none"></span>
                    <span class="font-semibold tracking-wide">LIVE NOW</span>
                    <span class="text-white/75 group-hover:text-white">Registered? Join the live session</span>
                    <span aria-hidden="true" class="transition-transform duration-150 group-hover:translate-x-0.5">&rarr;</span>
                </span>
            </Link>

            <!-- ─── Stage hero ─────────────────────────────────────────── -->
            <section class="stage relative isolate" aria-labelledby="event-title">
                <!-- Venue photograph: full-bleed on phones, flush to the right edge on desktop. -->
                <StagePhoto
                    :src="event.hero_image_url"
                    :alt="event.hero_image_alt || event.venue_name || ''"
                    eager
                    class="aspect-[16/9] w-full lg:absolute lg:inset-y-0 lg:right-0 lg:aspect-auto lg:w-[40%]"
                >
                    <template #fallback>
                        <div class="flex h-full flex-col justify-between bg-ink-800 p-6 sm:p-10">
                            <p class="eyebrow text-white/60">Breakfast seminar</p>
                            <p
                                class="ghost-text tnum font-display text-[clamp(3.5rem,9vw,7rem)] leading-none text-white/[0.12]"
                                style="font-stretch: 70%; font-weight: 800"
                                aria-hidden="true"
                                :data-ghost="clock(event.starts_at)"
                            ></p>
                        </div>
                    </template>
                </StagePhoto>

                <div class="shell relative grid pb-16 pt-10 sm:pb-20 lg:grid-cols-12 lg:pb-28 lg:pt-20">
                    <div class="lg:col-span-7 lg:pr-8">
                        <p class="eyebrow rise flex flex-wrap items-center gap-x-3 gap-y-1 text-white/60">
                            <span>Lumko Business Technology Partners presents</span>
                            <span aria-hidden="true" class="hidden h-px w-8 bg-signal-500 sm:inline-block"></span>
                            <span class="tnum text-white">{{ date.day }} {{ date.month }} {{ date.year }}</span>
                        </p>

                        <h1
                            id="event-title"
                            class="display rise mt-6 max-w-[11ch] text-display-xl text-white"
                            style="--rise-delay: 80ms"
                        >{{ event.name }}</h1>

                        <p class="rise mt-6 max-w-[36ch] text-lede text-white/75" style="--rise-delay: 160ms">
                            {{ event.subtitle }}
                        </p>

                        <div class="rise mt-10 border-t border-white/15 pt-6" style="--rise-delay: 240ms">
                            <EventFacts :event="event" on-stage />
                        </div>

                        <div v-if="event.has_ended" class="rise mt-10 max-w-xl" style="--rise-delay: 320ms">
                            <p class="text-white/80">
                                Thank you to everyone who joined us. Registration for this event is now closed.
                            </p>
                            <Button
                                v-if="event.recording_url"
                                :href="event.recording_url"
                                external
                                variant="inverse"
                                size="lg"
                                arrow
                                class="mt-6"
                            >Watch the recording</Button>
                        </div>
                        <div
                            v-else
                            class="rise mt-10 flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-6"
                            style="--rise-delay: 320ms"
                        >
                            <Button :href="route('event.register')" variant="inverse" size="lg" arrow class="w-full sm:w-auto">
                                Register to attend
                            </Button>
                            <p v-if="onlineOffered" class="text-meta text-white/60">
                                Online {{ onlinePrice.toLowerCase() }}<span aria-hidden="true"> &middot; </span><span class="sr-only">, </span>In person <span class="tnum">{{ inPersonPrice }}</span>
                            </p>
                            <p v-else class="text-meta text-white/60">
                                In person <span class="tnum">{{ inPersonPrice }}</span>
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ─── Brief ──────────────────────────────────────────────── -->
            <section class="shell grid gap-y-6 py-20 sm:py-24 lg:grid-cols-12 lg:py-32" aria-labelledby="brief-title">
                <p class="section-index lg:col-span-2">{{ indexOf('brief') }} <span class="text-ink-400">/</span> Brief</p>
                <div class="lg:col-span-7">
                    <h2 id="brief-title" class="sr-only">About the seminar</h2>
                    <p class="font-display text-display-sm text-ink-900" style="font-stretch: 92%; font-weight: 500">
                        {{ event.description }}
                    </p>
                </div>
                <dl class="ledger border-y border-rule text-sm lg:col-span-3 lg:col-start-10 lg:self-start">
                    <div v-if="minutesLong" class="flex justify-between gap-4 py-3">
                        <dt class="text-ink-500">Length</dt>
                        <dd class="tnum font-medium">{{ minutesLong }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-ink-500">Format</dt>
                        <dd class="text-right font-medium">{{ onlineOffered ? 'Online & in person' : 'In person' }}</dd>
                    </div>
                    <div v-if="event.speakers.length" class="flex justify-between gap-4 py-3">
                        <dt class="text-ink-500">Speakers</dt>
                        <dd class="tnum font-medium">{{ event.speakers.length }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-ink-500">Programme</dt>
                        <dd class="text-right font-medium">On your dashboard once registered</dd>
                    </div>
                </dl>
            </section>

            <!-- ─── Speakers ───────────────────────────────────────────── -->
            <section
                v-if="event.speakers.length"
                id="speakers"
                class="shell scroll-mt-4 py-20 sm:py-24 lg:py-32"
                aria-labelledby="speakers-title"
            >
                <div class="grid gap-y-3 lg:grid-cols-12 lg:items-end">
                    <p class="section-index lg:col-span-2 lg:self-start lg:pt-3">{{ indexOf('speakers') }} <span class="text-ink-400">/</span> Speakers</p>
                    <h2 id="speakers-title" class="display text-display-md lg:col-span-10">On stage</h2>
                </div>

                <div class="mt-14 space-y-16 lg:mt-20 lg:space-y-24">
                    <article
                        v-for="(speaker, i) in event.speakers"
                        :key="speaker.name"
                        class="grid items-end gap-6 sm:grid-cols-12 sm:gap-8"
                    >
                        <div
                            class="sm:col-span-5 lg:col-span-4"
                            :class="i % 2 ? 'sm:order-2 sm:col-start-8 lg:col-start-9' : 'lg:col-start-3'"
                        >
                            <div class="relative aspect-[4/5] w-3/5 max-w-sm overflow-hidden bg-ink-900 sm:w-full">
                                <img
                                    v-if="speaker.photo_url"
                                    :src="speaker.photo_url"
                                    :alt="speaker.photo_alt || speaker.name"
                                    loading="lazy"
                                    class="absolute inset-0 h-full w-full object-cover grayscale-[20%]"
                                />
                                <div v-else class="absolute inset-0 flex items-end justify-between p-6" aria-hidden="true">
                                    <span class="ghost-text font-display text-[7rem] leading-[0.8] text-white/[0.14]" style="font-stretch: 70%; font-weight: 800" :data-ghost="initials(speaker.name)"></span>
                                    <span class="tnum text-meta text-white/60">{{ String(i + 1).padStart(2, '0') }}</span>
                                </div>
                            </div>
                        </div>
                        <div
                            class="sm:col-span-7 lg:col-span-5"
                            :class="i % 2 ? 'sm:order-1 lg:col-start-3' : ''"
                        >
                            <p class="section-index">{{ String(i + 1).padStart(2, '0') }}</p>
                            <h3 class="mt-3 text-display-sm text-ink-900">{{ speaker.name }}</h3>
                            <p class="mt-2 text-sm font-medium text-ink-600">
                                {{ speaker.title }}<template v-if="speaker.organisation"><span aria-hidden="true" class="mx-2 text-ink-400">/</span>{{ speaker.organisation }}</template>
                            </p>
                            <p v-if="speaker.bio" class="mt-5 max-w-prose text-[0.9375rem] leading-relaxed text-ink-600">{{ speaker.bio }}</p>
                        </div>
                    </article>
                </div>
            </section>

            <!-- ─── Partners ───────────────────────────────────────────── -->
            <section
                v-if="(event.partners ?? []).length"
                id="partners"
                class="shell scroll-mt-4 border-t border-rule py-20 sm:py-24"
                aria-labelledby="partners-title"
            >
                <div class="grid gap-y-3 lg:grid-cols-12 lg:items-end">
                    <p class="section-index lg:col-span-2 lg:self-start lg:pt-3">{{ indexOf('partners') }} <span class="text-ink-400">/</span> Partners</p>
                    <h2 id="partners-title" class="display text-display-md lg:col-span-10">With the support of</h2>
                </div>
                <div class="mt-12 lg:ml-[16.6667%]">
                    <PartnerStrip :groups="event.partners" />
                </div>
            </section>

            <!-- ─── Attend ─────────────────────────────────────────────── -->
            <section
                v-if="!event.has_ended"
                id="attend"
                class="scroll-mt-4 border-t border-rule py-20 sm:py-24 lg:py-32"
                aria-labelledby="attend-title"
            >
                <div class="shell">
                    <div class="grid gap-y-3 lg:grid-cols-12 lg:items-end">
                        <p class="section-index lg:col-span-2 lg:self-start lg:pt-3">{{ indexOf('attend') }} <span class="text-ink-400">/</span> Attend</p>
                        <h2 id="attend-title" class="display text-display-md lg:col-span-10">{{ onlineOffered ? 'Two ways to take part' : 'Join us in person' }}</h2>
                    </div>

                    <div class="mt-12 grid gap-10 lg:mt-16 lg:grid-cols-12 lg:grid-rows-[auto_auto_auto_auto] lg:gap-x-6 lg:gap-y-0">
                        <!-- Row labels (desktop only; each column repeats them for phones). -->
                        <div class="hidden lg:col-span-2 lg:row-span-4 lg:grid lg:grid-rows-subgrid" aria-hidden="true">
                            <span></span>
                            <span class="eyebrow border-t border-rule py-4 text-ink-400">Where</span>
                            <span class="eyebrow border-t border-rule py-4 text-ink-400">Payment</span>
                            <span class="eyebrow border-t border-rule py-4 text-ink-400">Your access</span>
                        </div>

                        <section v-if="onlineOffered" class="lg:col-span-4 lg:row-span-4 lg:grid lg:grid-rows-subgrid" aria-labelledby="mode-online">
                            <header class="border-t-2 border-ink-900 pb-6 pt-5">
                                <h3 id="mode-online" class="text-xl text-ink-900">Online</h3>
                                <p class="tnum mt-3 font-display text-display-md text-ink-900" style="font-stretch: 80%">{{ onlinePrice }}</p>
                            </header>
                            <dl class="contents">
                                <div class="border-t border-rule py-4">
                                    <dt class="eyebrow text-ink-400 lg:sr-only">Where</dt>
                                    <dd class="mt-1 text-[0.9375rem] lg:mt-0">Wherever you are, through this platform</dd>
                                </div>
                                <div class="border-t border-rule py-4">
                                    <dt class="eyebrow text-ink-400 lg:sr-only">Payment</dt>
                                    <dd class="mt-1 text-[0.9375rem] lg:mt-0">None</dd>
                                </div>
                                <div class="border-t border-rule py-4">
                                    <dt class="eyebrow text-ink-400 lg:sr-only">Your access</dt>
                                    <dd class="mt-1 text-[0.9375rem] lg:mt-0">Join the live session from your dashboard</dd>
                                </div>
                            </dl>
                        </section>

                        <section
                            :class="['lg:row-span-4 lg:grid lg:grid-rows-subgrid', onlineOffered ? 'lg:col-span-5 lg:col-start-8' : 'lg:col-span-7 lg:col-start-3']"
                            aria-labelledby="mode-in-person"
                        >
                            <header class="border-t-2 border-ink-900 pb-6 pt-5">
                                <h3 id="mode-in-person" class="text-xl text-ink-900">In person</h3>
                                <p class="tnum mt-3 font-display text-display-md text-ink-900" style="font-stretch: 80%">{{ inPersonPrice }}</p>
                            </header>
                            <dl class="contents">
                                <div class="border-t border-rule py-4">
                                    <dt class="eyebrow text-ink-400 lg:sr-only">Where</dt>
                                    <dd class="mt-1 text-[0.9375rem] lg:mt-0">
                                        {{ event.venue_name }}
                                        <span v-if="event.venue_address" class="block text-meta text-ink-500">{{ event.venue_address }}</span>
                                    </dd>
                                </div>
                                <div class="border-t border-rule py-4">
                                    <dt class="eyebrow text-ink-400 lg:sr-only">Payment</dt>
                                    <dd class="mt-1 text-[0.9375rem] lg:mt-0">EFT, then upload your proof of payment</dd>
                                </div>
                                <div class="border-t border-rule py-4">
                                    <dt class="eyebrow text-ink-400 lg:sr-only">Your access</dt>
                                    <dd class="mt-1 text-[0.9375rem] lg:mt-0">A QR ticket, issued once your payment is approved</dd>
                                </div>
                            </dl>
                        </section>
                    </div>

                    <p v-if="!onlineOffered" class="mt-8 max-w-prose text-[0.9375rem] text-ink-600 lg:ml-[16.6667%]">
                        Can't be there? The session is recorded, and the recording will be shared after the event.
                    </p>

                    <div class="mt-12 grid gap-8 border-t border-ink-900 pt-8 lg:grid-cols-12 lg:gap-6">
                        <div class="lg:col-span-6 lg:col-start-3">
                            <p class="eyebrow text-ink-400">In person, from registration to ticket</p>
                            <JourneyRail :stages="route_explainer" label="How in-person registration works" class="mt-4" />
                        </div>
                        <div class="lg:col-span-4 lg:col-start-9 lg:justify-self-end lg:self-end">
                            <Button :href="route('event.register')" size="lg" arrow class="w-full sm:w-auto">
                                Register to attend
                            </Button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ─── Venue ──────────────────────────────────────────────── -->
            <section
                v-if="event.venue_name"
                id="venue"
                class="relative scroll-mt-4 bg-ink-900"
                aria-labelledby="venue-title"
            >
                <StagePhoto
                    :src="event.venue_image_url"
                    :alt="event.venue_image_alt || event.venue_name || ''"
                    class="aspect-[4/3] w-full sm:aspect-[21/9] lg:min-h-[32rem]"
                >
                    <template #fallback>
                        <div class="h-full bg-ink-800">
                            <div class="shell flex h-full items-center">
                                <p
                                    class="ghost-text font-display text-[clamp(3rem,12vw,11rem)] leading-[0.85] text-white/[0.08]"
                                    style="font-stretch: 68%; font-weight: 800"
                                    aria-hidden="true"
                                    :data-ghost="event.venue_address?.split(',').pop()?.trim() || event.venue_name"
                                ></p>
                            </div>
                        </div>
                    </template>
                </StagePhoto>

                <div class="lg:absolute lg:inset-x-0 lg:bottom-0">
                    <div class="shell lg:pb-10">
                        <div class="bg-white px-5 py-7 sm:px-8 lg:max-w-md">
                            <p class="section-index">{{ indexOf('venue') }} <span class="text-ink-400">/</span> Venue</p>
                            <h2 id="venue-title" class="mt-3 text-display-sm text-ink-900">{{ event.venue_name }}</h2>
                            <p v-if="event.venue_address" class="mt-2 text-ink-600">{{ event.venue_address }}</p>
                            <p class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                                <span class="tnum text-ink-500">{{ longDate(event.starts_at) }}, {{ clock(event.starts_at) }}</span>
                                <a v-if="directions" :href="directions" class="link" target="_blank" rel="noopener">
                                    Get directions<span class="sr-only"> (opens Google Maps)</span>
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ─── The series: last, after everything about this event.
                 Its follow call also serves as the post-event "stay in touch". -->
            <SeriesBrief v-if="series" :series="series" :index="indexOf('series')" />
        </template>

        <template v-else>
            <section class="stage">
                <div class="shell py-24 sm:py-32">
                    <p class="eyebrow text-white/60">Lumko Seminars</p>
                    <h1 class="display mt-5 max-w-[14ch] text-display-md">The next seminar is being prepared</h1>
                    <p class="mt-5 max-w-prose text-white/70">No upcoming event is published right now &mdash; check back soon.</p>
                </div>
            </section>

            <!-- Between events the series is the whole story. -->
            <SeriesBrief v-if="series" :series="series" index="01" />
        </template>

        <!-- Closing call, set in the footer's navy field. -->
        <template v-if="event && !event.has_ended" #close>
            <div class="shell grid gap-8 py-16 sm:py-20 lg:grid-cols-12 lg:items-end">
                <p class="display text-display-md lg:col-span-8">
                    <span class="tnum">{{ date.weekday }} {{ date.day }} {{ date.month }}</span>,
                    <span class="tnum text-signal-300">{{ clock(event.starts_at) }}</span>.
                    <span class="block text-white/60">{{ event.venue_name }}</span>
                </p>
                <div class="lg:col-span-4 lg:justify-self-end">
                    <Button :href="route('event.register')" variant="inverse" size="lg" arrow class="w-full sm:w-auto">
                        Reserve your place
                    </Button>
                </div>
            </div>
        </template>
    </PublicLayout>
</template>
