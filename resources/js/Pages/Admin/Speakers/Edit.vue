<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Badge from '@/Components/Badge.vue';
import InvitePortalModal from '@/Components/Admin/InvitePortalModal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    speaker: Object,
    linkedEvents: { type: Array, default: () => [] },
    // Speaker portal: { status, account_email, invited_to, invited_on, expires_on, can_invite }
    portal: { type: Object, default: () => ({ status: 'not_invited', can_invite: false }) },
});

const inviting = ref(false);

function removeAccess() {
    if (!confirm(`Remove ${props.speaker.name}'s speaker portal access? Their account, resources and stage links are not affected.`)) return;
    router.delete(route('admin.speakers.portal-revoke', props.speaker.id), { preserveScroll: true });
}

const form = useForm({
    name: props.speaker.name,
    title: props.speaker.title,
    organisation: props.speaker.organisation,
    contact_email: props.speaker.contact_email ?? '',
    bio: props.speaker.bio,
    photo: null,
    photo_alt: props.speaker.photo_alt ?? '',
    photo_approved: props.speaker.photo_approved,
    remove_photo: false,
    _method: 'put',
});

function submit() {
    form.post(route('admin.speakers.update', props.speaker.id), { forceFormData: true });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Edit speaker</h2>
        </template>

        <Head title="Edit speaker" />

        <div class="py-12">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium" for="admin-speakers-edit-1">Name</label>
                        <input id="admin-speakers-edit-1" v-model="form.name" type="text" class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-speakers-edit-2">Title</label>
                        <input id="admin-speakers-edit-2" v-model="form.title" type="text" class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-speakers-edit-3">Organisation</label>
                        <input id="admin-speakers-edit-3" v-model="form.organisation" type="text" class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-speakers-edit-email">Contact email</label>
                        <input
                            id="admin-speakers-edit-email"
                            v-model="form.contact_email"
                            type="email"
                            autocomplete="off"
                            aria-describedby="admin-speakers-edit-email-hint"
                            :aria-invalid="!!form.errors.contact_email"
                            class="mt-1 w-full rounded border-gray-300"
                        />
                        <p id="admin-speakers-edit-email-hint" class="mt-1 text-xs text-gray-600">Where their speaker portal invitation and report link are sent. Never shown to attendees.</p>
                        <p v-if="form.errors.contact_email" role="alert" class="field-error">{{ form.errors.contact_email }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-speakers-edit-4">Bio</label>
                        <textarea id="admin-speakers-edit-4" v-model="form.bio" class="mt-1 w-full rounded border-gray-300"></textarea>
                    </div>

                    <div v-if="speaker.photo_path" class="flex items-center gap-3">
                        <img :src="`/storage/${speaker.photo_path}`" :alt="speaker.photo_alt ?? ''" class="h-16 w-16 rounded-full object-cover" />
                        <div class="text-sm">
                            <p :class="speaker.photo_approved ? 'text-green-700' : 'text-amber-700'">
                                {{ speaker.photo_approved ? 'Approved — visible publicly' : 'Not yet approved — hidden from public pages' }}
                            </p>
                            <label class="mt-1 flex items-center gap-1.5">
                                <input v-model="form.photo_approved" type="checkbox" class="rounded border-gray-300" /> Approve for public display
                            </label>
                            <label class="mt-1 flex items-center gap-1.5 text-red-600">
                                <input v-model="form.remove_photo" type="checkbox" class="rounded border-gray-300" /> Remove photo
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium" for="admin-speakers-edit-5">{{ speaker.photo_path ? 'Replace photo' : 'Photo' }} (optional, JPG/PNG/WebP, max 2MB)</label>
                        <input id="admin-speakers-edit-5" type="file" class="block w-full max-w-full text-sm" accept="image/jpeg,image/png,image/webp" @input="form.photo = $event.target.files[0]" />
                        <p v-if="form.errors.photo" class="text-sm text-red-600">{{ form.errors.photo }}</p>
                    </div>
                    <div v-if="form.photo">
                        <label class="block text-sm font-medium" for="admin-speakers-edit-6">Photo alt text</label>
                        <input id="admin-speakers-edit-6" v-model="form.photo_alt" type="text" placeholder="Describes the photo for screen readers" class="mt-1 w-full rounded border-gray-300" />
                        <p v-if="form.errors.photo_alt" class="text-sm text-red-600">{{ form.errors.photo_alt }}</p>
                        <p class="mt-1 text-xs text-gray-500">Uploading a new photo resets approval — it will need to be approved again.</p>
                    </div>

                    <button
                        type="submit"
                        class="rounded bg-ink-900 px-4 py-2 text-sm text-white"
                        :disabled="form.processing"
                    >
                        Update speaker
                    </button>
                </form>

                <section class="mt-6 rounded-lg bg-white p-6 shadow" aria-labelledby="speaker-portal-title">
                    <h3 id="speaker-portal-title" class="font-semibold text-gray-800">Speaker portal</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        Their own account: schedule, profile, slides and resources, a livestream test, their stage and who asked to connect.
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                        <Badge :variant="{ active: 'success', invited: 'warning', not_invited: 'neutral' }[portal.status]">
                            {{ { active: 'Has a speaker account', invited: 'Invited', not_invited: 'No speaker account yet' }[portal.status] }}
                        </Badge>
                        <span v-if="portal.status === 'active'" class="text-gray-700">Signs in as {{ portal.account_email }}</span>
                        <span v-else-if="portal.status === 'invited'" class="text-gray-700">
                            Sent {{ portal.invited_on }} to {{ portal.invited_to }}, works until {{ portal.expires_on }}
                        </span>
                    </div>
                    <p
                        v-if="portal.status === 'invited' && speaker.contact_email && portal.invited_to !== speaker.contact_email"
                        class="mt-2 rounded bg-amber-50 p-2 text-sm text-amber-900"
                        role="note"
                    >
                        The invitation went to {{ portal.invited_to }}, not their current contact email. Resend it to {{ speaker.contact_email }}.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-3 text-sm">
                        <button v-if="portal.can_invite" type="button" class="font-medium text-brand hover:underline" @click="inviting = true">
                            {{ portal.status === 'invited' ? 'Resend invitation' : 'Invite to the speaker portal' }}
                        </button>
                        <span v-else-if="portal.status !== 'active'" class="text-gray-600">Add a contact email above (and save) to invite them.</span>
                        <button v-if="portal.status === 'active'" type="button" class="text-red-700 hover:underline" @click="removeAccess">Remove portal access</button>
                    </div>
                </section>

                <InvitePortalModal
                    kind="speaker"
                    :show="inviting"
                    :action="route('admin.speakers.portal-invite', speaker.id)"
                    :recipients="[{ name: speaker.name, email: speaker.contact_email }]"
                    :resend="portal.status === 'invited'"
                    @close="inviting = false"
                />

                <section class="mt-6 rounded-lg bg-white p-6 shadow" aria-labelledby="speaker-linked-events">
                    <h3 id="speaker-linked-events" class="font-semibold text-gray-800">Linked to</h3>
                    <p v-if="!linkedEvents.length" class="mt-2 text-sm text-gray-600">
                        Not linked to any event yet. Add them from an event's Speakers page.
                    </p>
                    <ul v-else class="mt-3 space-y-3 text-sm">
                        <li v-for="e in linkedEvents" :key="e.id">
                            <Link :href="route('admin.events.speakers.index', { event: e.id })" class="font-medium text-brand hover:underline">{{ e.name }}</Link>
                            <p class="text-gray-700">
                                <template v-if="e.sessions.length">{{ e.sessions.join(', ') }}</template>
                                <span v-else class="text-gray-600">Not on a session yet</span>
                            </p>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
