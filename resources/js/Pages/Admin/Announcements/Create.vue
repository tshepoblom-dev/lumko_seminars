<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, useForm } from '@inertiajs/vue3';

const eventRoute = useEventRoute();

const form = useForm({
    title: '',
    body: '',
    type: 'event_update',
    audience: 'all',
    published_at: '',
});

function submit() {
    form.post(eventRoute('admin.announcements.store'));
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">New announcement</h2>
        </template>

        <Head title="New announcement" />

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium" for="admin-announcements-create-2">Title</label>
                        <input id="admin-announcements-create-2" v-model="form.title" type="text" class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-announcements-create-3">Body</label>
                        <textarea id="admin-announcements-create-3" v-model="form.body" rows="4" class="mt-1 w-full rounded border-gray-300"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-announcements-create-4">Type</label>
                        <select id="admin-announcements-create-4" v-model="form.type" class="mt-1 w-full rounded border-gray-300">
                            <option value="event_update">Event update</option>
                            <option value="reminder">Reminder</option>
                            <option value="programme_update">Programme update</option>
                            <option value="resource_available">Resource available</option>
                            <option value="live_event_notice">Live event notice</option>
                            <option value="post_event">Post-event</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-announcements-create-5">Audience</label>
                        <select id="admin-announcements-create-5" v-model="form.audience" class="mt-1 w-full rounded border-gray-300">
                            <option value="all">Everyone registered</option>
                            <option value="online">Online attendees only</option>
                            <option value="in_person">In-person attendees only</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-announcements-create-6">Publish at (leave blank to save as draft)</label>
                        <input id="admin-announcements-create-6" v-model="form.published_at" type="datetime-local" class="mt-1 w-full rounded border-gray-300" />
                        <p class="mt-1 text-xs text-gray-500">
                            Makes it visible in-app (dashboard/notifications) at this time. Email is always a
                            separate, explicit step — use "Send email" once it's published.
                        </p>
                    </div>
                    <button type="submit" class="rounded bg-ink-900 px-4 py-2 text-sm text-white" :disabled="form.processing">
                        Save announcement
                    </button>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
