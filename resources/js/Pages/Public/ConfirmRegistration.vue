<script setup>
import FormField from '@/Components/FormField.vue';
import Button from '@/Components/Button.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AuthFrame from '@/Components/AuthFrame.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    email: { type: String, required: true },
    token: { type: String, required: true },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('registration.confirm.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <PublicLayout>
    <Head title="Confirm your registration" />

    <AuthFrame title="Confirm your registration">
        <p class="mt-2 text-sm text-ink-600">
            Set a password to finish setting up your account. This also confirms your email address.
        </p>

        <form class="mt-6" @submit.prevent="submit">
            <FormField id="email" label="Email" :error="form.errors.email" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" type="email" v-model="form.email" required autocomplete="username" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <FormField id="password" label="Choose a password" :error="form.errors.password" class="mt-4" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" type="password" v-model="form.password" required autofocus autocomplete="new-password" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <FormField id="password_confirmation" label="Confirm password" :error="form.errors.password_confirmation" class="mt-4" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" type="password" v-model="form.password_confirmation" required autocomplete="new-password" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <div class="mt-6">
                <Button type="submit" class="w-full justify-center" :disabled="form.processing">
                    Confirm & continue
                </Button>
            </div>
        </form>
    </AuthFrame>
    </PublicLayout>
</template>
