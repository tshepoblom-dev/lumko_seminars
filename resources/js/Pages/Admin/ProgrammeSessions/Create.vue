<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SessionForm from '@/Components/Admin/SessionForm.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    speakers: { type: Array, default: () => [] },
    nextSortOrder: { type: Number, default: 1 },
});

const eventRoute = useEventRoute();

const form = useForm({
    speaker_ids: [],
    title: '',
    description: '',
    start_time: '',
    end_time: '',
    sort_order: props.nextSortOrder,
});

function submit() {
    form.post(eventRoute('admin.programme-sessions.store'));
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">New session</h2>
        </template>

        <Head title="New session" />

        <div class="py-6">
            <div class="max-w-2xl">
                <SessionForm :form="form" :speakers="speakers" submit-label="Save session" @submit="submit" />
            </div>
        </div>
    </AdminLayout>
</template>
