<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({
    events: Array,
});

function destroy(event) {
    if (!event.can_delete) {
        return;
    }
    if (confirm(`Delete "${event.name}"? This cannot be undone.`)) {
        router.delete(route('admin.events.destroy', event.id));
    }
}

const formatDate = (iso) =>
    iso
        ? new Date(iso).toLocaleString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Johannesburg' })
        : '';

function archive(event) {
    if (confirm(`Archive "${event.name}"? It will be hidden from the public site, but all its data is kept.`)) {
        router.post(route('admin.events.archive', event.id));
    }
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Events</h2>
        </template>

        <Head title="Events" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="mb-4 text-right">
                    <Link
                        :href="route('admin.events.create')"
                        class="rounded bg-ink-900 px-4 py-2 text-sm text-white"
                    >
                        New event
                    </Link>
                </div>
                <div class="overflow-x-auto rounded-lg bg-white shadow">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left">Name</th>
                                <th class="px-4 py-2 text-left">Starts</th>
                                <th class="px-4 py-2 text-left">Status</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="event in events" :key="event.id">
                                <td class="px-4 py-2">
                                    <Link :href="route('admin.events.show', event.id)" class="font-medium text-brand hover:underline">{{ event.name }}</Link>
                                </td>
                                <td class="px-4 py-2">{{ formatDate(event.starts_at) }}</td>
                                <td class="px-4 py-2">
                                    <span class="capitalize">{{ event.status }}</span>
                                    <p v-if="!event.can_delete" class="mt-0.5 text-xs text-gray-500">
                                        Has data — archive instead of delete
                                    </p>
                                </td>
                                <td class="space-x-2 px-4 py-2 text-right">
                                    <Link :href="route('admin.events.show', event.id)" class="text-signal-700">
                                        Open
                                    </Link>
                                    <Link :href="route('admin.events.edit', event.id)" class="text-signal-700">
                                        Settings
                                    </Link>
                                    <button
                                        v-if="event.status !== 'archived'"
                                        class="text-gray-600"
                                        @click="archive(event)"
                                    >
                                        Archive
                                    </button>
                                    <button
                                        class="text-red-600 disabled:cursor-not-allowed disabled:text-gray-300"
                                        :disabled="!event.can_delete"
                                        :title="event.can_delete ? '' : `Can't delete — still has: ${event.blocking_summary}. Archive it instead.`"
                                        @click="destroy(event)"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
