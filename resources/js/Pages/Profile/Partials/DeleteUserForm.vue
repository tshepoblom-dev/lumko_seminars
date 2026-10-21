<script setup>
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

// B12 — deleting is anonymisation (AccountAnonymiser), same as on /account.
const confirming = ref(false);
const passwordInput = ref(null);

const form = useForm({ password: '' });

function open() {
    confirming.value = true;
    nextTick(() => passwordInput.value?.focus());
}

function close() {
    confirming.value = false;
    form.clearErrors();
    form.reset();
}

function deleteUser() {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => close(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <div>
        <h2 class="text-lg text-red-800">Delete account</h2>
        <p class="mt-1 max-w-prose text-sm text-ink-600">
            Your name, email, mobile and organisation are removed. Financial and consent records are kept
            without any personal information, as required for compliance. This cannot be undone.
        </p>

        <Button variant="secondary" class="mt-4 !border-red-300 !text-red-800 hover:!border-red-700" @click="open">
            Delete my account
        </Button>

        <Modal :show="confirming" title="Delete your account?" @close="close">
            <form id="delete-account-form" class="space-y-3" @submit.prevent="deleteUser">
                <p class="text-sm text-ink-600">Enter your password to confirm. You'll be signed out straight away.</p>
                <FormField id="delete-password" label="Your password" :error="form.errors.password" v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" ref="passwordInput" v-model="form.password" type="password" autocomplete="current-password" :aria-describedby="describedBy" :aria-invalid="invalid" class="w-full" />
                </FormField>
            </form>
            <template #footer>
                <Button variant="secondary" @click="close">Cancel</Button>
                <Button type="submit" form="delete-account-form" variant="danger" :disabled="form.processing">
                    Permanently delete my account
                </Button>
            </template>
        </Modal>
    </div>
</template>
