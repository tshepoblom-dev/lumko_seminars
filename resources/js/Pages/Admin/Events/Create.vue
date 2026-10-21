<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import EventImageField from '@/Components/EventImageField.vue';

const form = useForm({
    name: '',
    subtitle: '',
    slug: '',
    description: '',
    venue_name: '',
    venue_address: '',
    starts_at: '',
    ends_at: '',
    online_price: 0,
    in_person_price: 0,
    offers_online: true,
    status: 'draft',
    hero_image: null,
    hero_image_alt: '',
    venue_image: null,
    venue_image_alt: '',
});

function submit() {
    form.post(route('admin.events.store'), { forceFormData: true });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">New event</h2>
        </template>

        <Head title="New event" />

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-white p-6 shadow" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium" for="admin-events-create-1">Name</label>
                        <input id="admin-events-create-1" v-model="form.name" type="text" class="mt-1 w-full rounded border-gray-300" />
                        <p v-if="form.errors.name" class="text-sm text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-events-create-2">Subtitle</label>
                        <input id="admin-events-create-2" v-model="form.subtitle" type="text" class="mt-1 w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-events-create-3">Slug</label>
                        <input id="admin-events-create-3" v-model="form.slug" type="text" class="mt-1 w-full rounded border-gray-300" />
                        <p v-if="form.errors.slug" class="text-sm text-red-600">{{ form.errors.slug }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="admin-events-create-4">Description</label>
                        <textarea id="admin-events-create-4" v-model="form.description" class="mt-1 w-full rounded border-gray-300"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium" for="admin-events-create-5">Venue name</label>
                            <input id="admin-events-create-5" v-model="form.venue_name" type="text" class="mt-1 w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium" for="admin-events-create-6">Venue address</label>
                            <input id="admin-events-create-6" v-model="form.venue_address" type="text" class="mt-1 w-full rounded border-gray-300" />
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium" for="admin-events-create-7">Starts at</label>
                            <input id="admin-events-create-7" v-model="form.starts_at" type="datetime-local" class="mt-1 w-full rounded border-gray-300" />
                            <p v-if="form.errors.starts_at" class="text-sm text-red-600">{{ form.errors.starts_at }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium" for="admin-events-create-8">Ends at</label>
                            <input id="admin-events-create-8" v-model="form.ends_at" type="datetime-local" class="mt-1 w-full rounded border-gray-300" />
                            <p v-if="form.errors.ends_at" class="text-sm text-red-600">{{ form.errors.ends_at }}</p>
                        </div>
                    </div>
                    <div>
                        <label class="flex items-center gap-2 text-sm font-medium">
                            <input v-model="form.offers_online" type="checkbox" class="rounded border-gray-300" aria-describedby="admin-events-create-online-hint" />
                            Offer online attendance
                        </label>
                        <p id="admin-events-create-online-hint" class="mt-1 text-xs text-gray-600">
                            Off: registration offers in person only and the site doesn't mention online.
                        </p>
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div v-if="form.offers_online">
                            <label class="block text-sm font-medium" for="admin-events-create-9">Online price</label>
                            <input id="admin-events-create-9" v-model="form.online_price" type="number" step="0.01" class="mt-1 w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium" for="admin-events-create-10">In-person price</label>
                            <input id="admin-events-create-10" v-model="form.in_person_price" type="number" step="0.01" class="mt-1 w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium" for="admin-events-create-11">Status</label>
                            <select id="admin-events-create-11" v-model="form.status" class="mt-1 w-full rounded border-gray-300">
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                    </div>
                    <fieldset class="space-y-6 border-t border-rule pt-6">
                        <legend class="text-sm font-semibold text-ink-800">Photography</legend>
                        <p class="text-sm text-ink-500">Shown on the public event page. Landscape photos work best, at least 2400 px wide (JPG, PNG or WebP, max 5 MB). You can add them later.</p>
                        <EventImageField :form="form" kind="hero" label="Hero photo" hint="Beside the event title at the top of the page." />
                        <EventImageField :form="form" kind="venue" label="Venue photo" hint="The full-width band in the Venue section." />
                    </fieldset>
                    <button
                        type="submit"
                        class="rounded bg-ink-900 px-4 py-2 text-sm text-white"
                        :disabled="form.processing"
                    >
                        Save event
                    </button>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
