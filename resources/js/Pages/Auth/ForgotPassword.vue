<script setup>
import FormField from '@/Components/FormField.vue';
import Button from '@/Components/Button.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AuthFrame from '@/Components/AuthFrame.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <PublicLayout>
    <Head title="Forgot Password" />

    <AuthFrame title="Forgot your password?">

        <div class="mt-4 text-sm text-ink-600">
            No problem. Just let us know your email address and we will email you a password reset link
            that will allow you to choose a new one.
        </div>

        <div
            v-if="status"
            class="mt-4 border-t-2 border-verified-500 pt-3 text-sm font-medium text-ink-900"
        >
            {{ status }}
        </div>

        <form class="mt-6" @submit.prevent="submit">
            <FormField id="email" label="Email" :error="form.errors.email" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" type="email" v-model="form.email" required autofocus autocomplete="username" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <div class="mt-4 flex items-center justify-end">
                <Button type="submit" :disabled="form.processing">
                    Email Password Reset Link
                </Button>
            </div>
        </form>
    </AuthFrame>
    </PublicLayout>
</template>
