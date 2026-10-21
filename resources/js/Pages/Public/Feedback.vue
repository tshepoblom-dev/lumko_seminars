<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import PortalPage from '@/Components/PortalPage.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Checkbox from '@/Components/Checkbox.vue';

const props = defineProps({
    event: Object,
    topicOptions: Array,
    alreadySubmitted: Boolean,
    isOpen: Boolean,
    // [{ id, name, organisation, connected, consent_text }]
    speakers: { type: Array, default: () => [] },
});

const form = useForm({
    rating: 3,
    biggest_takeaway: '',
    topics_next: [],
    wants_contact: false,
    contact_topics: [],
    speaker_ids: [],
});

const speakersToOffer = computed(() => props.speakers.filter((s) => !s.connected));
const alreadyConnected = computed(() => props.speakers.filter((s) => s.connected));

function submit() {
    form.post(route('feedback.store'));
}
</script>

<template>
    <AttendeeLayout>
    <Head title="Feedback" />

    <PortalPage eyebrow="After the event" :title="`How was ${event.name}?`">
        <p v-if="alreadySubmitted" class="mb-6 text-sm text-ink-500">
            You've already submitted feedback — resubmitting will update your response.
        </p>
        <p v-if="!isOpen" class="border border-rule border-t-2 border-t-amber-500 bg-white p-4 text-sm text-ink-800">
            Feedback isn't open yet — it opens once the event has ended.
        </p>

        <form v-else class="space-y-10" @submit.prevent="submit">
            <fieldset>
                <legend class="field-label">How useful was the session? (1–5)</legend>
                <div class="mt-3 grid max-w-sm grid-cols-5 gap-1">
                    <button
                        v-for="n in 5"
                        :key="n"
                        type="button"
                        class="tnum h-12 border font-display text-lg transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500 focus-visible:ring-offset-2"
                        style="font-stretch: 86%; font-weight: 700"
                        :class="form.rating === n ? 'border-ink-900 bg-ink-900 text-white' : 'border-rule-strong bg-white text-ink-600 hover:border-ink-900'"
                        :aria-pressed="form.rating === n"
                        :aria-label="`${n} out of 5`"
                        @click="form.rating = n"
                    >
                        {{ n }}
                    </button>
                </div>
                <p class="mt-2 flex max-w-sm justify-between text-meta text-ink-400" aria-hidden="true">
                    <span>Not useful</span><span>Very useful</span>
                </p>
            </fieldset>

            <FormField id="public-feedback-1" label="What was your biggest takeaway?" :error="form.errors.biggest_takeaway" v-slot="{ id, describedBy, invalid }">
                <textarea :id="id" v-model="form.biggest_takeaway" rows="4" class="field" :aria-describedby="describedBy" :aria-invalid="invalid"></textarea>
            </FormField>

            <fieldset>
                <legend class="field-label">What topics would you like Lumko to explore next?</legend>
                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    <label v-for="option in topicOptions" :key="option" class="choice text-sm">
                        <Checkbox v-model:checked="form.topics_next" :value="option" />
                        <span>{{ option }}</span>
                    </label>
                </div>
            </fieldset>

            <div>
                <label class="choice text-sm">
                    <Checkbox v-model:checked="form.wants_contact" />
                    <span>Would you like Lumko to contact you about any of these areas?</span>
                </label>
                <fieldset v-if="form.wants_contact" class="mt-3 border-l-2 border-rule pl-4">
                    <legend class="sr-only">Topics to be contacted about</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="option in topicOptions" :key="option" class="choice text-sm">
                            <Checkbox v-model:checked="form.contact_topics" :value="option" />
                            <span>{{ option }}</span>
                        </label>
                    </div>
                    <p v-if="form.errors.contact_topics" class="field-error">
                        {{ form.errors.contact_topics }}
                    </p>
                </fieldset>
            </div>

            <fieldset v-if="speakers.length">
                <legend class="field-label">Connect with a speaker</legend>
                <p class="mt-1 text-sm text-ink-500">
                    Tick any speaker you'd like to hear from. Only the speakers you tick receive your name, email,
                    organisation and job title. You can withdraw this any time from your account.
                </p>
                <div v-if="speakersToOffer.length" class="mt-3 space-y-2">
                    <label v-for="speaker in speakersToOffer" :key="speaker.id" class="choice text-sm">
                        <Checkbox v-model:checked="form.speaker_ids" :value="speaker.id" />
                        <span>{{ speaker.consent_text }}</span>
                    </label>
                </div>
                <p v-if="alreadyConnected.length" class="mt-3 text-sm text-ink-600">
                    Already connected: {{ alreadyConnected.map((s) => s.name).join(', ') }}.
                    <Link :href="route('attendee.preferences.edit')" class="link">Manage</Link>
                </p>
            </fieldset>

            <Button type="submit" size="lg" :disabled="form.processing">
                Submit feedback
            </Button>
        </form>
    </PortalPage>
    </AttendeeLayout>
</template>
