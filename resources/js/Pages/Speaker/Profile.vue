<script setup>
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FormField from '@/Components/FormField.vue';
import PortalPage from '@/Components/PortalPage.vue';
import TextInput from '@/Components/TextInput.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { speakerNav } from '@/lib/portalNav';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    speaker: { type: Object, required: true },
});

const form = useForm({
    name: props.speaker.name ?? '',
    title: props.speaker.title ?? '',
    organisation: props.speaker.organisation ?? '',
    bio: props.speaker.bio ?? '',
    photo: null,
    photo_alt: props.speaker.photo_alt ?? '',
    remove_photo: false,
});

function submit() {
    form.post(route('speaker.profile.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset('photo', 'remove_photo'),
    });
}
</script>

<template>
    <PortalLayout area="Speaker portal" current="speaker" :home-href="route('speaker.dashboard')" :nav="speakerNav()">
        <Head title="Your profile" />

        <PortalPage eyebrow="Speaker portal" title="Your profile">
            <p class="max-w-prose text-[0.9375rem] text-ink-600">
                This is how attendees see you on the event site and programme.
            </p>

            <form class="mt-8 space-y-5" @submit.prevent="submit">
                <FormField id="speaker-name" label="Name" :error="form.errors.name" required v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.name" required autocomplete="name" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
                <div class="grid gap-5 sm:grid-cols-2">
                    <FormField id="speaker-title" label="Title or role" :error="form.errors.title" v-slot="{ id, describedBy, invalid }">
                        <TextInput :id="id" v-model="form.title" autocomplete="organization-title" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>
                    <FormField id="speaker-organisation" label="Organisation" :error="form.errors.organisation" v-slot="{ id, describedBy, invalid }">
                        <TextInput :id="id" v-model="form.organisation" autocomplete="organization" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>
                </div>
                <FormField id="speaker-bio" label="Bio" hint="A short paragraph or two, in the third person." :error="form.errors.bio" v-slot="{ id, describedBy, invalid }">
                    <textarea :id="id" v-model="form.bio" rows="7" maxlength="5000" class="field w-full" :aria-describedby="describedBy" :aria-invalid="invalid"></textarea>
                </FormField>

                <fieldset class="border-t border-rule pt-6">
                    <legend class="field-label">Photo</legend>
                    <div v-if="speaker.photo_url" class="mt-3 flex items-center gap-4">
                        <img :src="speaker.photo_url" :alt="speaker.photo_alt ?? ''" class="h-20 w-20 object-cover" />
                        <div>
                            <Badge :variant="speaker.photo_approved ? 'success' : 'warning'">
                                {{ speaker.photo_approved ? 'Approved and on the site' : 'Awaiting approval' }}
                            </Badge>
                            <label class="mt-2 flex items-center gap-2 text-sm text-ink-700">
                                <Checkbox v-model:checked="form.remove_photo" />
                                Remove this photo
                            </label>
                        </div>
                    </div>

                    <FormField
                        id="speaker-photo"
                        :label="speaker.photo_url ? 'Replace photo' : 'Upload a photo'"
                        hint="JPG, PNG or WebP, up to 2 MB. Our team approves new photos before they appear on the site."
                        :error="form.errors.photo"
                        class="mt-4"
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <input
                            :id="id"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="block w-full max-w-full text-sm text-ink-700"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            @input="form.photo = $event.target.files[0]"
                        />
                    </FormField>

                    <FormField
                        v-if="form.photo || speaker.photo_url"
                        id="speaker-photo-alt"
                        label="Photo description"
                        hint="For people using screen readers, e.g. “Naledi Dube speaking at a lectern”."
                        :error="form.errors.photo_alt"
                        :required="!!form.photo"
                        class="mt-4"
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <TextInput :id="id" v-model="form.photo_alt" :required="!!form.photo" :aria-describedby="describedBy" :aria-invalid="invalid" />
                    </FormField>
                </fieldset>

                <div class="border-t border-rule pt-6">
                    <Button type="submit" :disabled="form.processing">Save profile</Button>
                </div>
            </form>
        </PortalPage>
    </PortalLayout>
</template>
