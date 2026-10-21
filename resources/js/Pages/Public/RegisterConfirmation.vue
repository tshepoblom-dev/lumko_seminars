<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Notice from '@/Components/Notice.vue';
import Button from '@/Components/Button.vue';
import { Head, usePage } from '@inertiajs/vue3';

const page = usePage();
</script>

<template>
    <PublicLayout>
    <Head title="You're registered" />

    <Notice eyebrow="Registered" :title="page.props.flash?.info ?? `You're registered!`">
        <p>
            We've sent a confirmation to your email. If this is your first time registering, check
            your inbox for a link to set your account password.
        </p>
        <template #actions>
            <Button href="/" variant="secondary">Back to the event page</Button>
        </template>
    </Notice>
    </PublicLayout>
</template>
