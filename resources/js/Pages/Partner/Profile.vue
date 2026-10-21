<script setup>
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import PortalPage from '@/Components/PortalPage.vue';
import TextInput from '@/Components/TextInput.vue';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    partner: { type: Object, required: true },
    involvements: { type: Array, default: () => [] }, // [{ id, event, blurb }]
});

const form = useForm({
    website: props.partner.website ?? '',
    description: props.partner.description ?? '',
    logo: null,
    logo_alt: props.partner.logo_alt ?? '',
    blurbs: Object.fromEntries(props.involvements.map((i) => [i.id, i.blurb ?? ''])),
});

function submit() {
    form.post(route('partner.profile.update', props.partner.slug), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset('logo'),
    });
}
</script>

<template>
    <PartnerLayout :partner="partner">
        <Head :title="`Profile · ${partner.name}`" />

        <PortalPage eyebrow="Partner portal" title="Your profile">
            <p class="max-w-prose text-[0.9375rem] text-ink-600">
                How attendees see {{ partner.name }} on the event site and your partner page. To change your organisation's name, ask our team.
            </p>

            <form class="mt-8 space-y-5" @submit.prevent="submit">
                <FormField id="partner-website" label="Website" :error="form.errors.website" v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.website" type="url" placeholder="https://" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
                <FormField id="partner-description" label="About you" hint="What you do and who you help, in a short paragraph or two." :error="form.errors.description" v-slot="{ id, describedBy, invalid }">
                    <textarea :id="id" v-model="form.description" rows="6" maxlength="5000" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid"></textarea>
                </FormField>

                <fieldset class="border-t border-rule pt-6">
                    <legend class="field-label">Logo</legend>
                    <div v-if="partner.logo_url" class="mt-3 flex items-center gap-4">
                        <img :src="partner.logo_url" :alt="partner.logo_alt ?? ''" class="max-h-16 w-auto max-w-[12rem] object-contain" />
                        <Badge :variant="partner.logo_approved ? 'success' : 'warning'">{{ partner.logo_approved ? 'Approved and on the site' : 'Awaiting approval' }}</Badge>
                    </div>
                    <FormField id="partner-logo" :label="partner.logo_url ? 'Replace logo' : 'Upload a logo'" hint="PNG with a transparent background works best. Up to 2 MB. Our team approves new logos before they appear." :error="form.errors.logo" class="mt-4" v-slot="{ id, describedBy, invalid }">
                        <input :id="id" type="file" accept="image/png,image/jpeg,image/webp" class="block w-full max-w-full text-sm text-ink-700" :aria-describedby="describedBy" :aria-invalid="invalid" @input="form.logo = $event.target.files[0]" />
                    </FormField>
                    <FormField v-if="form.logo" id="partner-logo-alt" label="Logo description" hint="Usually just your organisation's name." :error="form.errors.logo_alt" required class="mt-4" v-slot="{ id, describedBy, invalid }">
                        <TextInput :id="id" v-model="form.logo_alt" required :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>
                </fieldset>

                <fieldset v-if="involvements.length" class="space-y-5 border-t border-rule pt-6">
                    <legend class="field-label">For each event</legend>
                    <FormField
                        v-for="i in involvements"
                        :id="`blurb-${i.id}`"
                        :key="i.id"
                        :label="`Your message to ${i.event} attendees`"
                        hint="Optional. Shown on your partner page for that event, e.g. an offer or what to see at your stand."
                        :error="form.errors[`blurbs.${i.id}`]"
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <textarea :id="id" v-model="form.blurbs[i.id]" rows="3" maxlength="2000" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid"></textarea>
                    </FormField>
                </fieldset>

                <div class="border-t border-rule pt-6">
                    <Button type="submit" :disabled="form.processing">Save profile</Button>
                </div>
            </form>
        </PortalPage>
    </PartnerLayout>
</template>
