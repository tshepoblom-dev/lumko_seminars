<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import Button from '@/Components/Button.vue';
import TimeRuler from '@/Components/TimeRuler.vue';
import { dateParts, longDate, timeRange } from '@/lib/eventFormat';

const props = defineProps({
    event: Object,
    attendee: { type: Object, default: null },
    admission: { type: String, default: 'In person' },
    confirmed: Boolean,
    qrSvg: String,
    code: String,
});

const date = computed(() => (props.event.starts_at ? dateParts(props.event.starts_at) : null));

function print() {
    window.print();
}
</script>

<template>
    <AttendeeLayout>
    <Head title="Your ticket" />

    <div class="bg-paper">
        <div class="shell pb-20 pt-10 sm:pt-14">
            <div class="no-print flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="eyebrow text-ink-400">{{ event.name }}</p>
                    <h1 class="display mt-2 text-display-sm text-ink-900">{{ confirmed ? 'Your ticket' : 'Your ticket is on its way' }}</h1>
                </div>
                <div v-if="confirmed" class="flex flex-wrap gap-2">
                    <!-- The same ticket as an A4 PDF, also emailed when it was confirmed. -->
                    <Button :href="route('ticket.pdf')" native variant="secondary" size="sm">Download PDF</Button>
                    <Button variant="secondary" size="sm" @click="print">Print ticket</Button>
                </div>
            </div>

            <!-- The credential. Horizontal with a tear-off stub on wide screens, vertical on phones. -->
            <article
                class="print-flat relative mt-8 grid bg-white shadow-lift md:grid-cols-[1fr_auto]"
                :aria-label="`Ticket for ${event.name}`"
            >
                <!-- Main panel on the navy stage -->
                <div class="stage relative overflow-hidden px-6 py-7 sm:px-9 sm:py-9">
                    <div class="flex items-start justify-between gap-6">
                        <img src="/images/logo-light.svg" width="3141" height="854" alt="Lumko" class="h-6 w-auto" />
                        <p v-if="date" class="tnum text-right font-display leading-none" style="font-stretch: 72%; font-weight: 800">
                            <span class="block text-5xl sm:text-6xl">{{ date.day }}</span>
                            <span class="eyebrow mt-1 block text-white/60">{{ date.month }} {{ date.year }}</span>
                        </p>
                    </div>

                    <p class="display mt-8 max-w-[14ch] text-display-sm text-white sm:mt-10">{{ event.name }}</p>

                    <dl class="mt-8 grid grid-cols-2 gap-x-6 gap-y-5 border-t border-white/15 pt-6 text-sm sm:grid-cols-3">
                        <div v-if="attendee" class="col-span-2 sm:col-span-3">
                            <dt class="eyebrow text-white/60">Attendee</dt>
                            <dd class="mt-1 font-display text-2xl text-white" style="font-stretch: 88%; font-weight: 700">{{ attendee.name }}</dd>
                            <dd v-if="attendee.organisation" class="text-meta text-white/65">{{ attendee.organisation }}</dd>
                        </div>
                        <div>
                            <dt class="eyebrow text-white/60">Admission</dt>
                            <dd class="mt-1 font-medium">{{ admission }}</dd>
                        </div>
                        <div v-if="event.starts_at">
                            <dt class="eyebrow text-white/60">Time</dt>
                            <dd class="tnum mt-1 font-medium">{{ timeRange(event.starts_at, event.ends_at) }}</dd>
                        </div>
                        <div v-if="event.venue_name" class="col-span-2 sm:col-span-1">
                            <dt class="eyebrow text-white/60">Venue</dt>
                            <dd class="mt-1 font-medium">{{ event.venue_name }}</dd>
                            <dd v-if="event.venue_address" class="text-meta text-white/65">{{ event.venue_address }}</dd>
                        </div>
                    </dl>

                    <TimeRuler
                        v-if="event.programme?.length"
                        :sessions="event.programme"
                        size="compact"
                        on-stage
                        decorative
                        class="mt-8"
                    />
                </div>

                <!-- Perforation: notches and a dashed tear line between panel and stub. -->
                <div class="relative md:w-72">
                    <span aria-hidden="true" class="absolute left-0 right-0 top-0 border-t-2 border-dashed border-rule-strong md:bottom-0 md:right-auto md:border-l-2 md:border-t-0"></span>
                    <span aria-hidden="true" class="absolute -left-3 top-0 h-6 w-6 -translate-y-1/2 rounded-full bg-paper md:-top-3 md:translate-y-0"></span>
                    <span aria-hidden="true" class="absolute -right-3 top-0 h-6 w-6 -translate-y-1/2 rounded-full bg-paper md:-bottom-3 md:-left-3 md:right-auto md:top-auto md:translate-y-0"></span>

                    <!-- Stub: the part that is scanned at the door. -->
                    <div v-if="confirmed" class="flex h-full flex-col items-center justify-center px-6 pb-8 pt-10 text-center md:px-8 md:pt-8">
                        <div
                            class="w-56 bg-white [&>svg]:h-auto [&>svg]:w-full"
                            role="img"
                            :aria-label="`Check-in QR code for ${event.name}`"
                            data-testid="ticket-qr"
                            v-html="qrSvg"
                        ></div>
                        <p class="mt-4 text-meta text-ink-500">Present this QR code at check-in.</p>
                        <div class="mt-5 w-full border-t border-rule pt-4">
                            <p class="text-meta text-ink-500">If it won't scan, give the door staff this code:</p>
                            <strong
                                class="tnum mt-1.5 block break-all font-display text-2xl tracking-[0.18em] text-ink-900"
                                style="font-stretch: 100%; font-weight: 700"
                                data-testid="ticket-code"
                            >{{ code }}</strong>
                        </div>
                    </div>

                    <div v-else class="flex h-full flex-col justify-center px-6 pb-8 pt-10 md:px-8 md:pt-8">
                        <p class="flex items-center gap-2 text-meta font-medium text-ink-800">
                            <span aria-hidden="true" class="h-2 w-2 rounded-full bg-amber-500"></span>
                            Awaiting verification
                        </p>
                        <p class="mt-3 text-[0.9375rem] text-ink-800">
                            Your ticket will appear here once your payment has been approved.
                        </p>
                        <Link :href="route('payment.show')" class="link mt-6 text-sm">Check your payment</Link>
                    </div>
                </div>
            </article>

            <p v-if="confirmed && event.starts_at" class="no-print mt-6 text-meta text-ink-500">
                The seminar runs {{ timeRange(event.starts_at, event.ends_at) }} on {{ longDate(event.starts_at) }}. Keep this page handy on the day, or print it.
            </p>
        </div>
    </div>
    </AttendeeLayout>
</template>
