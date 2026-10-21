<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, Link, router } from '@inertiajs/vue3';

const eventRoute = useEventRoute();

defineProps({ resources: Array });

function destroy(resource) {
    if (confirm(`Delete "${resource.title}"?`)) {
        router.delete(eventRoute('admin.resources.destroy', { resource: resource.id }));
    }
}

function togglePublish(resource) {
    router.post(eventRoute(resource.is_published ? 'admin.resources.unpublish' : 'admin.resources.publish', { resource: resource.id }));
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Resources</h2>
        </template>

        <Head title="Resources" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="mb-4 text-right">
                    <Link :href="eventRoute('admin.resources.create')" class="rounded bg-ink-900 px-4 py-2 text-sm text-white">
                        New resource
                    </Link>
                </div>
                <div class="overflow-x-auto rounded-lg bg-white shadow">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left">Title</th>
                                <th class="px-4 py-2 text-left">Category</th>
                                <th class="px-4 py-2 text-left">Views/downloads</th>
                                <th class="px-4 py-2 text-left">Status</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="r in resources" :key="r.id">
                                <td class="px-4 py-2">
                                    {{ r.title }}
                                    <span v-if="r.shared_by" class="block text-xs text-gray-600">{{ r.shared_by }}</span>
                                </td>
                                <td class="px-4 py-2">{{ r.category_label }}</td>
                                <td class="px-4 py-2">{{ r.views_count }}</td>
                                <td class="px-4 py-2">
                                    <button
                                        class="rounded px-2 py-1 text-xs"
                                        :class="r.is_published ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'"
                                        @click="togglePublish(r)"
                                    >
                                        {{ r.is_published ? 'Published' : 'Unpublished' }}
                                    </button>
                                </td>
                                <td class="space-x-2 px-4 py-2 text-right">
                                    <Link :href="eventRoute('admin.resources.edit', { resource: r.id })" class="text-signal-700">Edit</Link>
                                    <button class="text-red-600" @click="destroy(r)">Delete</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
