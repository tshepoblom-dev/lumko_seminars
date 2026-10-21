<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Badge from '@/Components/Badge.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { categoryLabel, formatBytes, formatDateTime, modeLabel, recipientStatus, sendStatus } from '@/lib/communications';

const props = defineProps({
    isPlatform: { type: Boolean, default: false },
    indexUrl: { type: String, required: true },
    communication: { type: Object, required: true },
    recipients: { type: Object, required: true },
});

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    { key: 'status', label: 'Status' },
    { key: 'sent_at', label: 'Sent' },
];

const status = computed(() => sendStatus[props.communication.status] ?? { label: props.communication.status, variant: 'neutral' });
const queued = computed(() =>
    props.communication.recipient_count
    - props.communication.sent_count
    - props.communication.failed_count
    - props.communication.skipped_count,
);

const filterSummary = computed(() => {
    const filters = props.communication.filters ?? {};
    if (props.communication.recipient_mode === 'selected') return `${filters.user_ids?.length ?? 0} chosen`;
    return Object.entries(filters)
        .map(([key, value]) => `${key.replaceAll('_', ' ')}: ${value === true ? 'yes' : value}`)
        .join(' · ');
});

// While it's still going out, keep the counts current.
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (props.communication.status !== 'sending' || document.visibilityState !== 'visible') return;
        router.reload({ only: ['communication', 'recipients'] });
    }, 10000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ communication.subject }}</h2>
        </template>

        <Head :title="communication.subject" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <p class="text-sm"><Link :href="indexUrl" class="link">All emails</Link></p>

                <section aria-label="Delivery" class="grid gap-px border border-rule bg-rule sm:grid-cols-4">
                    <div class="bg-white p-4">
                        <p class="text-meta text-ink-500">Status</p>
                        <p class="mt-1"><Badge :variant="status.variant">{{ status.label }}</Badge></p>
                        <p v-if="queued > 0" class="mt-1 text-meta text-ink-500">{{ queued }} still queued</p>
                    </div>
                    <div class="bg-white p-4">
                        <p class="text-meta text-ink-500">Sent</p>
                        <p class="mt-1 font-display text-2xl tabular-nums text-ink-900">{{ communication.sent_count }}<span class="text-base text-ink-500"> / {{ communication.recipient_count }}</span></p>
                    </div>
                    <div class="bg-white p-4">
                        <p class="text-meta text-ink-500">Failed</p>
                        <p class="mt-1 font-display text-2xl tabular-nums text-ink-900">{{ communication.failed_count }}</p>
                    </div>
                    <div class="bg-white p-4">
                        <p class="text-meta text-ink-500">Skipped (no consent)</p>
                        <p class="mt-1 font-display text-2xl tabular-nums text-ink-900">{{ communication.skipped_count }}</p>
                    </div>
                </section>

                <section aria-labelledby="message-heading" class="border border-rule bg-white p-5">
                    <h3 id="message-heading" class="font-display text-xl text-ink-900">Message</h3>
                    <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
                        <dt class="text-ink-500">Type</dt>
                        <dd class="text-ink-900">{{ categoryLabel[communication.category] }}</dd>
                        <dt class="text-ink-500">Recipients</dt>
                        <dd class="text-ink-900">
                            {{ modeLabel[communication.recipient_mode] }}<template v-if="filterSummary"> ({{ filterSummary }})</template>
                        </dd>
                        <dt class="text-ink-500">Sent</dt>
                        <dd class="text-ink-900">{{ formatDateTime(communication.created_at) }}<template v-if="communication.sender"> by {{ communication.sender }}</template></dd>
                        <template v-if="communication.attachments.length">
                            <dt class="text-ink-500">Attachments</dt>
                            <dd>
                                <ul class="space-y-1">
                                    <li v-for="a in communication.attachments" :key="a.id">
                                        <a :href="a.url" class="link">{{ a.name }}</a>
                                        <span class="text-meta text-ink-500"> · {{ formatBytes(a.size) }}</span>
                                    </li>
                                </ul>
                            </dd>
                        </template>
                    </dl>
                    <div class="mt-4 whitespace-pre-line border-t border-rule pt-4 text-sm text-ink-800">{{ communication.body }}</div>
                </section>

                <section aria-labelledby="recipients-heading" class="space-y-3">
                    <h3 id="recipients-heading" class="font-display text-xl text-ink-900">Recipients</h3>
                    <DataTable :columns="columns" :rows="recipients.data">
                        <template #cell-name="{ row }">{{ row.name ?? '(account deleted)' }}</template>
                        <template #cell-status="{ row }">
                            <Badge :variant="recipientStatus[row.status]?.variant ?? 'neutral'">{{ recipientStatus[row.status]?.label ?? row.status }}</Badge>
                            <p v-if="row.error" class="mt-1 max-w-xs text-meta text-ink-500">{{ row.error }}</p>
                        </template>
                        <template #cell-sent_at="{ row }">{{ formatDateTime(row.sent_at) || '—' }}</template>
                    </DataTable>

                    <div v-if="recipients.links.length > 3" class="flex flex-wrap gap-1">
                        <Link
                            v-for="(link, i) in recipients.links"
                            :key="i"
                            :href="link.url ?? '#'"
                            preserve-scroll
                            :class="[
                                'rounded px-3 py-1 text-sm',
                                link.active ? 'bg-brand text-white' : 'bg-white text-gray-700 hover:bg-gray-100',
                                !link.url ? 'pointer-events-none opacity-50' : '',
                            ]"
                        ><span v-html="link.label" /></Link>
                    </div>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
