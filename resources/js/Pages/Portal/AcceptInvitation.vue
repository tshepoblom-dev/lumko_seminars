<script setup>
import AuthFrame from '@/Components/AuthFrame.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

// The destination of a speaker or partner portal invitation. `mode` says
// which of the four situations this is (see PortalInvitationController).
const props = defineProps({
    token: { type: String, required: true },
    kind: { type: String, required: true }, // speaker | partner
    subjectName: { type: String, required: true },
    email: { type: String, required: true },
    mode: { type: String, required: true }, // new | login | confirm | wrong_account
    suggestedName: { type: Object, default: () => ({ first: '', last: '' }) },
});

const title = computed(() => (props.kind === 'speaker'
    ? 'Your speaker portal'
    : `Join ${props.subjectName}`));

const intro = computed(() => (props.kind === 'speaker'
    ? 'See your sessions, share slides and resources with attendees, test your camera and mic on the livestream, and see who asked to connect with you.'
    : `Manage ${props.subjectName}'s partner page and see the people who chose to connect with you.`));

const form = useForm({
    first_name: props.suggestedName.first,
    last_name: props.suggestedName.last,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('portal-invitation.store', props.token), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <PublicLayout>
        <Head :title="title">
            <meta name="robots" content="noindex, nofollow" />
        </Head>

        <AuthFrame :title="title">
            <p class="mt-2 text-sm text-ink-600">{{ intro }}</p>

            <template v-if="mode === 'new'">
                <p class="mt-4 text-sm text-ink-600">
                    Create your account for <strong class="font-semibold text-ink-900">{{ email }}</strong>. Following the link in your email already confirmed this address.
                </p>

                <form class="mt-6" @submit.prevent="submit">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField id="first_name" label="First name" :error="form.errors.first_name" v-slot="{ id, describedBy, invalid }">
                            <TextInput :id="id" v-model="form.first_name" required autocomplete="given-name" :aria-describedby="describedBy" :aria-invalid="invalid" />
                        </FormField>
                        <FormField id="last_name" label="Last name" :error="form.errors.last_name" v-slot="{ id, describedBy, invalid }">
                            <TextInput :id="id" v-model="form.last_name" required autocomplete="family-name" :aria-describedby="describedBy" :aria-invalid="invalid" />
                        </FormField>
                    </div>

                    <FormField id="password" label="Choose a password" :error="form.errors.password" class="mt-4" v-slot="{ id, describedBy, invalid }">
                        <TextInput :id="id" type="password" v-model="form.password" required autocomplete="new-password" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>

                    <FormField id="password_confirmation" label="Confirm password" :error="form.errors.password_confirmation" class="mt-4" v-slot="{ id, describedBy, invalid }">
                        <TextInput :id="id" type="password" v-model="form.password_confirmation" required autocomplete="new-password" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>

                    <p v-if="form.errors.email" class="mt-4 text-sm text-red-700" role="alert">{{ form.errors.email }}</p>

                    <div class="mt-6">
                        <Button type="submit" class="w-full justify-center" :disabled="form.processing">
                            Create account &amp; continue
                        </Button>
                    </div>
                </form>
            </template>

            <template v-else-if="mode === 'login'">
                <p class="mt-4 text-sm text-ink-600">
                    You already have an account with <strong class="font-semibold text-ink-900">{{ email }}</strong>. Log in with it and you'll come straight back here to accept.
                </p>
                <div class="mt-6">
                    <Button :href="route('login')" class="w-full justify-center">Log in to accept</Button>
                </div>
                <p class="mt-4 text-sm text-ink-600">
                    Forgotten your password?
                    <Link :href="route('password.request')" class="link">Reset it</Link>, then open this invitation again.
                </p>
            </template>

            <template v-else-if="mode === 'confirm'">
                <p class="mt-4 text-sm text-ink-600">
                    You're signed in as <strong class="font-semibold text-ink-900">{{ email }}</strong>. Accept to add
                    {{ kind === 'speaker' ? 'the speaker portal' : `${subjectName}'s partner portal` }} to your account.
                </p>
                <p v-if="form.errors.email" class="mt-4 text-sm text-red-700" role="alert">{{ form.errors.email }}</p>
                <div class="mt-6">
                    <Button class="w-full justify-center" :disabled="form.processing" @click="submit">Accept invitation</Button>
                </div>
            </template>

            <template v-else>
                <p class="mt-4 text-sm text-ink-600" role="alert">
                    This invitation was sent to <strong class="font-semibold text-ink-900">{{ email }}</strong>, but you're signed in with a different account.
                    Log out, then open the link from your email again.
                </p>
                <div class="mt-6">
                    <Button :href="route('logout')" method="post" as="button" variant="secondary" class="w-full justify-center">Log out</Button>
                </div>
            </template>
        </AuthFrame>
    </PublicLayout>
</template>
