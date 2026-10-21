<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, Link, router } from '@inertiajs/vue3';

const eventRoute = useEventRoute();

defineProps({ announcements: Array });

function destroy(announcement) {
    if (confirm(`Delete "${announcement.title}"?`)) {
        router.delete(eventRoute('admin.announcements.destroy', { announcement: announcement.id }));
    }
}

function sendEmail(announcement) {
    if (announcement.emailed_at) {
        if (!confirm(`This was already emailed on ${announcement.emailed_at}. Send again to everyone registered?`)) {
            return;
        }
        router.post(eventRoute('admin.announcements.send-email', { announcement: announcement.id }), { resend: true });
        return;
    }

    router.post(eventRoute('admin.announcements.send-email', { announcement: announcement.id }));
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Announcements</h2>
        </template>

        <Head title="Announcements" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="mb-4 text-right">
                    <Link :href="eventRoute('admin.announcements.create')" class="rounded bg-ink-900 px-4 py-2 text-sm text-white">
                        New announcement
                    </Link>
                </div>
                <div class="overflow-x-auto rounded-lg bg-white shadow">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2 text-left">Title</th>
                                <th class="px-4 py-2 text-left">Type</th>
                                <th class="px-4 py-2 text-left">Published</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="a in announcements" :key="a.id">
                                <td class="px-4 py-2">{{ a.title }}</td>
                                <td class="px-4 py-2 capitalize">{{ a.type }}</td>
                                <td class="px-4 py-2">{{ a.published_at ?? 'Draft' }}</td>
                                <td class="space-x-2 px-4 py-2 text-right">
                                    <Link :href="eventRoute('admin.announcements.edit', { announcement: a.id })" class="text-signal-700">Edit</Link>
                                    <button v-if="a.published_at" class="text-green-700" @click="sendEmail(a)">
                                        {{ a.emailed_at ? 'Resend email' : 'Send email' }}
                                    </button>
                                    <button class="text-red-600" @click="destroy(a)">Delete</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
