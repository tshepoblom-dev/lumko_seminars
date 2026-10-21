<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { Head, Link } from '@inertiajs/vue3';
import { categoryLabel, formatDateTime, modeLabel, sendStatus } from '@/lib/communications';

defineProps({
    isPlatform: { type: Boolean, default: false },
    createUrl: { type: String, required: true },
    communications: { type: Object, required: true },
});

const columns = [
    { key: 'subject', label: 'Subject' },
    { key: 'audience', label: 'Audience' },
    { key: 'delivery', label: 'Delivery' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Sent' },
];
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ isPlatform ? 'Platform communications' : 'Communications' }}
            </h2>
        </template>

        <Head :title="isPlatform ? 'Platform communications' : 'Communications'" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="max-w-2xl text-sm text-ink-500">
                        <template v-if="isPlatform">
                            Email attendee accounts across every event. These are always marketing emails, so only
                            people who agreed to hear from Lumko receive them.
                        </template>
                        <template v-else>
                            Email this event's registrants: everyone, a filtered group, or people you choose. Attachments
                            are allowed.
                        </template>
                    </p>
                    <Button :href="createUrl">New email</Button>
                </div>

                <DataTable :columns="columns" :rows="communications.data">
                    <template #cell-subject="{ row }">
                        <Link :href="row.url" class="link">{{ row.subject }}</Link>
                        <p class="text-meta text-ink-500">{{ categoryLabel[row.category] }}</p>
                    </template>
                    <template #cell-audience="{ row }">
                        {{ modeLabel[row.recipient_mode] }}
                        <p class="text-meta text-ink-500">{{ row.recipient_count }} {{ row.recipient_count === 1 ? 'person' : 'people' }}</p>
                    </template>
                    <template #cell-delivery="{ row }">
                        <span class="tabular-nums">{{ row.sent_count }} sent</span>
                        <p v-if="row.failed_count || row.skipped_count" class="text-meta text-ink-500">
                            <span v-if="row.failed_count">{{ row.failed_count }} failed</span>
                            <span v-if="row.failed_count && row.skipped_count"> · </span>
                            <span v-if="row.skipped_count">{{ row.skipped_count }} skipped</span>
                        </p>
                    </template>
                    <template #cell-status="{ row }">
                        <Badge :variant="sendStatus[row.status]?.variant ?? 'neutral'">{{ sendStatus[row.status]?.label ?? row.status }}</Badge>
                    </template>
                    <template #cell-created_at="{ row }">
                        {{ formatDateTime(row.created_at) }}
                        <p v-if="row.sender" class="text-meta text-ink-500">by {{ row.sender }}</p>
                    </template>
                    <template #empty>
                        <EmptyState title="No emails sent yet" description="Emails you send from here are listed with their delivery results." />
                    </template>
                </DataTable>

                <div v-if="communications.links.length > 3" class="flex flex-wrap gap-1">
                    <Link
                        v-for="(link, i) in communications.links"
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
            </div>
        </div>
    </AdminLayout>
</template>
