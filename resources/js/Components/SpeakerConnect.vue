<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';

/**
 * "Connect with [speaker]": the attendee chooses to share their contact
 * details with this one speaker. The dialog shows the exact sentence they
 * agree to (the same text the consent ledger stores) and what is shared.
 * Withdrawing is on the account page.
 */
const props = defineProps({
    // { id, name, connected, consent_text }
    speaker: { type: Object, required: true },
    eventId: { type: Number, required: true },
    open: { type: Boolean, default: true },
});

const showing = ref(false);
const form = useForm({ note: '' });

function connect() {
    form.post(route('speakers.connect', { event: props.eventId, speaker: props.speaker.id }), {
        preserveScroll: true,
        onSuccess: () => {
            showing.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <span v-if="speaker.connected" class="inline-flex items-center gap-2 text-meta text-ink-800">
        <span aria-hidden="true" class="h-2 w-2 rounded-full bg-verified-500"></span>
        <span>Connected<span class="sr-only"> with {{ speaker.name }}</span></span>
        <Link :href="route('attendee.preferences.edit')" class="link text-meta">Manage</Link>
    </span>
    <template v-else-if="open">
        <Button size="sm" variant="secondary" :aria-label="`Connect with ${speaker.name}`" @click="showing = true">Connect</Button>

        <Modal :show="showing" :title="`Connect with ${speaker.name}`" @close="showing = false">
            <div class="space-y-4 text-sm text-ink-800">
                <p>{{ speaker.consent_text }}</p>
                <p class="text-ink-500">
                    Shared from your account: your name, email, organisation and job title. Nothing else, and only with
                    {{ speaker.name }}. They receive it after the event.
                </p>
                <FormField
                    :id="`speaker-note-${speaker.id}`"
                    label="Anything you'd like to talk about? (optional)"
                    :error="form.errors.note"
                    v-slot="{ id, describedBy, invalid }"
                >
                    <textarea
                        :id="id"
                        v-model="form.note"
                        rows="3"
                        maxlength="500"
                        class="field"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                    ></textarea>
                </FormField>
            </div>
            <template #footer>
                <Button variant="secondary" @click="showing = false">Cancel</Button>
                <Button :disabled="form.processing" @click="connect">Share my details</Button>
            </template>
        </Modal>
    </template>
</template>
