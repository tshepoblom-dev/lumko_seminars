<script setup>
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PortalPage from '@/Components/PortalPage.vue';
import TextInput from '@/Components/TextInput.vue';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    partner: Object,
    event: Object,
    tier: { type: String, default: null },
    features: { type: Array, default: () => [] },
    resources: { type: Array, default: () => [] },
});

const kind = ref('file');
const fileInput = ref(null);
const form = useForm({ title: '', description: '', file: null, external_url: '' });

function submit() {
    form.transform((data) => ({
        ...data,
        file: kind.value === 'file' ? data.file : null,
        external_url: kind.value === 'link' ? data.external_url : '',
    })).post(route('partner.events.resources.store', [props.partner.slug, props.event.id]), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

const toRemove = ref(null);
function remove() {
    router.delete(route('partner.events.resources.destroy', [props.partner.slug, props.event.id, toRemove.value.id]), {
        preserveScroll: true,
        onFinish: () => { toRemove.value = null; },
    });
}
</script>

<template>
    <PartnerLayout :partner="partner" :event="event" :features="features">
        <Head :title="`Resources · ${event.name}`" />

        <PortalPage :eyebrow="event.name" title="Resources and offers">
            <p class="max-w-prose text-[0.9375rem] text-ink-600">
                Brochures, case studies, offers or links for attendees. They appear on your partner page and on the attendee Resources page under your name.
            </p>

            <ul v-if="resources.length" class="mt-8 divide-y divide-rule border-y border-ink-900">
                <li v-for="r in resources" :key="r.id" class="flex flex-wrap items-start justify-between gap-4 py-4">
                    <div class="min-w-0">
                        <p class="font-medium text-ink-900">{{ r.title }}</p>
                        <p class="mt-0.5 text-sm text-ink-500">{{ r.is_external ? 'Link' : r.file_name }} · {{ r.views_count }} {{ r.views_count === 1 ? 'open' : 'opens' }}</p>
                        <p class="mt-1.5"><Badge :variant="r.is_published ? 'success' : 'danger'">{{ r.is_published ? 'Available to attendees' : 'Hidden by the organisers' }}</Badge></p>
                    </div>
                    <button type="button" class="link text-sm text-red-700" @click="toRemove = r">Remove</button>
                </li>
            </ul>
            <p v-else class="mt-8 border-t border-ink-900 py-6 text-sm text-ink-600">Nothing shared yet.</p>

            <form class="mt-12 space-y-5" @submit.prevent="submit">
                <h2 class="border-b border-ink-900 pb-3 text-xl text-ink-900">Add a resource</h2>
                <FormField id="pr-title" label="Title" :error="form.errors.title" required v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.title" required maxlength="255" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
                <FormField id="pr-description" label="Description" hint="Optional" :error="form.errors.description" v-slot="{ id, describedBy, invalid }">
                    <textarea :id="id" v-model="form.description" rows="3" maxlength="2000" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid"></textarea>
                </FormField>
                <fieldset>
                    <legend class="field-label">What are you sharing?</legend>
                    <div class="mt-2 flex gap-6 text-sm text-ink-800">
                        <label class="flex items-center gap-2"><input v-model="kind" type="radio" value="file" class="text-ink-900 focus:ring-signal-500" /> A file</label>
                        <label class="flex items-center gap-2"><input v-model="kind" type="radio" value="link" class="text-ink-900 focus:ring-signal-500" /> A link</label>
                    </div>
                </fieldset>
                <FormField v-if="kind === 'file'" id="pr-file" label="File" hint="PDF, PowerPoint, Word, Excel, image, ZIP or MP4, up to 20 MB." :error="form.errors.file" required v-slot="{ id, describedBy, invalid }">
                    <input :id="id" ref="fileInput" type="file" class="block w-full max-w-full text-sm text-ink-700" :aria-describedby="describedBy" :aria-invalid="invalid" @input="form.file = $event.target.files[0]" />
                </FormField>
                <FormField v-else id="pr-url" label="Link" hint="Starting with https://" :error="form.errors.external_url || form.errors.file" required v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.external_url" type="url" required placeholder="https://" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
                <div class="border-t border-rule pt-6">
                    <Button type="submit" :disabled="form.processing">Share with attendees</Button>
                </div>
            </form>
        </PortalPage>

        <Modal :show="!!toRemove" title="Remove this resource?" @close="toRemove = null">
            <p class="text-sm text-ink-600">Attendees will no longer be able to open <strong>{{ toRemove?.title }}</strong>.</p>
            <template #footer>
                <Button variant="secondary" @click="toRemove = null">Cancel</Button>
                <Button variant="danger" @click="remove">Remove</Button>
            </template>
        </Modal>
    </PartnerLayout>
</template>
