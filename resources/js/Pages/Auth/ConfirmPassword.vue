<script setup>
import FormField from '@/Components/FormField.vue';
import Button from '@/Components/Button.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AuthFrame from '@/Components/AuthFrame.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <PublicLayout>
    <Head title="Confirm Password" />

    <AuthFrame title="Confirm your password">

        <div class="mt-4 text-sm text-ink-600">
            This is a secure area of the application. Please confirm your
            password before continuing.
        </div>

        <form class="mt-6" @submit.prevent="submit">
            <FormField id="password" label="Password" :error="form.errors.password" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" type="password" v-model="form.password" required autocomplete="current-password" autofocus :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <div class="mt-4 flex justify-end">
                <Button type="submit" class="ms-4" :disabled="form.processing">
                    Confirm
                </Button>
            </div>
        </form>
    </AuthFrame>
    </PublicLayout>
</template>
