<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import EventImageField from '@/Components/EventImageField.vue';
import { toLocalInput } from '@/lib/eventFormat';

const props = defineProps({
    event: Object,
});

const form = useForm({
    name: props.event.name,
    subtitle: props.event.subtitle,
    slug: props.event.slug,
    description: props.event.description,
    venue_name: props.event.venue_name,
    venue_address: props.event.venue_address,
    starts_at: toLocalInput(props.event.starts_at),
    ends_at: toLocalInput(props.event.ends_at),
    online_price: props.event.online_price,
    in_person_price: props.event.in_person_price,
    offers_online: props.event.offers_online === true,
    status: props.event.status,
    hero_image: null,
    hero_image_alt: props.event.hero_image_alt ?? '',
    remove_hero_image: false,
    venue_image: null,
    venue_image_alt: props.event.venue_image_alt ?? '',
    remove_venue_image: false,
    // Files need a multipart POST; Laravel reads _method as PUT.
    _method: 'put',
});

function submit() {
    form.post(route('admin.events.update', props.event.id), { forceFormData: true });
}

// Livestream settings save independently from the rest of the event form.
const liveForm = useForm({
    livestream_provider: props.event.livestream_provider,
    livestream_url: props.event.livestream_url,
    livestream_channel_name: props.event.livestream_channel_name,
    livestream_secondary_provider: props.event.livestream_secondary_provider,
    livestream_secondary_url: props.event.livestream_secondary_url,
    recording_url: props.event.recording_url,
    show_reactions_to_attendees: props.event.show_reactions_to_attendees ?? false,
    live_lead_minutes: props.event.live_lead_minutes ?? 15,
});

function saveLivestream() {
    liveForm.put(route('admin.events.livestream.update', props.event.id));
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Edit event</h2>
        </template>

        <Head title="Edit event" />

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium" for="admin-events-edit-1">Name</label>
                        <input id="admin-events-edit-1" v-model="form.name" type="text" class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-events-edit-2">Subtitle</label>
                        <input id="admin-events-edit-2" v-model="form.subtitle" type="text" class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-events-edit-3">Slug</label>
                        <input id="admin-events-edit-3" v-model="form.slug" type="text" class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-events-edit-4">Description</label>
                        <textarea id="admin-events-edit-4" v-model="form.description" class="mt-1 w-full rounded border-gray-300"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium" for="admin-events-edit-5">Venue name</label>
                            <input id="admin-events-edit-5" v-model="form.venue_name" type="text" class="mt-1 w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium" for="admin-events-edit-6">Venue address</label>
                            <input id="admin-events-edit-6" v-model="form.venue_address" type="text" class="mt-1 w-full rounded border-gray-300" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium" for="admin-events-edit-7">Starts at</label>
                            <input id="admin-events-edit-7" v-model="form.starts_at" type="datetime-local" class="mt-1 w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium" for="admin-events-edit-8">Ends at</label>
                            <input id="admin-events-edit-8" v-model="form.ends_at" type="datetime-local" class="mt-1 w-full rounded border-gray-300" />
                        </div>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 text-sm font-medium">
                            <input v-model="form.offers_online" type="checkbox" class="rounded border-gray-300" aria-describedby="admin-events-edit-online-hint" />
                            Offer online attendance
                        </label>
                        <p id="admin-events-edit-online-hint" class="mt-1 text-xs text-gray-600">
                            Tick to let people register to attend online. Unticked, registration offers in person only and the site doesn't mention online; people already registered online keep their registration and can switch to in person from their dashboard.
                        </p>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div v-if="form.offers_online">
                            <label class="block text-sm font-medium" for="admin-events-edit-9">Online price</label>
                            <input id="admin-events-edit-9" v-model="form.online_price" type="number" step="0.01" class="mt-1 w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium" for="admin-events-edit-10">In-person price</label>
                            <input id="admin-events-edit-10" v-model="form.in_person_price" type="number" step="0.01" class="mt-1 w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium" for="admin-events-edit-11">Status</label>
                            <select id="admin-events-edit-11" v-model="form.status" class="mt-1 w-full rounded border-gray-300">
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>
                    <fieldset class="space-y-6 border-t border-rule pt-6">
                        <legend class="text-sm font-semibold text-ink-800">Photography</legend>
                        <p class="text-sm text-ink-500">Shown on the public event page. Landscape photos work best, at least 2400 px wide (JPG, PNG or WebP, max 5 MB).</p>
                        <EventImageField :form="form" kind="hero" label="Hero photo" hint="Beside the event title at the top of the page." :current-path="event.hero_image_path" />
                        <EventImageField :form="form" kind="venue" label="Venue photo" hint="The full-width band in the Venue section." :current-path="event.venue_image_path" />
                    </fieldset>
                    <button
                        type="submit"
                        class="rounded bg-ink-900 px-4 py-2 text-sm text-white"
                        :disabled="form.processing"
                    >
                        Update event
                    </button>

                    <div class="border-t border-gray-100 pt-4">
                        <p class="text-sm font-semibold text-gray-500">Livestream</p>
                        <div class="mt-2 grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium" for="admin-events-edit-12">Provider</label>
                                <select id="admin-events-edit-12" v-model="liveForm.livestream_provider" class="mt-1 w-full rounded border-gray-300">
                                    <option :value="null">None</option>
                                    <option value="youtube">YouTube</option>
                                    <option value="facebook">Facebook Live</option>
                                    <option value="agora">Agora (interactive)</option>
                                </select>
                            </div>
                            <div v-if="liveForm.livestream_provider === 'youtube' || liveForm.livestream_provider === 'facebook'">
                                <label class="block text-sm font-medium" for="admin-events-edit-13">Stream URL</label>
                                <input id="admin-events-edit-13" v-model="liveForm.livestream_url" type="url" class="mt-1 w-full rounded border-gray-300" />
                            </div>
                            <div v-if="liveForm.livestream_provider === 'agora'">
                                <label class="block text-sm font-medium" for="admin-events-edit-14">Agora channel name</label>
                                <input id="admin-events-edit-14" v-model="liveForm.livestream_channel_name" type="text" class="mt-1 w-full rounded border-gray-300" />
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium" for="admin-events-edit-15">Fallback provider</label>
                                <select id="admin-events-edit-15" v-model="liveForm.livestream_secondary_provider" class="mt-1 w-full rounded border-gray-300">
                                    <option :value="null">None</option>
                                    <option value="youtube">YouTube</option>
                                    <option value="facebook">Facebook Live</option>
                                </select>
                            </div>
                            <div v-if="liveForm.livestream_secondary_provider">
                                <label class="block text-sm font-medium" for="admin-events-edit-16">Fallback URL</label>
                                <input id="admin-events-edit-16" v-model="liveForm.livestream_secondary_url" type="url" class="mt-1 w-full rounded border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium" for="admin-events-edit-17">Join window (minutes before start)</label>
                                <input id="admin-events-edit-17" v-model.number="liveForm.live_lead_minutes" type="number" min="0" max="180" class="mt-1 w-full rounded border-gray-300" />
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="block text-sm font-medium" for="admin-events-edit-18">Recording URL (post-event)</label>
                            <input id="admin-events-edit-18" v-model="liveForm.recording_url" type="url" class="mt-1 w-full rounded border-gray-300" placeholder="Added after the event" />
                        </div>

                        <label class="mt-3 flex items-center gap-2 text-sm">
                            <input v-model="liveForm.show_reactions_to_attendees" type="checkbox" />
                            Show reaction counts to attendees (not just staff)
                        </label>

                        <p v-if="liveForm.errors.livestream_url || liveForm.errors.livestream_secondary_url" class="mt-2 text-sm text-red-600">
                            {{ liveForm.errors.livestream_url || liveForm.errors.livestream_secondary_url }}
                        </p>

                        <button
                            type="button"
                            class="mt-3 rounded bg-gray-700 px-4 py-2 text-sm text-white"
                            @click="saveLivestream"
                        >
                            Save livestream settings
                        </button>

                        <Link
                            v-if="event.livestream_provider === 'agora'"
                            :href="route('admin.live.broadcast.show', { event: event.id })"
                            class="ml-3 text-sm text-signal-700 underline"
                        >
                            Open broadcast console
                        </Link>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
