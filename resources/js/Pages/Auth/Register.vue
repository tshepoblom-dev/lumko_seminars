<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AuthFrame from '@/Components/AuthFrame.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import Button from '@/Components/Button.vue';
import Checkbox from '@/Components/Checkbox.vue';

// Standalone sign-up. Registering for an event happens afterwards, from the
// dashboard or /attend, which prefills these details.
const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    password_confirmation: '',
    accept_legal: false,
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <PublicLayout>
    <Head title="Create your account" />

    <AuthFrame title="Create your account">
        <p class="text-sm text-ink-600">
            One account for every Lumko seminar. You can register for an event straight after.
        </p>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div class="grid gap-5 sm:grid-cols-2">
                <FormField id="first_name" label="First name" :error="form.errors.first_name" v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.first_name" type="text" required autofocus autocomplete="given-name" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
                <FormField id="last_name" label="Last name" :error="form.errors.last_name" v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.last_name" type="text" required autocomplete="family-name" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
            </div>

            <FormField id="email" label="Email" :error="form.errors.email" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" v-model="form.email" type="email" required autocomplete="username" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <FormField id="password" label="Password" :error="form.errors.password" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" v-model="form.password" type="password" required autocomplete="new-password" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <FormField id="password_confirmation" label="Confirm password" :error="form.errors.password_confirmation" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" v-model="form.password_confirmation" type="password" required autocomplete="new-password" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>

            <div>
                <label class="flex items-start gap-3 text-sm text-ink-800">
                    <Checkbox v-model:checked="form.accept_legal" name="accept_legal" required class="mt-0.5" :aria-invalid="!!form.errors.accept_legal" aria-describedby="accept_legal-error" />
                    <span>
                        I have read and accept the
                        <a :href="route('legal.terms')" target="_blank" rel="noopener" class="link">Terms &amp; Conditions<span class="sr-only"> (opens in a new tab)</span></a>
                        and the
                        <a :href="route('legal.privacy')" target="_blank" rel="noopener" class="link">Privacy Policy<span class="sr-only"> (opens in a new tab)</span></a>.
                    </span>
                </label>
                <p id="accept_legal-error" class="field-error" role="alert">{{ form.errors.accept_legal }}</p>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-4 pt-2">
                <Link :href="route('login')" class="link text-sm">Already have an account? Sign in</Link>
                <Button type="submit" :disabled="form.processing" arrow>Create account</Button>
            </div>
        </form>
    </AuthFrame>
    </PublicLayout>
</template>
