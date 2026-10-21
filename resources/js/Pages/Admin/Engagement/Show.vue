<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({ event: Object, stats: Object });

const cards = [
    ['Registered (total)', 'registered_total'],
    ['Online', 'registered_online'],
    ['In person', 'registered_in_person'],
    ['Checked in', 'checked_in'],
    ['Questions submitted', 'questions_submitted'],
    ['Questions approved', 'questions_approved'],
    ['Poll responses', 'poll_responses'],
    ['Resource views/downloads', 'resource_views'],
    ['Networking opt-ins', 'networking_opt_ins'],
    ['Lumko marketing opt-ins', 'lumko_marketing_opt_ins'],
    ['Feedback responses', 'feedback_count'],
    ['Average feedback rating', 'feedback_average_rating'],
    ['Feedback requesting follow-up', 'feedback_wants_contact'],
];
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Engagement — {{ event.name }}</h2>
        </template>

        <Head title="Engagement" />

        <div class="py-12">
            <div class="mx-auto grid max-w-5xl gap-4 sm:grid-cols-3 sm:px-6 lg:px-8">
                <div v-for="[label, key] in cards" :key="key" class="rounded-lg bg-white p-6 shadow">
                    <p class="text-sm text-gray-500">{{ label }}</p>
                    <p class="mt-1 text-3xl font-bold">{{ stats[key] ?? '—' }}</p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
