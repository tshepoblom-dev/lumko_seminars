<script setup>
import FormField from '@/Components/FormField.vue';
import Button from '@/Components/Button.vue';
import Checkbox from '@/Components/Checkbox.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AuthFrame from '@/Components/AuthFrame.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <PublicLayout>
    <Head title="Log in" />

    <AuthFrame title="Sign in">

        <div v-if="status" class="mt-4 border-t-2 border-verified-500 pt-3 text-sm font-medium text-ink-900">
            {{ status }}
        </div>

        <form class="mt-6" @submit.prevent="submit">
            <FormField id="email" label="Email" :error="form.errors.email" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" type="email" v-model="form.email" required autofocus autocomplete="username" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <FormField id="password" label="Password" :error="form.errors.password" class="mt-4" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" type="password" v-model="form.password" required autocomplete="current-password" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <div class="mt-4 block">
                <label class="flex items-center">
                    <Checkbox name="remember" v-model:checked="form.remember" />
                    <span class="ms-2 text-sm text-ink-600"
                        >Remember me</span
                    >
                </label>
            </div>

            <div class="mt-4 flex items-center justify-end">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="link text-sm"
                >
                    Forgot your password?
                </Link>

                <Button type="submit" class="ms-4" :disabled="form.processing">
                    Log in
                </Button>
            </div>
        </form>

        <p class="mt-10 border-t border-rule pt-6 text-sm text-ink-600">
            No account yet?
            <Link :href="route('event.register')" class="link">Register for the event</Link>
            and your account is created at the same time.
        </p>
    </AuthFrame>
    </PublicLayout>
</template>
