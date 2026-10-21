<script setup>
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import PortalPage from '@/Components/PortalPage.vue';
import TextInput from '@/Components/TextInput.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { clock, shortDate } from '@/lib/eventFormat';
import { speakerNav } from '@/lib/portalNav';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    event: { type: Object, required: true },
    sessions: { type: Array, default: () => [] },
    categories: { type: Array, required: true },
    resources: { type: Array, default: () => [] },
});

const kind = ref('file'); // file | link

const form = useForm({
    title: '',
    description: '',
    category: 'after_event',
    file: null,
    external_url: '',
    release: 'now',
});

const fileInput = ref(null);

function submit() {
    form.transform((data) => ({
        ...data,
        file: kind.value === 'file' ? data.file : null,
        external_url: kind.value === 'link' ? data.external_url : '',
    })).post(route('speaker.events.resources.store', props.event.id), {
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
    router.delete(route('speaker.events.resources.destroy', [props.event.id, toRemove.value.id]), {
        preserveScroll: true,
        onFinish: () => { toRemove.value = null; },
    });
}

const releaseLabel = (r) => {
    if (!r.is_published) return { variant: 'danger', label: 'Hidden by the organisers' };
    if (r.is_available) return { variant: 'success', label: 'Available to attendees' };
    return { variant: 'info', label: r.release_at ? `Available from ${shortDate(r.release_at)}, ${clock(r.release_at)}` : 'Not available yet' };
};
</script>

<template>
    <PortalLayout area="Speaker portal" current="speaker" :home-href="route('speaker.dashboard')" :nav="speakerNav()">
        <Head :title="`Resources · ${event.name}`" />

        <PortalPage :eyebrow="event.name" title="Share resources">
            <p class="max-w-prose text-[0.9375rem] text-ink-600">
                Slides, papers or links you'd like attendees to have. They appear on the attendee Resources page with your name.
                Our team can hide anything that shouldn't be there.
            </p>

            <section aria-labelledby="shared-title" class="mt-10">
                <h2 id="shared-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">
                    What you've shared <span class="tnum text-ink-500">({{ resources.length }})</span>
                </h2>
                <ul v-if="resources.length" class="divide-y divide-rule">
                    <li v-for="r in resources" :key="r.id" class="flex flex-wrap items-start justify-between gap-4 py-4">
                        <div class="min-w-0">
                            <p class="font-medium text-ink-900">{{ r.title }}</p>
                            <p class="mt-0.5 text-sm text-ink-500">
                                {{ r.is_external ? 'Link' : r.file_name }} · {{ r.category_label }} · {{ r.views_count }} {{ r.views_count === 1 ? 'open' : 'opens' }}
                            </p>
                            <p class="mt-1.5"><Badge :variant="releaseLabel(r).variant">{{ releaseLabel(r).label }}</Badge></p>
                        </div>
                        <button type="button" class="link text-sm text-red-700" @click="toRemove = r">Remove</button>
                    </li>
                </ul>
                <p v-else class="py-6 text-sm text-ink-600">Nothing shared yet.</p>
            </section>

            <section aria-labelledby="add-title" class="mt-12">
                <h2 id="add-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">Add a resource</h2>

                <form class="mt-6 space-y-5" @submit.prevent="submit">
                    <FormField id="resource-title" label="Title" :error="form.errors.title" required v-slot="{ id, describedBy, invalid }">
                        <TextInput :id="id" v-model="form.title" required maxlength="255" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>

                    <FormField id="resource-description" label="Description" hint="Optional. One or two lines on what it is." :error="form.errors.description" v-slot="{ id, describedBy, invalid }">
                        <textarea :id="id" v-model="form.description" rows="3" maxlength="2000" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid"></textarea>
                    </FormField>

                    <fieldset>
                        <legend class="field-label">What are you sharing?</legend>
                        <div class="mt-2 flex gap-6 text-sm text-ink-800">
                            <label class="flex items-center gap-2"><input v-model="kind" type="radio" value="file" class="text-ink-900 focus:ring-signal-500" /> A file</label>
                            <label class="flex items-center gap-2"><input v-model="kind" type="radio" value="link" class="text-ink-900 focus:ring-signal-500" /> A link</label>
                        </div>
                    </fieldset>

                    <FormField
                        v-if="kind === 'file'"
                        id="resource-file"
                        label="File"
                        hint="PDF, PowerPoint, Word, Excel, CSV, image, ZIP, MP4 or text, up to 20 MB."
                        :error="form.errors.file"
                        required
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <input
                            :id="id"
                            ref="fileInput"
                            type="file"
                            class="block w-full max-w-full text-sm text-ink-700"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            @input="form.file = $event.target.files[0]"
                        />
                    </FormField>
                    <FormField
                        v-else
                        id="resource-url"
                        label="Link"
                        hint="Starting with https://"
                        :error="form.errors.external_url || form.errors.file"
                        required
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <TextInput :id="id" v-model="form.external_url" type="url" required placeholder="https://" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <FormField id="resource-category" label="Where it fits" :error="form.errors.category" v-slot="{ id, describedBy, invalid }">
                            <select :id="id" v-model="form.category" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid">
                                <option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                            </select>
                        </FormField>
                        <FormField id="resource-release" label="When attendees can open it" :error="form.errors.release" v-slot="{ id, describedBy, invalid }">
                            <select :id="id" v-model="form.release" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid">
                                <option value="now">Now</option>
                                <option v-for="s in sessions" :key="s.id" :value="String(s.id)">After “{{ s.title }}” ends</option>
                            </select>
                        </FormField>
                    </div>

                    <div class="flex flex-wrap items-center gap-4 border-t border-rule pt-6">
                        <Button type="submit" :disabled="form.processing">Share with attendees</Button>
                        <Link :href="route('speaker.dashboard')" class="link text-sm">Back to your schedule</Link>
                    </div>
                </form>
            </section>
        </PortalPage>

        <Modal :show="!!toRemove" title="Remove this resource?" @close="toRemove = null">
            <p class="text-sm text-ink-600">
                Attendees will no longer be able to open <strong>{{ toRemove?.title }}</strong>. Anyone who already downloaded it keeps their copy.
            </p>
            <template #footer>
                <Button variant="secondary" @click="toRemove = null">Cancel</Button>
                <Button variant="danger" @click="remove">Remove</Button>
            </template>
        </Modal>
    </PortalLayout>
</template>
