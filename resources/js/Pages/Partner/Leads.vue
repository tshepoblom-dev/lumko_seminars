<script setup>
import Button from '@/Components/Button.vue';
import PortalPage from '@/Components/PortalPage.vue';
import PartnerLayout from '@/Layouts/PartnerLayout.vue';
import { shortDate } from '@/lib/eventFormat';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    partner: Object,
    event: Object,
    tier: { type: String, default: null },
    features: { type: Array, default: () => [] },
    sources: { type: Array, default: () => [] },
    leads: { type: Array, default: () => [] },
});

const source = ref('');
const search = ref('');

const shown = computed(() => {
    const q = search.value.trim().toLowerCase();
    return props.leads.filter((l) => (!source.value || l.source === source.value)
        && (!q || [l.name, l.email, l.organisation, l.job_title].some((v) => (v ?? '').toLowerCase().includes(q))));
});

const noteFor = ref(null);
const noteForm = useForm({ body: '' });

function addNote(lead) {
    noteForm.post(route('partner.events.leads.notes.store', [props.partner.slug, props.event.id, lead.id]), {
        preserveScroll: true,
        onSuccess: () => { noteForm.reset(); noteFor.value = null; },
    });
}
</script>

<template>
    <PartnerLayout :partner="partner" :event="event" :features="features">
        <Head :title="`Leads · ${event.name}`" />

        <PortalPage :eyebrow="event.name" title="Your leads" width="wide">
            <template #aside>
                <Button v-if="leads.length" :href="route('partner.events.leads.export', [partner.slug, event.id])" native variant="secondary" size="sm">Download as CSV</Button>
            </template>

            <p class="max-w-prose text-[0.9375rem] text-ink-600">
                Everyone here chose to share their details with {{ partner.name }}, by connecting, sending an enquiry, at your stand, or when they registered.
                If someone withdraws, they disappear from this list straight away. Please use their details only to follow up on this event, and honour any request to stop.
            </p>

            <div class="mt-8 flex flex-wrap items-end gap-4">
                <label class="block text-sm text-ink-700">
                    <span class="field-label">How they connected</span>
                    <select v-model="source" class="field mt-1.5 w-56">
                        <option value="">All</option>
                        <option v-for="s in sources" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </label>
                <label class="block text-sm text-ink-700">
                    <span class="field-label">Search</span>
                    <input v-model="search" type="search" class="field mt-1.5 w-64" placeholder="Name, email or organisation" />
                </label>
                <p class="tnum pb-2 text-sm text-ink-500" role="status">{{ shown.length }} of {{ leads.length }}</p>
            </div>

            <ul v-if="shown.length" class="mt-6 divide-y divide-rule border-y border-ink-900">
                <li v-for="lead in shown" :key="lead.id" class="grid gap-4 py-5 lg:grid-cols-12">
                    <div class="lg:col-span-4">
                        <p class="font-medium text-ink-900">{{ lead.name }}</p>
                        <p class="text-sm"><a :href="`mailto:${lead.email}`" class="link">{{ lead.email }}</a></p>
                        <p class="text-sm text-ink-600">{{ [lead.job_title, lead.organisation].filter(Boolean).join(', ') }}</p>
                    </div>
                    <div class="text-sm lg:col-span-3">
                        <p class="text-ink-700">{{ lead.source_label }}<template v-if="lead.captured_by"> · by {{ lead.captured_by }}</template></p>
                        <p class="text-meta text-ink-500">{{ shortDate(lead.since) }}</p>
                    </div>
                    <div class="text-sm lg:col-span-5">
                        <p v-if="lead.note" class="text-ink-800"><span class="text-meta text-ink-500">Their message:</span> {{ lead.note }}</p>
                        <ul v-if="lead.team_notes.length" class="mt-2 space-y-1">
                            <li v-for="n in lead.team_notes" :key="n.id" class="text-ink-700">
                                <span class="text-meta text-ink-500">{{ n.author }}, {{ shortDate(n.at) }}:</span> {{ n.body }}
                            </li>
                        </ul>
                        <form v-if="noteFor === lead.id" class="mt-2 flex gap-2" @submit.prevent="addNote(lead)">
                            <label :for="`note-${lead.id}`" class="sr-only">Team note</label>
                            <input :id="`note-${lead.id}`" v-model="noteForm.body" class="field flex-1" maxlength="2000" required placeholder="Visible to your team only" />
                            <Button type="submit" size="sm" :disabled="noteForm.processing">Add</Button>
                        </form>
                        <button v-else type="button" class="link mt-2 text-sm" @click="noteFor = lead.id; noteForm.reset()">Add a team note</button>
                    </div>
                </li>
            </ul>
            <p v-else class="mt-6 border-t border-ink-900 py-8 text-sm text-ink-600">
                {{ leads.length ? 'No leads match.' : 'No leads yet. They appear here as attendees choose to connect with you.' }}
            </p>
        </PortalPage>
    </PartnerLayout>
</template>
