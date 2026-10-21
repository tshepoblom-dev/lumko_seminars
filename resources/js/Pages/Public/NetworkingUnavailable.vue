<script setup>
import { Head } from '@inertiajs/vue3';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import Notice from '@/Components/Notice.vue';
import Button from '@/Components/Button.vue';
</script>

<template>
    <AttendeeLayout>
    <Head title="Networking" />

    <Notice eyebrow="Networking" title="Register to unlock networking">
        <template #actions>
            <Button href="/attend" size="lg" arrow>Register to attend</Button>
        </template>
    </Notice>
    </AttendeeLayout>
</template>
