<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Button from '@/Components/Button.vue';
import DataTable from '@/Components/DataTable.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    sessions: Array,
});

const eventRoute = useEventRoute();

const columns = [
    { key: 'time', label: 'Time' },
    { key: 'title', label: 'Session' },
    { key: 'speakers', label: 'Speakers' },
];

const time = (iso) =>
    iso ? new Date(iso).toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Johannesburg' }) : '';

const sessionToDelete = ref(null);

function destroy() {
    router.delete(eventRoute('admin.programme-sessions.destroy', { programmeSession: sessionToDelete.value.id }), {
        onFinish: () => { sessionToDelete.value = null; },
    });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Programme</h2>
        </template>

        <Head title="Programme" />

        <div class="py-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-gray-600">
                    Sessions pick their speakers from this event's
                    <Link :href="eventRoute('admin.events.speakers.index')" class="text-brand underline">speaker list</Link>.
                </p>
                <Button :href="eventRoute('admin.programme-sessions.create')">New session</Button>
            </div>

            <DataTable :columns="columns" :rows="sessions">
                <template #cell-time="{ row }">
                    <span class="tnum whitespace-nowrap">{{ time(row.start_time) }}&ndash;{{ time(row.end_time) }}</span>
                </template>
                <template #cell-speakers="{ row }">
                    <span v-if="row.speakers.length">{{ row.speakers.join(', ') }}</span>
                    <span v-else class="text-gray-600">None</span>
                </template>
                <template #actions="{ row }">
                    <Link :href="eventRoute('admin.programme-sessions.edit', { programmeSession: row.id })" class="text-brand hover:underline">Edit</Link>
                    <button type="button" class="ml-3 text-red-700 hover:underline" @click="sessionToDelete = row">Delete</button>
                </template>
                <template #empty>
                    <EmptyState title="No sessions yet" description="Build this event's running order session by session.">
                        <template #action>
                            <Button :href="eventRoute('admin.programme-sessions.create')" size="sm">New session</Button>
                        </template>
                    </EmptyState>
                </template>
            </DataTable>
        </div>

        <Modal :show="!!sessionToDelete" title="Delete session" @close="sessionToDelete = null">
            <p class="text-sm text-gray-600">
                Delete <strong>{{ sessionToDelete?.title }}</strong>? This cannot be undone.
            </p>
            <template #footer>
                <Button variant="secondary" @click="sessionToDelete = null">Cancel</Button>
                <Button variant="danger" @click="destroy">Delete</Button>
            </template>
        </Modal>
    </AdminLayout>
</template>
