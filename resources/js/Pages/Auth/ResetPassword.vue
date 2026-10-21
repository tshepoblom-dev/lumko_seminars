<script setup>
import FormField from '@/Components/FormField.vue';
import Button from '@/Components/Button.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AuthFrame from '@/Components/AuthFrame.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    email: {
        type: String,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <PublicLayout>
    <Head title="Reset Password" />

    <AuthFrame title="Reset your password">

        <form class="mt-6" @submit.prevent="submit">
            <FormField id="email" label="Email" :error="form.errors.email" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" type="email" v-model="form.email" required autofocus autocomplete="username" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <FormField id="password" label="Password" :error="form.errors.password" class="mt-4" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" type="password" v-model="form.password" required autocomplete="new-password" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <FormField id="password_confirmation" label="Confirm Password" :error="form.errors.password_confirmation" class="mt-4" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" type="password" v-model="form.password_confirmation" required autocomplete="new-password" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <div class="mt-4 flex items-center justify-end">
                <Button type="submit" :disabled="form.processing">
                    Reset Password
                </Button>
            </div>
        </form>
    </AuthFrame>
    </PublicLayout>
</template>
