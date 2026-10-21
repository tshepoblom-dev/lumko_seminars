<script setup>
import Button from '@/Components/Button.vue';
import { computed } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AuthFrame from '@/Components/AuthFrame.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <PublicLayout>
    <Head title="Email Verification" />

    <AuthFrame title="Verify your email">

        <div class="mt-4 text-sm text-ink-600">
            Thanks for signing up! Before getting started, could you verify your
            email address by clicking on the link we just emailed to you? If you
            didn't receive the email, we will gladly send you another.
        </div>

        <div
            class="mt-4 border-t-2 border-verified-500 pt-3 text-sm font-medium text-ink-900"
            v-if="verificationLinkSent"
        >
            A new verification link has been sent to the email address you
            provided during registration.
        </div>

        <form class="mt-6" @submit.prevent="submit">
            <div class="mt-4 flex items-center justify-between">
                <Button type="submit" :disabled="form.processing">
                    Resend Verification Email
                </Button>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="link text-sm"
                    >Log Out</Link
                >
            </div>
        </form>
    </AuthFrame>
    </PublicLayout>
</template>
