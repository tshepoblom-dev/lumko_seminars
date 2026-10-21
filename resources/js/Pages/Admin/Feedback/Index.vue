<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({ summary: Object, feedback: Array });
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Feedback</h2>
        </template>

        <Head title="Feedback" />

        <div class="py-12">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-lg bg-white p-6 shadow">
                        <p class="text-sm text-gray-500">Responses</p>
                        <p class="mt-1 text-3xl font-bold">{{ summary.count }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-6 shadow">
                        <p class="text-sm text-gray-500">Average rating</p>
                        <p class="mt-1 text-3xl font-bold">{{ summary.average_rating ?? '—' }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-6 shadow">
                        <p class="text-sm text-gray-500">Requested follow-up</p>
                        <p class="mt-1 text-3xl font-bold">{{ summary.wants_contact_count }}</p>
                    </div>
                </div>

                <div class="mt-8 space-y-4">
                    <div v-for="f in feedback" :key="f.id" class="rounded-lg bg-white p-4 shadow">
                        <div class="flex items-center justify-between">
                            <p class="font-semibold" :class="{ 'italic text-gray-500': !f.contact }">
                                {{ f.contact ? `${f.contact.name} — ${f.contact.organisation ?? 'No organisation given'}` : 'Anonymous response' }}
                            </p>
                            <span class="rounded-full bg-signal-50 px-2 py-0.5 text-xs font-medium text-signal-700">
                                {{ f.rating }}/5
                            </span>
                        </div>
                        <p v-if="f.biggest_takeaway" class="mt-2 text-sm text-gray-700">{{ f.biggest_takeaway }}</p>
                        <p v-if="f.topics_next.length" class="mt-1 text-xs text-gray-500">
                            Wants to explore: {{ f.topics_next.join(', ') }}
                        </p>
                        <div v-if="f.contact" class="mt-2 rounded bg-green-50 p-2 text-xs text-green-800">
                            <p class="font-medium">Requested Lumko follow-up on: {{ f.contact_topics.join(', ') || 'unspecified' }}</p>
                            <p class="mt-1">{{ f.contact.email }}<template v-if="f.contact.mobile"> &middot; {{ f.contact.mobile }}</template></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
