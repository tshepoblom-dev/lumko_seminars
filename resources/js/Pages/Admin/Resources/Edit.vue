<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, useForm } from '@inertiajs/vue3';

const eventRoute = useEventRoute();

const props = defineProps({ resource: Object });

const form = useForm({
    title: props.resource.title,
    description: props.resource.description,
    category: props.resource.category,
    file: null,
    external_url: props.resource.external_url ?? '',
    _method: 'put',
});

function submit() {
    form.post(eventRoute('admin.resources.update', { resource: props.resource.id }), { forceFormData: true });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Edit resource</h2>
        </template>

        <Head title="Edit resource" />

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium" for="admin-resources-edit-2">Title</label>
                        <input id="admin-resources-edit-2" v-model="form.title" type="text" class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-resources-edit-3">Description</label>
                        <textarea id="admin-resources-edit-3" v-model="form.description" class="mt-1 w-full rounded border-gray-300"></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-resources-edit-4">Category</label>
                        <select id="admin-resources-edit-4" v-model="form.category" class="mt-1 w-full rounded border-gray-300">
                            <option value="before_event">Before the event</option>
                            <option value="during_event">During the event</option>
                            <option value="after_event">After the event</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-resources-edit-5">Replace file (optional)</label>
                        <input id="admin-resources-edit-5" type="file" class="block w-full max-w-full text-sm" @input="form.file = $event.target.files[0]" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-resources-edit-6">External link</label>
                        <input id="admin-resources-edit-6" v-model="form.external_url" type="url" class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <p v-if="form.errors.file" class="text-sm text-red-600">{{ form.errors.file }}</p>
                    <button type="submit" class="rounded bg-ink-900 px-4 py-2 text-sm text-white" :disabled="form.processing">
                        Update resource
                    </button>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
