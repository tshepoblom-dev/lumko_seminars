<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    logs: Object,
    filters: Object,
});

const actionFilter = ref(props.filters.action ?? '');

function applyFilter() {
    router.get(route('admin.audit.index'), { action: actionFilter.value || undefined }, { preserveState: true });
}

function detail(entry) {
    const parts = [];
    if (entry.before) parts.push('before: ' + JSON.stringify(entry.before));
    if (entry.after) parts.push('after: ' + JSON.stringify(entry.after));
    return parts.join(' · ');
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Audit log</h2>
        </template>

        <Head title="Audit log" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <form class="mb-4 flex gap-2" @submit.prevent="applyFilter">
                    <label for="audit-action-filter" class="sr-only">Filter by action</label>
                    <input
                        id="audit-action-filter"
                        v-model="actionFilter"
                        type="text"
                        placeholder="Filter by action (e.g. payment.approved)"
                        class="w-72 rounded border-gray-300 text-sm"
                    />
                    <button type="submit" class="rounded bg-ink-900 px-4 py-2 text-sm text-white">Filter</button>
                </form>

                <!-- Focusable so keyboard users can scroll the table sideways
                     on narrow screens (rows contain nothing else focusable). -->
                <div
                    class="overflow-x-auto rounded-lg bg-white shadow focus:outline-none focus-visible:ring-2 focus-visible:ring-brand"
                    role="region"
                    aria-label="Audit log entries"
                    tabindex="0"
                >
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left">When</th>
                                <th class="px-4 py-2 text-left">Action</th>
                                <th class="px-4 py-2 text-left">Actor</th>
                                <th class="px-4 py-2 text-left">Subject</th>
                                <th class="px-4 py-2 text-left">Details</th>
                                <th class="px-4 py-2 text-left">IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="entry in logs.data" :key="entry.id">
                                <td class="whitespace-nowrap px-4 py-2 text-gray-500">{{ entry.created_at }}</td>
                                <td class="px-4 py-2 font-mono text-xs">{{ entry.action }}</td>
                                <td class="px-4 py-2">{{ entry.actor }}</td>
                                <td class="px-4 py-2 text-gray-500">
                                    <span v-if="entry.subject_type">{{ entry.subject_type }} #{{ entry.subject_id }}</span>
                                </td>
                                <td class="max-w-xs truncate px-4 py-2 text-xs text-gray-500" :title="detail(entry)">{{ detail(entry) }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ entry.ip_address }}</td>
                            </tr>
                            <tr v-if="!logs.data.length">
                                <td colspan="6" class="px-4 py-8 text-center text-gray-500">No audit entries match.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <nav v-if="logs.links?.length > 3" class="mt-4 flex flex-wrap gap-1" aria-label="Pagination">
                    <Link
                        v-for="(link, i) in logs.links"
                        :key="i"
                        :href="link.url || '#'"
                        v-html="link.label"
                        class="rounded px-3 py-1 text-sm"
                        :class="[
                            link.active ? 'bg-ink-900 text-white' : 'text-gray-600 hover:bg-gray-100',
                            !link.url ? 'pointer-events-none opacity-40' : '',
                        ]"
                    />
                </nav>
            </div>
        </div>
    </AdminLayout>
</template>
