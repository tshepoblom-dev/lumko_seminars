<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { ref } from 'vue';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import PortalPage from '@/Components/PortalPage.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';

const props = defineProps({
    profile: Object,
    consents: Object,
    hasRegistration: Boolean,
    // [{ id, speaker, organisation, event, since }]
    speakerConnections: { type: Array, default: () => [] },
    // [{ id, partner, event, how, since }]
    partnerConnections: { type: Array, default: () => [] },
});

// { kind: 'speaker' | 'partner', id, name }
const withdrawing = ref(null);

function withdrawConnection() {
    const name = withdrawing.value.kind === 'partner' ? 'attendee.partner-connections.destroy' : 'attendee.speaker-connections.destroy';
    router.delete(route(name, withdrawing.value.id), {
        preserveScroll: true,
        onFinish: () => { withdrawing.value = null; },
    });
}

const form = useForm({
    first_name: props.profile.first_name,
    last_name: props.profile.last_name,
    mobile: props.profile.mobile,
    organisation: props.profile.organisation,
    job_title: props.profile.job_title,
    networking_consent: props.consents.networking,
    lumko_marketing_consent: props.consents.lumko_marketing,
});

function submit() {
    form.put(route('attendee.preferences.update'));
}

const showDeleteConfirm = ref(false);
const deleteForm = useForm({ password: '' });

function destroyAccount() {
    deleteForm.delete(route('attendee.preferences.destroy'), {
        preserveScroll: true,
        onSuccess: () => { showDeleteConfirm.value = false; },
    });
}
</script>

<template>
    <AttendeeLayout>
    <Head title="Account & preferences" />

    <PortalPage eyebrow="Your Lumko account" title="Account & preferences">
        <form class="space-y-6" @submit.prevent="submit">
            <h2 class="text-lg text-ink-900">Your details</h2>
            <div class="grid gap-6 sm:grid-cols-2">
                <FormField id="public-preferences-1" label="First name" :error="form.errors.first_name" v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.first_name" type="text" autocomplete="given-name" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
                <FormField id="public-preferences-2" label="Last name" :error="form.errors.last_name" v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.last_name" type="text" autocomplete="family-name" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
            </div>
            <FormField id="public-preferences-3" label="Email" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" :model-value="profile.email" type="email" disabled :aria-describedby="describedBy" />
            </FormField>
            <FormField id="public-preferences-4" label="Mobile" hint="Optional" :error="form.errors.mobile" v-slot="{ id, describedBy, invalid }">
                <TextInput :id="id" v-model="form.mobile" type="text" inputmode="tel" autocomplete="tel" :aria-describedby="describedBy" :aria-invalid="invalid" />
            </FormField>
            <div class="grid gap-6 sm:grid-cols-2">
                <FormField id="public-preferences-5" label="Organisation" :error="form.errors.organisation" v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.organisation" type="text" autocomplete="organization" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
                <FormField id="public-preferences-6" label="Job title" :error="form.errors.job_title" v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.job_title" type="text" autocomplete="organization-title" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
            </div>

            <div v-if="hasRegistration" class="space-y-2 border-t border-rule pt-8">
                <h2 class="mb-4 text-lg text-ink-900">Preferences</h2>
                <label class="choice text-sm">
                    <Checkbox v-model:checked="form.networking_consent" />
                    <span>Make my profile visible to other attendees.</span>
                </label>
                <label class="choice text-sm">
                    <Checkbox v-model:checked="form.lumko_marketing_consent" />
                    <span>Lumko may share relevant technology insights, future events or service information with me.</span>
                </label>
            </div>
            <p v-else class="text-sm text-ink-500">
                Register for an event to set networking and communication preferences.
            </p>

            <Button type="submit" :disabled="form.processing">
                Save changes
            </Button>
        </form>

        <section class="mt-16 border-t border-ink-900 pt-6" aria-labelledby="speaker-connections-title">
            <h2 id="speaker-connections-title" class="text-lg text-ink-900">Speakers you've connected with</h2>
            <p class="mt-1 text-sm text-ink-600">
                These speakers receive your name, email, organisation and job title because you asked them to.
                Withdraw and they no longer see your details.
            </p>
            <ul v-if="speakerConnections.length" class="mt-4 border-t border-rule">
                <li v-for="c in speakerConnections" :key="c.id" class="flex flex-wrap items-center justify-between gap-3 border-b border-rule py-3.5">
                    <span class="min-w-0 text-sm">
                        <span class="block font-medium text-ink-900">{{ c.speaker }}<template v-if="c.organisation"> &middot; {{ c.organisation }}</template></span>
                        <span class="block text-meta text-ink-500">{{ c.event }} &middot; since {{ c.since }}</span>
                    </span>
                    <Button size="sm" variant="secondary" :aria-label="`Withdraw from ${c.speaker}`" @click="withdrawing = { kind: 'speaker', id: c.id, name: c.speaker }">Withdraw</Button>
                </li>
            </ul>
            <p v-else class="mt-4 text-sm text-ink-500">You haven't connected with any speakers.</p>
        </section>

        <section class="mt-16 border-t border-ink-900 pt-6" aria-labelledby="partner-connections-title">
            <h2 id="partner-connections-title" class="text-lg text-ink-900">Partners you've shared your details with</h2>
            <p class="mt-1 text-sm text-ink-600">
                These event partners receive your name, email, organisation and job title because you chose to share them.
                Withdraw and they no longer see your details in their portal.
            </p>
            <ul v-if="partnerConnections.length" class="mt-4 border-t border-rule">
                <li v-for="c in partnerConnections" :key="c.id" class="flex flex-wrap items-center justify-between gap-3 border-b border-rule py-3.5">
                    <span class="min-w-0 text-sm">
                        <span class="block font-medium text-ink-900">{{ c.partner }}</span>
                        <span class="block text-meta text-ink-500">{{ c.event }} &middot; {{ c.how }} &middot; since {{ c.since }}</span>
                    </span>
                    <Button size="sm" variant="secondary" :aria-label="`Withdraw from ${c.partner}`" @click="withdrawing = { kind: 'partner', id: c.id, name: c.partner }">Withdraw</Button>
                </li>
            </ul>
            <p v-else class="mt-4 text-sm text-ink-500">You haven't shared your details with any partners.</p>
        </section>

        <section class="mt-16 grid gap-3 border-t border-ink-900 pt-6 sm:grid-cols-[1fr_auto] sm:items-start sm:gap-8">
            <div>
                <h2 class="text-lg text-ink-900">Your data</h2>
                <p class="mt-1 text-sm text-ink-600">
                    Download everything we hold about you — profile, registrations, payments, consent history,
                    feedback, questions and networking connections — as a single file.
                </p>
            </div>
            <Button :href="route('attendee.preferences.download')" native variant="secondary">Download my data</Button>
        </section>

        <section class="mt-12 border-t border-rule pt-6">
            <h2 class="text-lg text-red-800">Delete account</h2>
            <p class="mt-1 text-sm text-ink-600">
                Your name, email, mobile and organisation are removed. Financial and consent records are kept
                without any personal information, as required for compliance. This cannot be undone.
            </p>

            <Button
                v-if="!showDeleteConfirm"
                variant="secondary"
                class="mt-4 !border-red-300 !text-red-800 hover:!border-red-700"
                @click="showDeleteConfirm = true"
            >
                Delete my account
            </Button>

            <form v-else class="mt-4 space-y-3 border-t-2 border-red-700 pt-4" @submit.prevent="destroyAccount">
                <FormField id="public-preferences-7" label="Confirm your password" :error="deleteForm.errors.password" class="max-w-xs" v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="deleteForm.password" type="password" autocomplete="current-password" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
                <div class="flex flex-wrap gap-2 pt-1">
                    <Button type="submit" variant="danger" :disabled="deleteForm.processing">
                        Permanently delete my account
                    </Button>
                    <Button variant="secondary" @click="showDeleteConfirm = false">
                        Cancel
                    </Button>
                </div>
            </form>
        </section>
    </PortalPage>

    <Modal :show="!!withdrawing" :title="withdrawing?.kind === 'partner' ? 'Withdraw from this partner?' : 'Withdraw from this speaker?'" @close="withdrawing = null">
        <p class="text-sm text-ink-800">
            {{ withdrawing?.name }} will no longer see your details. If they've already contacted you or saved
            your details, ask them directly to delete them.
        </p>
        <template #footer>
            <Button variant="secondary" @click="withdrawing = null">Cancel</Button>
            <Button @click="withdrawConnection">Withdraw</Button>
        </template>
    </Modal>
    </AttendeeLayout>
</template>
