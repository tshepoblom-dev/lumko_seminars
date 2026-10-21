<script setup>
import Button from '@/Components/Button.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PortalPage from '@/Components/PortalPage.vue';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import { shortDate } from '@/lib/eventFormat';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

// A partner's page for one event's attendees. The two ways to become the
// partner's lead (Connect, an enquiry) both show the exact sentence the
// attendee agrees to; nothing is shared otherwise.
const props = defineProps({
    event: Object,
    partner: Object,
    offersConnect: Boolean,
    offersEnquiry: Boolean,
    canConnect: Boolean,
    connected: { type: Object, default: null },
    consentText: String,
    resources: { type: Array, default: () => [] },
});

const confirming = ref(false);
const connectForm = useForm({ note: '' });

function connect() {
    connectForm.post(route('partners.connect', [props.event.id, props.partner.slug]), {
        preserveScroll: true,
        onSuccess: () => { confirming.value = false; connectForm.reset(); },
    });
}

const enquiryForm = useForm({ message: '', consent: false });

function enquire() {
    enquiryForm.post(route('partners.enquire', [props.event.id, props.partner.slug]), {
        preserveScroll: true,
        onSuccess: () => enquiryForm.reset(),
    });
}
</script>

<template>
    <AttendeeLayout>
        <Head :title="partner.name" />

        <PortalPage :eyebrow="`${partner.tier ? partner.tier + ' partner' : 'Partner'} · ${event.name}`" :title="partner.name" width="wide">
            <template #aside>
                <img v-if="partner.logo_url" :src="partner.logo_url" :alt="partner.logo_alt || partner.name" class="ml-auto max-h-16 w-auto max-w-[14rem] object-contain" />
            </template>

            <div class="grid gap-12 lg:grid-cols-12">
                <div class="lg:col-span-7">
                    <p v-if="partner.blurb" class="text-lg leading-relaxed text-ink-900">{{ partner.blurb }}</p>
                    <p v-if="partner.description" class="mt-5 whitespace-pre-line text-[0.9375rem] leading-relaxed text-ink-600">{{ partner.description }}</p>
                    <p class="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                        <a v-if="partner.website" :href="partner.website" target="_blank" rel="noopener" class="link">Visit their website<span class="sr-only"> (opens in a new tab)</span></a>
                        <span v-if="partner.stand_label" class="text-ink-600">Find them at {{ partner.stand_label }}</span>
                    </p>

                    <section v-if="resources.length" class="mt-12" aria-labelledby="partner-resources-title">
                        <h2 id="partner-resources-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">From {{ partner.name }}</h2>
                        <ul class="divide-y divide-rule">
                            <li v-for="r in resources" :key="r.id" class="py-3.5">
                                <a :href="route('resources.show', r.id)" class="group block" :target="r.is_external ? '_blank' : undefined" :rel="r.is_external ? 'noopener' : undefined">
                                    <span class="font-medium text-ink-900 underline decoration-rule-strong underline-offset-4 group-hover:decoration-ink-900">{{ r.title }}</span>
                                    <span v-if="r.description" class="mt-1 block text-sm text-ink-600">{{ r.description }}</span>
                                </a>
                            </li>
                        </ul>
                    </section>
                </div>

                <aside class="space-y-10 lg:col-span-5">
                    <section v-if="connected" class="border-t-2 border-verified-500 bg-paper p-5" aria-labelledby="connected-title">
                        <h2 id="connected-title" class="font-semibold text-ink-900">You're connected</h2>
                        <p class="mt-1 text-sm text-ink-600">
                            Since {{ shortDate(connected.since) }}, {{ partner.name }} can see your name, email, organisation and job title.
                            You can withdraw any time from <Link :href="route('attendee.preferences.edit')" class="link">your account</Link>.
                        </p>
                    </section>

                    <section v-else-if="offersConnect && canConnect" class="border-t-2 border-ink-900 pt-4" aria-labelledby="connect-title">
                        <h2 id="connect-title" class="font-semibold text-ink-900">Want to hear from {{ partner.name }}?</h2>
                        <p class="mt-1 text-sm text-ink-600">Connect and they'll be able to contact you. Only partners you connect with get your details.</p>
                        <Button class="mt-4" @click="confirming = true">Connect with {{ partner.name }}</Button>
                    </section>

                    <section v-if="offersEnquiry && canConnect" class="border-t-2 border-ink-900 pt-4" aria-labelledby="enquiry-title">
                        <h2 id="enquiry-title" class="font-semibold text-ink-900">Ask {{ partner.name }} a question</h2>
                        <form class="mt-3 space-y-4" @submit.prevent="enquire">
                            <FormField id="enquiry-message" label="Your message" :error="enquiryForm.errors.message" required v-slot="{ id, describedBy, invalid }">
                                <textarea :id="id" v-model="enquiryForm.message" rows="4" maxlength="1000" required class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid"></textarea>
                            </FormField>
                            <label class="flex items-start gap-3 text-sm text-ink-800">
                                <Checkbox v-model:checked="enquiryForm.consent" class="mt-0.5" />
                                <span>{{ consentText }}</span>
                            </label>
                            <p v-if="enquiryForm.errors.consent" class="field-error" role="alert">{{ enquiryForm.errors.consent }}</p>
                            <Button type="submit" :disabled="enquiryForm.processing">Send</Button>
                        </form>
                    </section>

                    <p v-if="!canConnect && !connected && (offersConnect || offersEnquiry)" class="text-sm text-ink-500">
                        Connecting with partners isn't available for this account or has closed for this event.
                    </p>
                </aside>
            </div>
        </PortalPage>

        <Modal :show="confirming" :title="`Connect with ${partner.name}?`" @close="confirming = false">
            <p class="text-sm text-ink-800">{{ consentText }}</p>
            <FormField id="connect-note" label="A note for them" hint="Optional, e.g. what you'd like to talk about." :error="connectForm.errors.note" class="mt-4" v-slot="{ id, describedBy, invalid }">
                <textarea :id="id" v-model="connectForm.note" rows="3" maxlength="500" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid"></textarea>
            </FormField>
            <template #footer>
                <Button variant="secondary" @click="confirming = false">Cancel</Button>
                <Button :disabled="connectForm.processing" @click="connect">Yes, share my details</Button>
            </template>
        </Modal>
    </AttendeeLayout>
</template>
