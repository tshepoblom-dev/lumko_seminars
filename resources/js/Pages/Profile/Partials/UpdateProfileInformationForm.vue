<script setup>
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    profile: { type: Object, required: true },
    mustVerifyEmail: { type: Boolean, default: false },
    status: { type: String, default: null },
});

const form = useForm({
    first_name: props.profile.first_name ?? '',
    last_name: props.profile.last_name ?? '',
    email: props.profile.email,
});

function submit() {
    form.patch(route('profile.update'), { preserveScroll: true });
}
</script>

<template>
    <div>
        <h2 class="text-lg text-ink-900">Name and sign-in email</h2>
        <p class="mt-1 text-sm text-ink-600">
            Changing your email means confirming the new address before you can use the rest of the site again.
        </p>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div class="grid gap-5 sm:grid-cols-2">
                <FormField id="profile-first-name" label="First name" :error="form.errors.first_name" required v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.first_name" type="text" autocomplete="given-name" :aria-describedby="describedBy" :aria-invalid="invalid" class="w-full" />
                </FormField>
                <FormField id="profile-last-name" label="Last name" :error="form.errors.last_name" required v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.last_name" type="text" autocomplete="family-name" :aria-describedby="describedBy" :aria-invalid="invalid" class="w-full" />
                </FormField>
            </div>

            <FormField id="profile-email" label="Email" :error="form.errors.email" required v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" v-model="form.email" type="email" autocomplete="username" :aria-describedby="describedBy" :aria-invalid="invalid" class="w-full" />
            </FormField>

            <div v-if="mustVerifyEmail && !profile.email_verified" class="border-t-2 border-amber-500 bg-amber-50 p-3 text-sm text-amber-900">
                <p>
                    Your email address isn't confirmed yet.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="font-medium underline focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500"
                    >
                        Send the confirmation link again
                    </Link>
                </p>
                <p v-if="status === 'verification-link-sent'" class="mt-1 font-medium" role="status">
                    A new confirmation link has been sent to your email address.
                </p>
            </div>

            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>
    </div>
</template>
