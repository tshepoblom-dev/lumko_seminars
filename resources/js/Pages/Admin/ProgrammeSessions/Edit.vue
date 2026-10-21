<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SessionForm from '@/Components/Admin/SessionForm.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, useForm } from '@inertiajs/vue3';
import { toLocalInput } from '@/lib/eventFormat';

const props = defineProps({
    session: Object,
    speakers: { type: Array, default: () => [] },
});

const eventRoute = useEventRoute();

const form = useForm({
    speaker_ids: [...(props.session.speaker_ids ?? [])],
    title: props.session.title,
    description: props.session.description ?? '',
    start_time: toLocalInput(props.session.start_time),
    end_time: toLocalInput(props.session.end_time),
    sort_order: props.session.sort_order,
});

function submit() {
    form.put(eventRoute('admin.programme-sessions.update', { programmeSession: props.session.id }));
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Edit session</h2>
        </template>

        <Head title="Edit session" />

        <div class="py-6">
            <div class="max-w-2xl">
                <SessionForm :form="form" :speakers="speakers" submit-label="Save changes" @submit="submit" />
            </div>
        </div>
    </AdminLayout>
</template>
