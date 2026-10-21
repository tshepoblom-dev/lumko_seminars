<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Notice from '@/Components/Notice.vue';
import Button from '@/Components/Button.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    event: Object,
});
</script>

<template>
    <PublicLayout>
    <Head title="Registration closed" />

    <Notice v-if="event && event.has_ended" eyebrow="Registration closed" :title="`${event.name} has ended`">
        <p>Thank you to everyone who joined us. Registration for this event is now closed.</p>
        <template #actions>
            <Button v-if="event.recording_url" :href="event.recording_url" external arrow>Watch the recording</Button>
            <Link href="/" class="link text-sm">Back to home</Link>
        </template>
    </Notice>
    <Notice v-else eyebrow="Registration closed" title="Registration isn't open right now">
        <p>
            There's no event currently open for registration. Check back soon, or contact Lumko for details
            of upcoming events.
        </p>
        <template #actions>
            <Link href="/" class="link text-sm">Back to home</Link>
        </template>
    </Notice>
    </PublicLayout>
</template>
