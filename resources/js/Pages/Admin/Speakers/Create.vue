<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    // Set when opened from an event's Speakers page: the new speaker is
    // linked straight back to that event.
    forEvent: { type: Object, default: null },
});

const form = useForm({
    for_event_id: props.forEvent?.id ?? null,
    name: '',
    title: '',
    organisation: '',
    contact_email: '',
    bio: '',
    photo: null,
    photo_alt: '',
    // Ticked by default; only offered (and only sent) once an email is typed.
    invite_to_portal: true,
});

function submit() {
    form.transform((data) => ({ ...data, invite_to_portal: data.invite_to_portal && data.contact_email.trim() !== '' }))
        .post(route('admin.speakers.store'), { forceFormData: true });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">New speaker</h2>
        </template>

        <Head title="New speaker" />

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <p v-if="forEvent" class="mb-4 rounded border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900">
                    This speaker is added to the shared library and linked to <strong>{{ forEvent.name }}</strong>.
                </p>
                <form class="space-y-4 rounded-lg bg-white p-6 shadow" @submit.prevent="submit">
                    <FormField id="name" label="Name" :error="form.errors.name" required v-slot="{ id, describedBy }">
                        <input :id="id" v-model="form.name" type="text" :aria-describedby="describedBy" class="w-full rounded border-gray-300" />
                    </FormField>

                    <FormField id="title" label="Title" v-slot="{ id }">
                        <input :id="id" v-model="form.title" type="text" class="w-full rounded border-gray-300" />
                    </FormField>

                    <FormField id="organisation" label="Organisation" v-slot="{ id }">
                        <input :id="id" v-model="form.organisation" type="text" class="w-full rounded border-gray-300" />
                    </FormField>

                    <FormField
                        id="contact_email"
                        label="Contact email"
                        hint="Where their speaker portal invitation and report link are sent. Never shown to attendees."
                        :error="form.errors.contact_email"
                        v-slot="{ id, describedBy, invalid }"
                    >
                        <input :id="id" v-model="form.contact_email" type="email" autocomplete="off" :aria-describedby="describedBy" :aria-invalid="invalid" class="w-full rounded border-gray-300" />
                    </FormField>

                    <label v-if="form.contact_email.trim()" class="flex items-start gap-2 text-sm text-gray-700">
                        <input v-model="form.invite_to_portal" type="checkbox" class="mt-0.5 rounded border-gray-300" />
                        <span>
                            Email them an invitation to the speaker portal
                            <span class="block text-xs text-gray-600">
                                They create their own account to see their schedule, share resources, test the livestream and more. The link works once, for 14 days.
                            </span>
                        </span>
                    </label>

                    <FormField id="bio" label="Bio" v-slot="{ id }">
                        <textarea :id="id" v-model="form.bio" class="w-full rounded border-gray-300"></textarea>
                    </FormField>

                    <FormField
                        id="photo"
                        label="Photo"
                        hint="Optional, JPG/PNG/WebP, max 2MB"
                        :error="form.errors.photo"
                        v-slot="{ id, describedBy }"
                    >
                        <input :id="id" type="file" class="block w-full max-w-full text-sm" accept="image/jpeg,image/png,image/webp" :aria-describedby="describedBy" @input="form.photo = $event.target.files[0]" />
                    </FormField>

                    <FormField
                        v-if="form.photo"
                        id="photo_alt"
                        label="Photo alt text"
                        hint="New photos require separate approval before they appear publicly."
                        :error="form.errors.photo_alt"
                        v-slot="{ id, describedBy }"
                    >
                        <input
                            :id="id"
                            v-model="form.photo_alt"
                            type="text"
                            placeholder="Describes the photo for screen readers"
                            :aria-describedby="describedBy"
                            class="w-full rounded border-gray-300"
                        />
                    </FormField>

                    <Button type="submit" :disabled="form.processing">Save speaker</Button>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
