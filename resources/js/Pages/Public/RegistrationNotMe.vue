<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Notice from '@/Components/Notice.vue';
import Button from '@/Components/Button.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

// "This wasn't me": the link in the email sent when a registration was
// added to an existing account by someone who wasn't signed in.
defineProps({
    state: { type: String, required: true }, // confirm | blocked | gone | cancelled
    registration: { type: Object, default: null }, // { event, attendance_mode, registered_on }
});

const page = usePage();
const cancelling = ref(false);

function cancel() {
    // The same signed address as this page: the signature covers both.
    router.post(page.url, {}, {
        onStart: () => { cancelling.value = true; },
        onFinish: () => { cancelling.value = false; },
    });
}
</script>

<template>
    <PublicLayout>
        <Head title="Cancel a registration" />

        <Notice v-if="state === 'confirm'" eyebrow="Not you?" title="Cancel this registration">
            <p>
                Your email address was registered for <strong>{{ registration.event }}</strong>
                ({{ registration.attendance_mode }}) on {{ registration.registered_on }}, by someone who wasn't signed in to your account.
            </p>
            <p>
                If that wasn't you, cancel it here. The registration and the answers given on the form are removed. Your account stays exactly as it was.
            </p>
            <template #actions>
                <Button :disabled="cancelling" @click="cancel">{{ cancelling ? 'Cancelling…' : 'Cancel this registration' }}</Button>
                <Button :href="route('login')" variant="secondary">It was me, log in</Button>
            </template>
        </Notice>

        <Notice v-else-if="state === 'blocked'" eyebrow="Not you?" title="We need to cancel this one for you">
            <p>
                The registration for <strong>{{ registration.event }}</strong> already has a payment or a check-in against it, so it can't be cancelled from this page.
            </p>
            <p>Reply to the email we sent you and we'll sort it out.</p>
            <template #actions>
                <Button href="/" variant="secondary">Back to the event page</Button>
            </template>
        </Notice>

        <Notice v-else-if="state === 'cancelled'" eyebrow="Cancelled" title="That registration is cancelled">
            <p>It has been removed, and your account is unchanged. You don't need to do anything else.</p>
            <template #actions>
                <Button href="/" variant="secondary">Back to the event page</Button>
            </template>
        </Notice>

        <Notice v-else eyebrow="Not you?" title="There's nothing to cancel">
            <p>This registration no longer exists. It may already have been cancelled.</p>
            <template #actions>
                <Button href="/" variant="secondary">Back to the event page</Button>
            </template>
        </Notice>
    </PublicLayout>
</template>
