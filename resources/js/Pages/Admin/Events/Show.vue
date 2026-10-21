<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    event: Object,
    stats: Object,
    attention: Object,
});

const eventRoute = useEventRoute();

const when = computed(() => {
    if (!props.event.starts_at) return '';
    const opts = { timeZone: 'Africa/Johannesburg' };
    const start = new Date(props.event.starts_at);
    const date = start.toLocaleDateString('en-ZA', { ...opts, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const t = (d) => d.toLocaleTimeString('en-ZA', { ...opts, hour: '2-digit', minute: '2-digit' });
    return props.event.ends_at ? `${date}, ${t(start)}–${t(new Date(props.event.ends_at))}` : `${date}, ${t(start)}`;
});

const tiles = computed(() => [
    { label: 'Registered', value: props.stats.registered_total, href: eventRoute('admin.attendees.index') },
    { label: 'Online', value: props.stats.registered_online, href: eventRoute('admin.attendees.index') + '?mode=online' },
    { label: 'In person', value: props.stats.registered_in_person, href: eventRoute('admin.attendees.index') + '?mode=in_person' },
    {
        label: 'Paid',
        value: props.stats.payments_approved,
        note: props.stats.payments_complimentary ? `+ ${props.stats.payments_complimentary} complimentary` : null,
        href: eventRoute('admin.payments.index'),
    },
    { label: 'Checked in', value: props.stats.checked_in, href: eventRoute('admin.check-in.index') },
    { label: 'Sessions', value: props.stats.sessions, href: eventRoute('admin.programme-sessions.index') },
    { label: 'Speakers', value: props.stats.speakers, href: eventRoute('admin.events.speakers.index') },
    {
        label: 'Feedback',
        value: props.stats.feedback_count,
        note: props.stats.feedback_average_rating ? `avg ${props.stats.feedback_average_rating}/5` : null,
        href: eventRoute('admin.feedback.index'),
    },
]);

// Only what needs someone to act, and only when there is something.
const todo = computed(() =>
    [
        {
            count: props.attention.payments_to_review,
            text: (n) => `${n} proof${n === 1 ? '' : 's'} of payment waiting for review`,
            href: eventRoute('admin.payments.index'),
        },
        {
            count: props.attention.accounts_not_set_up,
            text: (n) => `${n} registrant${n === 1 ? " hasn't" : "s haven't"} set up their account yet`,
            href: eventRoute('admin.attendees.index'),
        },
        {
            count: props.attention.sessions_without_speakers,
            text: (n) => `${n} session${n === 1 ? ' has' : 's have'} no speaker named`,
            href: eventRoute('admin.programme-sessions.index'),
        },
        {
            count: props.attention.questions_pending,
            text: (n) => `${n} audience question${n === 1 ? '' : 's'} to moderate`,
            href: eventRoute('admin.live.questions.index'),
        },
    ].filter((item) => item.count > 0),
);
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Overview</h2>
        </template>

        <Head :title="event.name" />

        <div class="space-y-6 py-6">
            <section class="rounded-lg bg-white p-6 shadow">
                <p v-if="event.subtitle" class="text-gray-700">{{ event.subtitle }}</p>
                <p class="mt-1 text-sm text-gray-600">
                    {{ when }}<template v-if="event.venue_name"> · {{ event.venue_name }}</template>
                </p>
                <div class="mt-4 flex flex-wrap gap-3 text-sm">
                    <Link :href="eventRoute('admin.events.edit')" class="text-brand underline">Edit event settings</Link>
                    <Link :href="eventRoute('admin.live.control-room')" class="text-brand underline">Open control room</Link>
                </div>
            </section>

            <section aria-labelledby="overview-attention">
                <h3 id="overview-attention" class="mb-2 text-base font-semibold text-gray-800">Needs attention</h3>
                <ul v-if="todo.length" class="divide-y divide-gray-100 rounded-lg bg-white shadow">
                    <li v-for="item in todo" :key="item.href + item.count">
                        <Link :href="item.href" class="flex items-center justify-between gap-4 px-4 py-3 text-sm text-gray-800 hover:bg-gray-50">
                            <span>{{ item.text(item.count) }}</span>
                            <span aria-hidden="true" class="text-gray-500">→</span>
                        </Link>
                    </li>
                </ul>
                <p v-else class="rounded-lg bg-white px-4 py-3 text-sm text-gray-600 shadow">Nothing is waiting on you.</p>
            </section>

            <section aria-labelledby="overview-numbers">
                <h3 id="overview-numbers" class="mb-2 text-base font-semibold text-gray-800">At a glance</h3>
                <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <li v-for="tile in tiles" :key="tile.label">
                        <Link :href="tile.href" class="block rounded-lg bg-white p-4 shadow hover:ring-2 hover:ring-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                            <span class="block text-xs text-gray-600">{{ tile.label }}</span>
                            <span class="tnum block text-2xl font-bold text-gray-900">{{ tile.value }}</span>
                            <span v-if="tile.note" class="block text-xs text-gray-600">{{ tile.note }}</span>
                        </Link>
                    </li>
                </ul>
                <p class="mt-3 text-sm text-gray-600">
                    Verified payments: <span class="font-semibold text-gray-900">R{{ Number(stats.verified_amount).toFixed(0) }}</span>.
                    <Link :href="eventRoute('admin.engagement.show')" class="text-brand underline">Full engagement report</Link>
                </p>
            </section>
        </div>
    </AdminLayout>
</template>
