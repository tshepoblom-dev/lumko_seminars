<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    stats: Object,
    warnings: { type: Object, default: () => ({}) },
    events: { type: Array, default: () => [] },
    selectedEventId: Number,
    eventStats: Object,
});

function selectEvent(eventId) {
    router.get(route('admin.dashboard'), { event: eventId }, { preserveState: true, preserveScroll: true });
}

const sparklinePoints = computed(() => {
    const rows = props.eventStats?.registrations_over_time ?? [];
    if (!rows.length) return '';
    const max = Math.max(...rows.map((r) => r.count), 1);
    const width = 300;
    const height = 60;
    const step = rows.length > 1 ? width / (rows.length - 1) : 0;

    return rows.map((r, i) => `${i * step},${height - (r.count / max) * height}`).join(' ');
});
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Dashboard</h2>
        </template>

        <Head title="Admin Dashboard" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div
                    v-if="warnings.payment_details_missing"
                    role="alert"
                    class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
                >
                    Bank details are not configured. In-person attendees cannot be told where to pay until
                    the <code>SEMINAR_BANK_*</code> environment variables are set.
                </div>
                <div
                    v-if="warnings.stalled_jobs"
                    role="alert"
                    class="mb-4 rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-900"
                >
                    {{ warnings.stalled_jobs }} email{{ warnings.stalled_jobs === 1 ? ' is' : 's are' }} waiting to be sent for over 10 minutes.
                    The queue worker is probably not running, so registration and "set your password" emails aren't going out.
                    See docs/deployment.md (Queue worker).
                </div>
                <div
                    v-if="warnings.failed_jobs"
                    role="alert"
                    class="mb-4 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
                >
                    {{ warnings.failed_jobs }} background job{{ warnings.failed_jobs === 1 ? '' : 's' }} failed (often an email the mail server refused).
                    Check <code>php artisan queue:failed</code> and the log.
                </div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-white p-6 shadow">
                        <p class="text-sm text-gray-500">Events</p>
                        <p class="mt-1 text-3xl font-bold">{{ stats.events }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-6 shadow">
                        <p class="text-sm text-gray-500">Published events</p>
                        <p class="mt-1 text-3xl font-bold">{{ stats.published_events }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-6 shadow">
                        <p class="text-sm text-gray-500">Speakers</p>
                        <p class="mt-1 text-3xl font-bold">{{ stats.speakers }}</p>
                    </div>
                </div>

                <div class="mt-8 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800">Event overview</h3>
                    <div v-if="events.length" class="flex items-center gap-3">
                    <Link
                        v-if="selectedEventId"
                        :href="route('admin.events.show', selectedEventId)"
                        class="text-sm font-medium text-brand hover:underline"
                    >
                        Open event workspace
                    </Link>
                    <select
                        aria-label="Select event"
                        :value="selectedEventId"
                        class="rounded border-gray-300 text-sm"
                        @change="selectEvent($event.target.value)"
                    >
                        <option v-for="event in events" :key="event.id" :value="event.id">{{ event.name }}</option>
                    </select>
                    </div>
                </div>

                <p v-if="!eventStats" class="mt-2 text-sm text-gray-500">No event to show data for.</p>

                <template v-else>
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Registered</p><p class="text-2xl font-bold">{{ eventStats.registered_total }}</p></div>
                        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Online</p><p class="text-2xl font-bold">{{ eventStats.registered_online }}</p></div>
                        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Physical</p><p class="text-2xl font-bold">{{ eventStats.registered_in_person }}</p></div>
                        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Paid</p><p class="text-2xl font-bold">{{ eventStats.payment_totals.approved }}</p><p v-if="eventStats.payment_totals.complimentary" class="text-xs text-gray-500">+ {{ eventStats.payment_totals.complimentary }} complimentary</p></div>
                        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Pending payment</p><p class="text-2xl font-bold">{{ eventStats.payment_totals.pending + eventStats.payment_totals.under_review }}</p></div>
                        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Checked in</p><p class="text-2xl font-bold">{{ eventStats.checked_in }}</p></div>
                        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Questions</p><p class="text-2xl font-bold">{{ eventStats.questions_submitted }}</p></div>
                        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Poll participation</p><p class="text-2xl font-bold">{{ eventStats.poll_responses }}</p></div>
                        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Networking opt-in</p><p class="text-2xl font-bold">{{ eventStats.networking_opt_ins }}</p></div>
                        <div class="rounded-lg bg-white p-4 shadow"><p class="text-xs text-gray-500">Lumko opt-in</p><p class="text-2xl font-bold">{{ eventStats.lumko_marketing_opt_ins }}</p></div>
                    </div>

                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        <div class="rounded-lg bg-white p-6 shadow">
                            <h4 class="font-semibold text-gray-800">Registrations over time (30 days)</h4>
                            <svg v-if="sparklinePoints" viewBox="0 0 300 60" class="mt-3 h-16 w-full">
                                <polyline :points="sparklinePoints" fill="none" class="stroke-brand" stroke-width="2" />
                            </svg>
                            <p v-else class="mt-3 text-sm text-gray-500">No registrations in the last 30 days.</p>
                        </div>

                        <div class="rounded-lg bg-white p-6 shadow">
                            <h4 class="font-semibold text-gray-800">Payment totals</h4>
                            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                                <div><dt class="text-gray-500">Pending</dt><dd class="font-medium">{{ eventStats.payment_totals.pending }}</dd></div>
                                <div><dt class="text-gray-500">Under review</dt><dd class="font-medium">{{ eventStats.payment_totals.under_review }}</dd></div>
                                <div><dt class="text-gray-500">Approved</dt><dd class="font-medium">{{ eventStats.payment_totals.approved }}</dd></div>
                                <div><dt class="text-gray-500">Rejected</dt><dd class="font-medium">{{ eventStats.payment_totals.rejected }}</dd></div>
                                <div><dt class="text-gray-500">Complimentary</dt><dd class="font-medium">{{ eventStats.payment_totals.complimentary }}</dd></div>
                            </dl>
                            <p class="mt-3 text-sm text-gray-500">
                                Total verified: <span class="font-semibold text-gray-800">R{{ eventStats.payment_totals.verified_amount.toFixed(0) }}</span>
                            </p>
                        </div>

                        <div class="rounded-lg bg-white p-6 shadow">
                            <h4 class="font-semibold text-gray-800">Top organisations</h4>
                            <ul class="mt-3 space-y-1 text-sm">
                                <li v-for="row in eventStats.by_organisation" :key="row.label" class="flex justify-between">
                                    <span>{{ row.label }}</span><span class="text-gray-500">{{ row.count }}</span>
                                </li>
                                <li v-if="!eventStats.by_organisation.length" class="text-gray-500">No data yet.</li>
                            </ul>
                        </div>

                        <div class="rounded-lg bg-white p-6 shadow">
                            <h4 class="font-semibold text-gray-800">Top industries</h4>
                            <ul class="mt-3 space-y-1 text-sm">
                                <li v-for="row in eventStats.by_industry" :key="row.label" class="flex justify-between">
                                    <span>{{ row.label }}</span><span class="text-gray-500">{{ row.count }}</span>
                                </li>
                                <li v-if="!eventStats.by_industry.length" class="text-gray-500">No data yet.</li>
                            </ul>
                        </div>

                        <div class="rounded-lg bg-white p-6 shadow">
                            <h4 class="font-semibold text-gray-800">Top job titles</h4>
                            <ul class="mt-3 space-y-1 text-sm">
                                <li v-for="row in eventStats.by_job_title" :key="row.label" class="flex justify-between">
                                    <span>{{ row.label }}</span><span class="text-gray-500">{{ row.count }}</span>
                                </li>
                                <li v-if="!eventStats.by_job_title.length" class="text-gray-500">No data yet.</li>
                            </ul>
                        </div>

                        <div class="rounded-lg bg-white p-6 shadow">
                            <h4 class="font-semibold text-gray-800">Top interests</h4>
                            <ul class="mt-3 space-y-1 text-sm">
                                <li v-for="row in eventStats.by_interest" :key="row.label" class="flex justify-between">
                                    <span>{{ row.label }}</span><span class="text-gray-500">{{ row.count }}</span>
                                </li>
                                <li v-if="!eventStats.by_interest.length" class="text-gray-500">No data yet.</li>
                            </ul>
                        </div>

                        <div class="rounded-lg bg-white p-6 shadow">
                            <h4 class="font-semibold text-gray-800">Top improvement areas</h4>
                            <ul class="mt-3 space-y-1 text-sm">
                                <li v-for="row in eventStats.by_improvement_area" :key="row.label" class="flex justify-between">
                                    <span>{{ row.label }}</span><span class="text-gray-500">{{ row.count }}</span>
                                </li>
                                <li v-if="!eventStats.by_improvement_area.length" class="text-gray-500">No data yet.</li>
                            </ul>
                        </div>

                        <!-- The event's own choice and yes/no questions. -->
                        <div v-for="q in eventStats.by_question ?? []" :key="q.question" class="rounded-lg bg-white p-6 shadow">
                            <h4 class="font-semibold text-gray-800">{{ q.question }}</h4>
                            <ul class="mt-3 space-y-1 text-sm">
                                <li v-for="row in q.rows" :key="row.label" class="flex justify-between gap-3">
                                    <span>{{ row.label }}</span><span class="text-gray-500">{{ row.count }}</span>
                                </li>
                                <li v-if="!q.rows.length" class="text-gray-500">No answers yet.</li>
                            </ul>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </AdminLayout>
</template>
