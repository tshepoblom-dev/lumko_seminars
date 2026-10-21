<script setup>
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import TextInput from '@/Components/TextInput.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Link } from '@inertiajs/vue3';

// Create and Edit share this form. The event comes from the workspace URL;
// speakers are picked from the ones already linked to the event.
const props = defineProps({
    form: { type: Object, required: true },
    speakers: { type: Array, default: () => [] },
    submitLabel: { type: String, default: 'Save session' },
});

defineEmits(['submit']);

const eventRoute = useEventRoute();

function toggleSpeaker(id, checked) {
    const ids = props.form.speaker_ids.filter((existing) => existing !== id);
    props.form.speaker_ids = checked ? [...ids, id] : ids;
}

// Errors on individual speaker_ids.N entries, shown under the list.
const speakerError = () =>
    props.form.errors.speaker_ids ??
    Object.entries(props.form.errors).find(([key]) => key.startsWith('speaker_ids.'))?.[1] ??
    null;
</script>

<template>
    <form class="space-y-5 rounded-lg bg-white p-6 shadow" @submit.prevent="$emit('submit')">
        <FormField id="session-title" label="Title" :error="form.errors.title" required v-slot="{ id, describedBy }">
            <TextInput :id="id" v-model="form.title" type="text" :aria-describedby="describedBy" class="w-full" />
        </FormField>

        <FormField id="session-description" label="Description" :error="form.errors.description" v-slot="{ id, describedBy }">
            <textarea :id="id" v-model="form.description" rows="3" :aria-describedby="describedBy" class="field w-full"></textarea>
        </FormField>

        <fieldset>
            <legend class="field-label mb-1.5">Speakers</legend>
            <p v-if="!speakers.length" class="field-hint">
                No speakers are linked to this event yet.
                <Link :href="eventRoute('admin.events.speakers.index')" class="text-brand underline">Add speakers to this event</Link>
                first, then pick them here.
            </p>
            <ul v-else class="divide-y divide-gray-100 rounded border border-gray-200" aria-describedby="session-speakers-hint">
                <li v-for="speaker in speakers" :key="speaker.id">
                    <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-gray-50">
                        <input
                            type="checkbox"
                            class="rounded border-gray-300 text-brand focus:ring-brand"
                            :checked="form.speaker_ids.includes(speaker.id)"
                            @change="toggleSpeaker(speaker.id, $event.target.checked)"
                        />
                        <span>
                            <span class="font-medium text-gray-900">{{ speaker.name }}</span>
                            <span v-if="speaker.organisation" class="text-gray-600"> · {{ speaker.organisation }}</span>
                        </span>
                    </label>
                </li>
            </ul>
            <p v-if="speakers.length && !speakerError()" id="session-speakers-hint" class="field-hint">
                Optional. Tick everyone presenting; panels can have several. Only speakers linked to this event are listed.
            </p>
            <p v-if="speakerError()" role="alert" class="field-error">{{ speakerError() }}</p>
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
            <FormField id="session-start" label="Start time" :error="form.errors.start_time" required v-slot="{ id, describedBy }">
                <TextInput :id="id" v-model="form.start_time" type="datetime-local" :aria-describedby="describedBy" class="w-full" />
            </FormField>
            <FormField id="session-end" label="End time" :error="form.errors.end_time" required v-slot="{ id, describedBy }">
                <TextInput :id="id" v-model="form.end_time" type="datetime-local" :aria-describedby="describedBy" class="w-full" />
            </FormField>
        </div>

        <FormField
            id="session-sort"
            label="Position in programme"
            hint="Lower numbers are listed first."
            :error="form.errors.sort_order"
            required
            v-slot="{ id, describedBy }"
        >
            <TextInput :id="id" v-model="form.sort_order" type="number" min="0" :aria-describedby="describedBy" class="w-32" />
        </FormField>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing">{{ submitLabel }}</Button>
            <Button variant="secondary" :href="eventRoute('admin.programme-sessions.index')">Cancel</Button>
        </div>
    </form>
</template>
