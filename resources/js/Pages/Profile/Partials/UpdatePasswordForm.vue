<script setup>
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const passwordInput = ref(null);
const currentPasswordInput = ref(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function updatePassword() {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value.focus();
            }
        },
    });
}
</script>

<template>
    <div>
        <h2 class="text-lg text-ink-900">Change password</h2>
        <p class="mt-1 text-sm text-ink-600">Use a long password you don't use anywhere else.</p>

        <form class="mt-6 max-w-md space-y-5" @submit.prevent="updatePassword">
            <FormField id="current_password" label="Current password" :error="form.errors.current_password" required v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" ref="currentPasswordInput" v-model="form.current_password" type="password" autocomplete="current-password" :aria-describedby="describedBy" :aria-invalid="invalid" class="w-full" />
            </FormField>

            <FormField id="password" label="New password" :error="form.errors.password" required v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" ref="passwordInput" v-model="form.password" type="password" autocomplete="new-password" :aria-describedby="describedBy" :aria-invalid="invalid" class="w-full" />
            </FormField>

            <FormField id="password_confirmation" label="Confirm new password" :error="form.errors.password_confirmation" required v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" v-model="form.password_confirmation" type="password" autocomplete="new-password" :aria-describedby="describedBy" :aria-invalid="invalid" class="w-full" />
            </FormField>

            <Button type="submit" :disabled="form.processing">Update password</Button>
        </form>
    </div>
</template>
