<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, useForm } from '@inertiajs/vue3';

const eventRoute = useEventRoute();

const form = useForm({
    title: '',
    description: '',
    category: 'before_event',
    file: null,
    external_url: '',
});

function submit() {
    form.post(eventRoute('admin.resources.store'), { forceFormData: true });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">New resource</h2>
        </template>

        <Head title="New resource" />

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium" for="admin-resources-create-2">Title</label>
                        <input id="admin-resources-create-2" v-model="form.title" type="text" class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-resources-create-3">Description</label>
                        <textarea id="admin-resources-create-3" v-model="form.description" class="mt-1 w-full rounded border-gray-300"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-resources-create-4">Category</label>
                        <select id="admin-resources-create-4" v-model="form.category" class="mt-1 w-full rounded border-gray-300">
                            <option value="before_event">Before the event</option>
                            <option value="during_event">During the event</option>
                            <option value="after_event">After the event</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-resources-create-5">File (PDF, slides, etc.)</label>
                        <input id="admin-resources-create-5" type="file" class="block w-full max-w-full text-sm" @input="form.file = $event.target.files[0]" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-resources-create-6">— or an external link</label>
                        <input id="admin-resources-create-6" v-model="form.external_url" type="url" placeholder="https://..." class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <p v-if="form.errors.file" class="text-sm text-red-600">{{ form.errors.file }}</p>
                    <p class="text-xs text-gray-500">
                        New resources are unpublished by default — publish them from the list once ready.
                    </p>
                    <button type="submit" class="rounded bg-ink-900 px-4 py-2 text-sm text-white" :disabled="form.processing">
                        Save resource
                    </button>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
