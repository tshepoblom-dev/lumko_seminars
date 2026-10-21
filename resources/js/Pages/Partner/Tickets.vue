<script setup>
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import FormField from '@/Components/FormField.vue';
import PortalPage from '@/Components/PortalPage.vue';
import TextInput from '@/Components/TextInput.vue';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import { shortDate } from '@/lib/eventFormat';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    partner: Object,
    event: Object,
    tier: { type: String, default: null },
    features: { type: Array, default: () => [] },
    cap: Number,
    used: Number,
    tickets: { type: Array, default: () => [] },
});

const left = computed(() => Math.max(0, props.cap - props.used));
const form = useForm({ recipient_name: '', recipient_email: '' });

function submit() {
    form.post(route('partner.events.tickets.store', [props.partner.slug, props.event.id]), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

const statusLabel = { unused: 'Not sent', sent: 'Sent, not used yet', redeemed: 'Used', revoked: 'Cancelled', closed: 'Registration closed' };
const statusVariant = { unused: 'warning', sent: 'info', redeemed: 'success', revoked: 'neutral', closed: 'neutral' };
</script>

<template>
    <PartnerLayout :partner="partner" :event="event" :features="features">
        <Head :title="`Complimentary tickets · ${event.name}`" />

        <PortalPage :eyebrow="event.name" title="Complimentary tickets">
            <p class="max-w-prose text-[0.9375rem] text-ink-600">
                Your partnership includes <strong class="tnum font-semibold text-ink-900">{{ cap }}</strong> complimentary in-person
                {{ cap === 1 ? 'ticket' : 'tickets' }}. Name a guest and we email them a personal invitation code; it works only with their email address.
            </p>
            <p class="tnum mt-2 text-sm text-ink-700" role="status">{{ left }} of {{ cap }} left.</p>

            <form v-if="left > 0" class="mt-8 grid gap-5 sm:grid-cols-2" @submit.prevent="submit">
                <FormField id="ticket-name" label="Guest's name" :error="form.errors.recipient_name" required v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.recipient_name" required autocomplete="off" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
                <FormField id="ticket-email" label="Guest's email" :error="form.errors.recipient_email" required v-slot="{ id, describedBy, invalid }">
                    <TextInput :id="id" v-model="form.recipient_email" type="email" required autocomplete="off" :aria-describedby="describedBy" :aria-invalid="invalid" />
                </FormField>
                <div class="sm:col-span-2">
                    <Button type="submit" :disabled="form.processing">Send the invitation</Button>
                </div>
            </form>

            <section class="mt-12" aria-labelledby="sent-title">
                <h2 id="sent-title" class="border-b border-ink-900 pb-3 text-xl text-ink-900">Sent</h2>
                <ul v-if="tickets.length" class="divide-y divide-rule">
                    <li v-for="t in tickets" :key="t.id" class="flex flex-wrap items-center justify-between gap-3 py-3.5 text-sm">
                        <span>
                            <span class="block font-medium text-ink-900">{{ t.recipient_name }}</span>
                            <span class="block text-meta text-ink-500">{{ t.recipient_email }} · {{ shortDate(t.sent_at || t.created_at) }}</span>
                        </span>
                        <Badge :variant="statusVariant[t.status] ?? 'neutral'">{{ statusLabel[t.status] ?? t.status }}</Badge>
                    </li>
                </ul>
                <p v-else class="py-6 text-sm text-ink-600">None sent yet.</p>
            </section>
        </PortalPage>
    </PartnerLayout>
</template>
