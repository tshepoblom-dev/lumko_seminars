<script setup>
import { Head } from '@inertiajs/vue3';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import Notice from '@/Components/Notice.vue';
import Button from '@/Components/Button.vue';
</script>

<template>
    <AttendeeLayout>
    <Head title="My Dashboard" />

    <Notice eyebrow="Your dashboard" title="You're not registered yet">
        <p>Register for the upcoming event to see your dashboard here.</p>
        <template #actions>
            <Button href="/attend" size="lg" arrow>Register to attend</Button>
        </template>
    </Notice>
    </AttendeeLayout>
</template>
