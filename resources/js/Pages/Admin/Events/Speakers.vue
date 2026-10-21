<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Badge from '@/Components/Badge.vue';
import Button from '@/Components/Button.vue';
import EmptyState from '@/Components/EmptyState.vue';
import Modal from '@/Components/Modal.vue';
import InvitePortalModal from '@/Components/Admin/InvitePortalModal.vue';
import { useEventRoute } from '@/composables/useEventRoute';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    speakers: { type: Array, default: () => [] },
    library: { type: Array, default: () => [] },
    eventHasEnded: { type: Boolean, default: false },
});

const page = usePage();
const eventRoute = useEventRoute();
const eventId = computed(() => page.props.workspace?.event?.id);

// ── Add from the shared library ────────────────────────────────────────
const adding = ref(false);
const search = ref('');
const addForm = useForm({ speaker_ids: [] });

const filteredLibrary = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return props.library;
    return props.library.filter((s) =>
        [s.name, s.title, s.organisation].filter(Boolean).some((v) => v.toLowerCase().includes(term)),
    );
});

function openAdd() {
    addForm.reset();
    search.value = '';
    adding.value = true;
}

function addSelected() {
    addForm.post(eventRoute('admin.events.speakers.store'), {
        preserveScroll: true,
        onSuccess: () => { adding.value = false; },
    });
}

// ── Order + remove ─────────────────────────────────────────────────────
function move(speaker, direction) {
    router.post(eventRoute('admin.events.speakers.move', { speaker: speaker.id }), { direction }, { preserveScroll: true });
}

const speakerToRemove = ref(null);

function remove() {
    router.delete(eventRoute('admin.events.speakers.destroy', { speaker: speakerToRemove.value.id }), {
        preserveScroll: true,
        onFinish: () => { speakerToRemove.value = null; },
    });
}

// ── Speaker report: the people who chose to connect + session report ──
const speakerToSend = ref(null);
const sending = ref(false);

function sendReport() {
    sending.value = true;
    router.post(eventRoute('admin.events.speakers.report.send', { speaker: speakerToSend.value.id }), {}, {
        preserveScroll: true,
        onFinish: () => { sending.value = false; speakerToSend.value = null; },
    });
}

function revokeReport(speaker) {
    if (!confirm(`Stop ${speaker.name}'s report link from working?`)) return;
    router.post(eventRoute('admin.events.speakers.report.revoke', { speaker: speaker.id }), {}, { preserveScroll: true });
}

function reportLine(speaker) {
    const r = speaker.report;
    if (r.state === 'active') return `Report link sent ${r.sent_at}, works until ${r.expires_at}`;
    if (r.state === 'inactive') return `Report link sent ${r.sent_at}, no longer works`;
    return 'Report link not sent';
}

// ── Speaker portal: their own account (schedule, resources, livestream test) ──
const portalLabel = (p) => ({
    active: 'Has a speaker account',
    invited: `Invited ${p.invited_on} (link works until ${p.expires_on})`,
    not_invited: 'No speaker account yet',
})[p.status];
const portalVariant = (p) => ({ active: 'success', invited: 'warning', not_invited: 'neutral' })[p.status];

// One modal for single invites, resends and the whole line-up.
const invite = ref(null); // { action, recipients, skipped, resend }

function inviteOne(speaker) {
    invite.value = {
        action: route('admin.speakers.portal-invite', speaker.id),
        recipients: [{ name: speaker.name, email: speaker.contact_email }],
        skipped: [],
        resend: speaker.portal.status === 'invited',
    };
}

const notYetInvited = computed(() => props.speakers.filter((s) => s.portal.status === 'not_invited'));
const invitable = computed(() => notYetInvited.value.filter((s) => s.portal.can_invite));

function inviteAll() {
    invite.value = {
        action: eventRoute('admin.events.speakers.portal-invite-all'),
        recipients: invitable.value.map((s) => ({ name: s.name, email: s.contact_email })),
        skipped: notYetInvited.value.filter((s) => !s.portal.can_invite).map((s) => s.name),
        resend: false,
    };
}

function removeAccess(speaker) {
    if (!confirm(`Remove ${speaker.name}'s speaker portal access? Their account, resources and stage links are not affected.`)) return;
    router.delete(route('admin.speakers.portal-revoke', speaker.id), { preserveScroll: true });
}
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Speakers</h2>
        </template>

        <Head title="Event speakers" />

        <div class="py-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p class="max-w-prose text-sm text-gray-600">
                    Speakers come from the shared
                    <Link :href="route('admin.speakers.index')" class="text-brand underline">speaker library</Link>.
                    The order here is the order they appear on the public page.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="invitable.length" variant="secondary" @click="inviteAll">
                        Invite everyone not yet invited ({{ invitable.length }})
                    </Button>
                    <Button variant="secondary" :href="route('admin.speakers.create', { event: eventId })">Create new speaker</Button>
                    <Button :disabled="!library.length" @click="openAdd">Add from library</Button>
                </div>
            </div>

            <ol v-if="speakers.length" class="divide-y divide-gray-100 rounded-lg bg-white shadow">
                <li v-for="(speaker, i) in speakers" :key="speaker.id" class="flex flex-wrap items-center gap-4 p-4">
                    <span class="tnum w-6 text-sm text-gray-600">{{ i + 1 }}</span>
                    <img
                        v-if="speaker.photo_url"
                        :src="speaker.photo_url"
                        alt=""
                        class="h-10 w-10 rounded-full object-cover"
                    />
                    <span v-else aria-hidden="true" class="flex h-10 w-10 items-center justify-center rounded-full bg-gray-200 text-sm font-semibold text-gray-700">
                        {{ speaker.name.split(' ').map((p) => p[0]).slice(0, 2).join('') }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-gray-900">
                            {{ speaker.name }}
                            <Badge v-if="speaker.photo_url && !speaker.photo_approved" variant="warning" class="ml-2">Photo pending approval</Badge>
                        </p>
                        <p v-if="speaker.title || speaker.organisation" class="text-sm text-gray-600">
                            {{ [speaker.title, speaker.organisation].filter(Boolean).join(' · ') }}
                        </p>
                        <p class="mt-1 text-sm text-gray-700">
                            <template v-if="speaker.sessions.length">Sessions: {{ speaker.sessions.join(', ') }}</template>
                            <span v-else class="text-gray-600">Not on any session yet</span>
                        </p>
                        <p class="mt-1 text-sm text-gray-700" :data-testid="`speaker-report-${speaker.id}`">
                            <strong class="tnum">{{ speaker.connections }}</strong>
                            {{ speaker.connections === 1 ? 'attendee has' : 'attendees have' }} asked to connect
                            <span class="text-gray-600"> · {{ reportLine(speaker) }}</span>
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                            <a
                                :href="eventRoute('admin.events.speakers.report.preview', { speaker: speaker.id })"
                                target="_blank"
                                rel="noopener"
                                class="text-brand hover:underline"
                            >Preview report<span aria-hidden="true"> &#8599;&#xFE0E;</span><span class="sr-only"> (opens in a new tab)</span></a>
                            <button
                                v-if="speaker.has_contact_email"
                                type="button"
                                class="text-brand hover:underline"
                                @click="speakerToSend = speaker"
                            >
                                {{ speaker.report.state === 'not_sent' ? 'Send report link' : 'Send a new report link' }}
                            </button>
                            <Link v-else :href="route('admin.speakers.edit', speaker.id)" class="text-gray-700 underline">
                                Add a contact email to send their report
                            </Link>
                            <button
                                v-if="speaker.report.state === 'active'"
                                type="button"
                                class="text-red-700 hover:underline"
                                @click="revokeReport(speaker)"
                            >
                                Revoke link
                            </button>
                        </div>
                        <!-- Speaker portal: their own account -->
                        <div class="mt-2 flex flex-wrap items-center gap-3 text-sm" :data-testid="`speaker-portal-${speaker.id}`">
                            <Badge :variant="portalVariant(speaker.portal)">{{ portalLabel(speaker.portal) }}</Badge>
                            <span v-if="speaker.portal.status === 'active' && speaker.portal.account_email" class="text-gray-600">{{ speaker.portal.account_email }}</span>
                            <button v-if="speaker.portal.can_invite" type="button" class="text-brand hover:underline" @click="inviteOne(speaker)">
                                {{ speaker.portal.status === 'invited' ? 'Resend invitation' : 'Invite to the speaker portal' }}
                            </button>
                            <Link v-else-if="speaker.portal.status !== 'active'" :href="route('admin.speakers.edit', speaker.id)" class="text-gray-700 underline">
                                Add a contact email to invite them
                            </Link>
                            <button v-if="speaker.portal.status === 'active'" type="button" class="text-red-700 hover:underline" @click="removeAccess(speaker)">
                                Remove portal access
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center gap-1 text-sm">
                        <button
                            type="button"
                            class="rounded px-2 py-1 text-gray-700 hover:bg-gray-100 disabled:text-gray-300"
                            :disabled="i === 0"
                            :aria-label="`Move ${speaker.name} up`"
                            @click="move(speaker, 'up')"
                        >
                            ↑
                        </button>
                        <button
                            type="button"
                            class="rounded px-2 py-1 text-gray-700 hover:bg-gray-100 disabled:text-gray-300"
                            :disabled="i === speakers.length - 1"
                            :aria-label="`Move ${speaker.name} down`"
                            @click="move(speaker, 'down')"
                        >
                            ↓
                        </button>
                        <Link :href="route('admin.speakers.edit', speaker.id)" class="ml-2 text-brand hover:underline">Edit profile</Link>
                        <button type="button" class="ml-3 text-red-700 hover:underline" @click="speakerToRemove = speaker">Remove</button>
                    </div>
                </li>
            </ol>

            <div v-else class="rounded-lg bg-white shadow">
                <EmptyState title="No speakers linked yet" description="Add speakers from the library, or create a new one. You can then name them on sessions.">
                    <template #action>
                        <Button size="sm" :disabled="!library.length" @click="openAdd">Add from library</Button>
                    </template>
                </EmptyState>
            </div>
        </div>

        <!-- Add from library -->
        <Modal :show="adding" title="Add speakers from the library" @close="adding = false">
            <label for="library-search" class="field-label mb-1.5">Search</label>
            <input
                id="library-search"
                v-model="search"
                type="search"
                class="field w-full"
                placeholder="Name, title or organisation"
            />
            <ul class="mt-3 max-h-80 divide-y divide-gray-100 overflow-y-auto rounded border border-gray-200">
                <li v-for="speaker in filteredLibrary" :key="speaker.id">
                    <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-gray-50">
                        <input v-model="addForm.speaker_ids" type="checkbox" :value="speaker.id" class="rounded border-gray-300 text-brand focus:ring-brand" />
                        <span>
                            <span class="font-medium text-gray-900">{{ speaker.name }}</span>
                            <span v-if="speaker.organisation" class="text-gray-600"> · {{ speaker.organisation }}</span>
                        </span>
                    </label>
                </li>
                <li v-if="!filteredLibrary.length" class="px-3 py-4 text-sm text-gray-600">No speakers match “{{ search }}”.</li>
            </ul>
            <p v-if="addForm.errors.speaker_ids" role="alert" class="field-error">{{ addForm.errors.speaker_ids }}</p>
            <template #footer>
                <Button variant="secondary" @click="adding = false">Cancel</Button>
                <Button :disabled="!addForm.speaker_ids.length || addForm.processing" @click="addSelected">
                    Add {{ addForm.speaker_ids.length || '' }} to event
                </Button>
            </template>
        </Modal>

        <InvitePortalModal
            kind="speaker"
            :show="!!invite"
            :action="invite?.action"
            :recipients="invite?.recipients ?? []"
            :skipped="invite?.skipped ?? []"
            :resend="invite?.resend ?? false"
            @close="invite = null"
        />

        <!-- Send report link -->
        <Modal :show="!!speakerToSend" title="Email the speaker report" @close="speakerToSend = null">
            <div class="space-y-3 text-sm text-gray-700">
                <p>
                    <strong>{{ speakerToSend?.name }}</strong> gets a private link to their report: the
                    <strong>{{ speakerToSend?.connections }}</strong>
                    {{ speakerToSend?.connections === 1 ? 'attendee who' : 'attendees who' }} chose to share their details with them,
                    plus their session's questions, reactions, polls and an audience summary.
                </p>
                <p v-if="!eventHasEnded" class="rounded border border-amber-300 bg-amber-50 p-3 text-amber-900">
                    The event hasn't ended, so more attendees may still connect. The link always shows the current list.
                </p>
                <p v-if="speakerToSend?.report.state !== 'not_sent'">Any earlier link stops working.</p>
            </div>
            <template #footer>
                <Button variant="secondary" @click="speakerToSend = null">Cancel</Button>
                <Button :disabled="sending" @click="sendReport">Send link</Button>
            </template>
        </Modal>

        <!-- Remove -->
        <Modal :show="!!speakerToRemove" title="Remove speaker from event" @close="speakerToRemove = null">
            <p class="text-sm text-gray-600">
                Remove <strong>{{ speakerToRemove?.name }}</strong> from this event? They stay in the speaker library and on any other events.
            </p>
            <template #footer>
                <Button variant="secondary" @click="speakerToRemove = null">Cancel</Button>
                <Button variant="danger" @click="remove">Remove</Button>
            </template>
        </Modal>
    </AdminLayout>
</template>
