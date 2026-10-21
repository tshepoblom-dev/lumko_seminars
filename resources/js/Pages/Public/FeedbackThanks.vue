<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import Notice from '@/Components/Notice.vue';

defineProps({ otherPublishedEvents: Array });
</script>

<template>
    <AttendeeLayout>
    <Head title="Thank you" />

    <Notice eyebrow="Feedback received" title="Thank you!">
        <p>
            Your feedback helps us plan better sessions. Recordings and presentation slides will
            appear on your
            <Link href="/resources" class="link">resources page</Link>
            once available.
        </p>

        <div v-if="otherPublishedEvents.length" class="pt-6">
            <p class="eyebrow border-b border-ink-900 pb-2 text-ink-400">Upcoming from Lumko</p>
            <ul>
                <li v-for="e in otherPublishedEvents" :key="e.name" class="flex justify-between gap-4 border-b border-rule py-3 text-sm">
                    <span class="font-medium text-ink-900">{{ e.name }}</span>
                    <span class="tnum text-ink-500">{{ e.starts_at }}</span>
                </li>
            </ul>
        </div>

        <template #actions>
            <Link href="/dashboard" class="link text-sm">Back to dashboard</Link>
        </template>
    </Notice>
    </AttendeeLayout>
</template>
