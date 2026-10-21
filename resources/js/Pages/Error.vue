<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Notice from '@/Components/Notice.vue';
import Button from '@/Components/Button.vue';

const props = defineProps({
    status: { type: Number, required: true },
});

const copy = computed(() => ({
    403: { title: 'You don\'t have access to this page', body: 'Your account isn\'t allowed to view this. If you think that\'s a mistake, please contact the organisers.' },
    404: { title: 'Page not found', body: 'The page you were looking for doesn\'t exist or has moved.' },
    419: { title: 'This page has expired', body: 'Please go back, refresh the page and try again.' },
    429: { title: 'Too many requests', body: 'Please wait a moment and try again.' },
    500: { title: 'Something went wrong on our side', body: 'We\'ve been notified. Please try again in a few minutes.' },
    503: { title: 'We\'ll be right back', body: 'The site is briefly unavailable for maintenance. Please try again shortly.' },
}[props.status] ?? { title: 'Something went wrong', body: 'Please try again.' }));
</script>

<template>
    <PublicLayout>
        <Head :title="copy.title" />

        <Notice :eyebrow="`Error ${status}`" :title="copy.title">
            <p>{{ copy.body }}</p>
            <template #actions>
                <Button href="/" arrow>Go to the home page</Button>
            </template>
        </Notice>
    </PublicLayout>
</template>
