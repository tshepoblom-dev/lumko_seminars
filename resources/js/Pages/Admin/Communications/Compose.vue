<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import Button from '@/Components/Button.vue';
import Checkbox from '@/Components/Checkbox.vue';
import FormField from '@/Components/FormField.vue';
import Modal from '@/Components/Modal.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';
import { formatBytes, plural } from '@/lib/communications';

const props = defineProps({
    isPlatform: { type: Boolean, default: false },
    state: { type: Object, required: true },
    audience: { type: Object, default: () => ({ count: 0, without_consent: 0 }) },
    candidates: { type: Object, default: null },
    preselected: { type: Array, default: () => [] },
    options: { type: Object, required: true },
    limits: { type: Object, required: true },
    defaultTestEmail: { type: String, default: '' },
    urls: { type: Object, required: true },
});

const page = usePage();

// --- Recipients -------------------------------------------------------------

const EVENT_FILTERS = {
    search: '', mode: '', payment_status: '', industry: '', organisation_size: '', interest: '',
    networking_opt_in: '', lumko_opt_in: '', account_set_up: '', checked_in_only: false, wants_follow_up: false,
};
const PLATFORM_FILTERS = { search: '', event_id: '', industry: '', organisation_size: '', interest: '' };

const mode = ref(props.preselected.length ? 'selected' : props.state.mode);
const category = ref(props.isPlatform ? 'marketing' : props.state.category);
// Answers to the event's own choice/yes-no questions: question id → option id.
const blankAnswers = Object.fromEntries((props.options.questions ?? []).map((q) => [q.id, '']));
const filters = reactive({
    ...(props.isPlatform ? PLATFORM_FILTERS : EVENT_FILTERS),
    ...props.state.filters,
    ...(props.isPlatform ? {} : { answers: { ...blankAnswers, ...(props.state.filters?.answers ?? {}) } }),
});
const candidatePage = ref(1);

// Picked people, kept here (not in the URL) so paging and filtering the
// list never loses a tick.
const selected = ref(props.preselected.map(({ id, name, email }) => ({ id, name, email })));
const selectedIds = computed(() => new Set(selected.value.map((p) => p.id)));

function toggle(person) {
    if (selectedIds.value.has(person.id)) {
        selected.value = selected.value.filter((p) => p.id !== person.id);
    } else {
        selected.value = [...selected.value, { id: person.id, name: person.name, email: person.email }];
    }
}

const allOnPageSelected = computed(() =>
    !!props.candidates?.data.length && props.candidates.data.every((p) => selectedIds.value.has(p.id)),
);

function toggleAllOnPage() {
    if (allOnPageSelected.value) {
        const onPage = new Set(props.candidates.data.map((p) => p.id));
        selected.value = selected.value.filter((p) => !onPage.has(p.id));
    } else {
        const toAdd = props.candidates.data.filter((p) => !selectedIds.value.has(p.id));
        selected.value = [...selected.value, ...toAdd.map(({ id, name, email }) => ({ id, name, email }))];
    }
}

function appliedFilters() {
    const applied = Object.fromEntries(Object.entries(filters).filter(([k, v]) => k !== 'answers' && v !== '' && v !== false && v !== null));
    const answers = Object.fromEntries(Object.entries(filters.answers ?? {}).filter(([, v]) => v !== ''));
    if (Object.keys(answers).length) applied.answers = answers;
    return applied;
}

const activeFilterCount = computed(() => {
    const { answers = {}, ...rest } = appliedFilters();
    return Object.keys(rest).length + Object.keys(answers).length;
});

// The count and the "choose people" list come from the server (the same
// query the send uses), re-read whenever the recipients change.
const refreshing = ref(false);
let refreshTimer = null;

function refreshAudience() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(() => {
        router.get(
            props.urls.compose,
            {
                mode: mode.value,
                ...(props.isPlatform ? {} : { category: category.value }),
                filters: appliedFilters(),
                page: mode.value === 'selected' ? candidatePage.value : undefined,
            },
            {
                only: ['audience', 'candidates'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => { refreshing.value = true; },
                onFinish: () => { refreshing.value = false; },
            },
        );
    }, 300);
}

watch([mode, category], () => {
    candidatePage.value = 1;
    refreshAudience();
});
watch(filters, () => {
    candidatePage.value = 1;
    refreshAudience();
}, { deep: true });
watch(candidatePage, refreshAudience);

// A deep link arrives in "selected" mode without the list loaded yet.
if (mode.value === 'selected' && !props.candidates) refreshAudience();

const recipientCount = computed(() => (mode.value === 'selected' ? selected.value.length : props.audience.count));

// --- Message --------------------------------------------------------------

const form = useForm({
    subject: '',
    body: '',
    attachments: [],
    test_email: props.defaultTestEmail,
});

const fileInput = ref(null);
const totalBytes = computed(() => form.attachments.reduce((sum, file) => sum + file.size, 0));
const maxBytes = props.limits.max_total_kb * 1024;
const attachmentProblem = computed(() => {
    if (form.attachments.length > props.limits.max_attachments) return `Up to ${props.limits.max_attachments} attachments.`;
    if (totalBytes.value > maxBytes) return `Attachments can be ${formatBytes(maxBytes)} at most in total. Link to large files instead.`;
    return null;
});

function addFiles(event) {
    form.attachments = [...form.attachments, ...Array.from(event.target.files ?? [])];
    event.target.value = '';
}

function removeFile(index) {
    form.attachments = form.attachments.filter((_, i) => i !== index);
}

const attachmentErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key === 'attachments' || key.startsWith('attachments.'))
        .map(([, message]) => message),
);

const previewName = computed(() => page.props.auth.user?.name?.split(' ')[0] || 'there');
const previewParagraphs = computed(() =>
    form.body
        .replaceAll('{first_name}', previewName.value)
        .split(/\n\s*\n/)
        .map((p) => p.trim())
        .filter(Boolean),
);

// --- Sending --------------------------------------------------------------

function payload(data) {
    return {
        ...data,
        category: category.value,
        recipient_mode: mode.value,
        filters: mode.value === 'filtered' ? appliedFilters() : {},
        user_ids: mode.value === 'selected' ? selected.value.map((p) => p.id) : [],
    };
}

const messageReady = computed(() => form.subject.trim() !== '' && form.body.trim() !== '' && !attachmentProblem.value);
const canSend = computed(() => messageReady.value && recipientCount.value > 0 && !form.processing);

function sendTest() {
    form.transform(payload).post(props.urls.test, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
    });
}

const confirming = ref(false);

function send() {
    form.transform(payload).post(props.urls.store, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => { confirming.value = false; },
    });
}

const recipientsError = computed(() => form.errors.recipient_mode ?? form.errors.user_ids ?? null);
</script>

<template>
    <AdminLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                {{ isPlatform ? 'New platform email' : 'New email' }}
            </h2>
        </template>

        <Head :title="isPlatform ? 'New platform email' : 'New email'" />

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <p class="mb-4 text-sm">
                    <Link :href="urls.index" class="link">All emails</Link>
                </p>

                <div class="grid items-start gap-6 xl:grid-cols-2">
                    <!-- Recipients -->
                    <section aria-labelledby="recipients-heading" class="space-y-5 border border-rule bg-white p-5">
                        <h3 id="recipients-heading" class="font-display text-xl text-ink-900">Recipients</h3>

                        <fieldset>
                            <legend class="field-label mb-2">Send to</legend>
                            <div class="space-y-2">
                                <label class="choice">
                                    <input v-model="mode" type="radio" value="all" class="mt-1 border-ink-400 text-ink-900 focus:ring-signal-500" />
                                    <span>
                                        <span class="block text-sm font-medium text-ink-900">Everyone</span>
                                        <span class="block text-meta text-ink-500">
                                            {{ isPlatform ? 'Every attendee account on the platform' : 'Everyone registered for this event' }}
                                        </span>
                                    </span>
                                </label>
                                <label class="choice">
                                    <input v-model="mode" type="radio" value="filtered" class="mt-1 border-ink-400 text-ink-900 focus:ring-signal-500" />
                                    <span>
                                        <span class="block text-sm font-medium text-ink-900">A filtered group</span>
                                        <span class="block text-meta text-ink-500">Narrow by industry, attendance, payment, consent and more</span>
                                    </span>
                                </label>
                                <label class="choice">
                                    <input v-model="mode" type="radio" value="selected" class="mt-1 border-ink-400 text-ink-900 focus:ring-signal-500" />
                                    <span>
                                        <span class="block text-sm font-medium text-ink-900">People I choose</span>
                                        <span class="block text-meta text-ink-500">Pick one person or several from a list</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        <!-- Filters: define the group, or narrow the list to pick from. -->
                        <div v-if="mode !== 'all'" class="space-y-3 border-t border-rule pt-4">
                            <p class="text-sm font-medium text-ink-800">
                                {{ mode === 'filtered' ? 'Filters' : 'Narrow the list' }}
                                <span v-if="activeFilterCount" class="font-normal text-ink-500">· {{ activeFilterCount }} applied</span>
                            </p>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="sm:col-span-2">
                                    <label for="comm-search" class="field-label mb-1.5">Search</label>
                                    <input id="comm-search" v-model="filters.search" type="search" class="field" placeholder="Name, email or organisation" />
                                </div>

                                <div v-if="isPlatform" class="sm:col-span-2">
                                    <label for="comm-event" class="field-label mb-1.5">Registered for</label>
                                    <select id="comm-event" v-model="filters.event_id" class="field">
                                        <option value="">Any event</option>
                                        <option v-for="e in options.events" :key="e.id" :value="String(e.id)">{{ e.name }}</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="comm-industry" class="field-label mb-1.5">Industry</label>
                                    <select id="comm-industry" v-model="filters.industry" class="field">
                                        <option value="">Any</option>
                                        <option v-for="i in options.industries" :key="i" :value="i">{{ i }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="comm-size" class="field-label mb-1.5">Organisation size</label>
                                    <select id="comm-size" v-model="filters.organisation_size" class="field">
                                        <option value="">Any</option>
                                        <option v-for="s in options.organisation_sizes" :key="s" :value="s">{{ s }}</option>
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="comm-interest" class="field-label mb-1.5">Interested in</label>
                                    <select id="comm-interest" v-model="filters.interest" class="field">
                                        <option value="">Any</option>
                                        <option v-for="i in options.interests" :key="i" :value="i">{{ i }}</option>
                                    </select>
                                </div>

                                <template v-if="!isPlatform">
                                    <!-- The event's own choice and yes/no questions. -->
                                    <div v-for="q in options.questions ?? []" :key="q.id" class="sm:col-span-2">
                                        <label :for="`comm-q-${q.id}`" class="field-label mb-1.5">{{ q.label }}</label>
                                        <select :id="`comm-q-${q.id}`" v-model="filters.answers[q.id]" class="field">
                                            <option value="">Any</option>
                                            <option v-for="o in q.options" :key="o.id" :value="o.id">{{ o.label }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="comm-mode" class="field-label mb-1.5">Attendance</label>
                                        <select id="comm-mode" v-model="filters.mode" class="field">
                                            <option value="">Any</option>
                                            <option value="online">Online</option>
                                            <option value="in_person">In person</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="comm-payment" class="field-label mb-1.5">Payment</label>
                                        <select id="comm-payment" v-model="filters.payment_status" class="field">
                                            <option value="">Any</option>
                                            <option value="pending">Pending</option>
                                            <option value="under_review">Under review</option>
                                            <option value="approved">Approved</option>
                                            <option value="rejected">Rejected</option>
                                            <option value="cancelled">Cancelled</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="comm-networking" class="field-label mb-1.5">Networking opt-in</label>
                                        <select id="comm-networking" v-model="filters.networking_opt_in" class="field">
                                            <option value="">Any</option>
                                            <option value="1">Opted in</option>
                                            <option value="0">Not opted in</option>
                                        </select>
                                    </div>
                                    <div v-if="category === 'event'">
                                        <label for="comm-lumko" class="field-label mb-1.5">Lumko marketing consent</label>
                                        <select id="comm-lumko" v-model="filters.lumko_opt_in" class="field">
                                            <option value="">Any</option>
                                            <option value="1">Agreed</option>
                                            <option value="0">Not agreed</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="comm-account" class="field-label mb-1.5">Account</label>
                                        <select id="comm-account" v-model="filters.account_set_up" class="field">
                                            <option value="">Any</option>
                                            <option value="1">Set up</option>
                                            <option value="0">Not set up</option>
                                        </select>
                                    </div>
                                    <div class="flex flex-col justify-end gap-2 sm:col-span-2">
                                        <label class="flex items-center gap-2 text-sm text-ink-800">
                                            <Checkbox v-model:checked="filters.checked_in_only" /> Checked in at the venue
                                        </label>
                                        <label class="flex items-center gap-2 text-sm text-ink-800">
                                            <Checkbox v-model:checked="filters.wants_follow_up" /> Asked for a follow-up (feedback)
                                        </label>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <!-- Choose people -->
                        <div v-if="mode === 'selected'" class="border-t border-rule pt-4">
                            <div v-if="selected.length" class="mb-3">
                                <p class="mb-2 text-sm font-medium text-ink-800">{{ plural(selected.length, 'person', 'people') }} chosen</p>
                                <ul class="flex flex-wrap gap-2" aria-label="Chosen people">
                                    <li v-for="person in selected" :key="person.id" class="inline-flex items-center gap-1 border border-rule-strong bg-paper py-1 pl-2.5 pr-1 text-meta text-ink-800">
                                        {{ person.name }}
                                        <button
                                            type="button"
                                            class="flex h-6 w-6 items-center justify-center rounded-ctl text-ink-500 hover:bg-ink-100 hover:text-ink-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500"
                                            :aria-label="`Remove ${person.name}`"
                                            @click="toggle(person)"
                                        >✕</button>
                                    </li>
                                </ul>
                            </div>

                            <div v-if="candidates" class="border border-rule">
                                <div class="flex items-center justify-between gap-3 border-b border-rule bg-paper px-3 py-2">
                                    <label class="flex items-center gap-2 text-meta text-ink-800">
                                        <Checkbox :checked="allOnPageSelected" @update:checked="toggleAllOnPage" /> All on this page
                                    </label>
                                    <span class="text-meta text-ink-500">{{ candidates.total }} matching</span>
                                </div>
                                <ul class="max-h-80 divide-y divide-rule overflow-y-auto">
                                    <li v-for="person in candidates.data" :key="person.id">
                                        <label class="flex cursor-pointer items-start gap-3 px-3 py-2.5 hover:bg-paper">
                                            <Checkbox class="mt-0.5" :checked="selectedIds.has(person.id)" @update:checked="toggle(person)" />
                                            <span class="min-w-0">
                                                <span class="block truncate text-sm text-ink-900">{{ person.name }}</span>
                                                <span class="block truncate text-meta text-ink-500">{{ person.email }}<template v-if="person.organisation"> · {{ person.organisation }}</template></span>
                                            </span>
                                        </label>
                                    </li>
                                    <li v-if="!candidates.data.length" class="px-3 py-6 text-center text-sm text-ink-500">No one matches.</li>
                                </ul>
                                <div v-if="candidates.last_page > 1" class="flex items-center justify-between border-t border-rule px-3 py-2 text-meta">
                                    <Button size="sm" variant="secondary" :disabled="candidatePage <= 1" @click="candidatePage--">Previous</Button>
                                    <span class="text-ink-500">Page {{ candidates.current_page }} of {{ candidates.last_page }}</span>
                                    <Button size="sm" variant="secondary" :disabled="candidatePage >= candidates.last_page" @click="candidatePage++">Next</Button>
                                </div>
                            </div>
                            <p v-else class="text-sm text-ink-500">Loading people…</p>
                        </div>

                        <!-- The number that goes on the Send button. -->
                        <div class="border-t border-ink-900 pt-4" aria-live="polite">
                            <p class="text-sm text-ink-800">
                                <strong class="font-display text-2xl tabular-nums text-ink-900" data-testid="recipient-count">{{ recipientCount }}</strong>
                                {{ recipientCount === 1 ? 'person' : 'people' }} will receive this email
                                <span v-if="refreshing" class="text-ink-500">(updating…)</span>
                            </p>
                            <p v-if="category === 'marketing' && audience.without_consent && mode !== 'selected'" class="mt-1 text-meta text-ink-500">
                                {{ plural(audience.without_consent, 'person is', 'people are') }} left out because they haven't agreed to hear from Lumko.
                            </p>
                            <p v-if="recipientsError" role="alert" class="field-error">{{ recipientsError }}</p>
                        </div>
                    </section>

                    <!-- Message -->
                    <section aria-labelledby="message-heading" class="space-y-5 border border-rule bg-white p-5">
                        <h3 id="message-heading" class="font-display text-xl text-ink-900">Message</h3>

                        <fieldset v-if="!isPlatform">
                            <legend class="field-label mb-2">Type</legend>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label class="choice">
                                    <input v-model="category" type="radio" value="event" class="mt-1 border-ink-400 text-ink-900 focus:ring-signal-500" />
                                    <span>
                                        <span class="block text-sm font-medium text-ink-900">Event update</span>
                                        <span class="block text-meta text-ink-500">About this event: logistics, changes, resources</span>
                                    </span>
                                </label>
                                <label class="choice">
                                    <input v-model="category" type="radio" value="marketing" class="mt-1 border-ink-400 text-ink-900 focus:ring-signal-500" />
                                    <span>
                                        <span class="block text-sm font-medium text-ink-900">Marketing</span>
                                        <span class="block text-meta text-ink-500">Other events, products or services. Only people who agreed receive it.</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>
                        <p v-else class="border border-rule bg-paper px-4 py-3 text-sm text-ink-800">
                            Platform emails are <strong>marketing</strong>: only people who agreed to hear from Lumko receive them,
                            and each one carries a link to change that.
                        </p>

                        <FormField id="comm-subject" label="Subject" required :error="form.errors.subject" v-slot="{ id, describedBy, invalid }">
                            <TextInput :id="id" v-model="form.subject" maxlength="200" :aria-describedby="describedBy" :aria-invalid="invalid" />
                        </FormField>

                        <FormField
                            id="comm-body"
                            label="Message"
                            required
                            :error="form.errors.body"
                            hint="Starts with “Hi {first name},”. Leave a blank line between paragraphs. Type {first_name} to use the person's first name. **bold** and [link text](https://…) work."
                            v-slot="{ id, describedBy, invalid }"
                        >
                            <textarea :id="id" v-model="form.body" rows="12" class="field font-sans" :aria-describedby="describedBy" :aria-invalid="invalid"></textarea>
                        </FormField>

                        <div>
                            <label for="comm-attachments" class="field-label mb-1.5">Attachments</label>
                            <ul v-if="form.attachments.length" class="mb-2 divide-y divide-rule border border-rule">
                                <li v-for="(file, i) in form.attachments" :key="`${file.name}-${i}`" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                    <span class="min-w-0 truncate text-ink-900">{{ file.name }}</span>
                                    <span class="flex shrink-0 items-center gap-2">
                                        <span class="tabular-nums text-meta text-ink-500">{{ formatBytes(file.size) }}</span>
                                        <button
                                            type="button"
                                            class="flex h-7 w-7 items-center justify-center rounded-ctl text-ink-500 hover:bg-ink-100 hover:text-ink-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500"
                                            :aria-label="`Remove ${file.name}`"
                                            @click="removeFile(i)"
                                        >✕</button>
                                    </span>
                                </li>
                            </ul>
                            <input
                                id="comm-attachments"
                                ref="fileInput"
                                type="file"
                                multiple
                                tabindex="-1"
                                class="sr-only"
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.csv,.txt,.png,.jpg,.jpeg"
                                aria-describedby="comm-attachments-hint"
                                @change="addFiles"
                            />
                            <Button size="sm" variant="secondary" @click="fileInput.click()">Add files</Button>
                            <p id="comm-attachments-hint" class="field-hint">
                                PDF, Word, Excel, PowerPoint, CSV, text or images. Up to {{ limits.max_attachments }} files,
                                {{ formatBytes(limits.max_total_kb * 1024) }} in total<template v-if="form.attachments.length"> (now {{ formatBytes(totalBytes) }})</template>.
                            </p>
                            <p v-if="attachmentProblem" role="alert" class="field-error">{{ attachmentProblem }}</p>
                            <p v-for="(message, i) in attachmentErrors" :key="i" role="alert" class="field-error">{{ message }}</p>
                        </div>

                        <details class="border border-rule">
                            <summary class="cursor-pointer px-4 py-2.5 text-sm font-medium text-ink-800 hover:bg-paper">Preview</summary>
                            <div class="space-y-3 border-t border-rule px-4 py-4 text-sm text-ink-800">
                                <p class="text-meta text-ink-500">Subject: <span class="text-ink-900">{{ form.subject || '(no subject yet)' }}</span></p>
                                <p>Hi {{ previewName }},</p>
                                <p v-for="(p, i) in previewParagraphs" :key="i" class="whitespace-pre-line">{{ p }}</p>
                                <p v-if="form.attachments.length" class="text-meta text-ink-500">
                                    Attached: {{ form.attachments.map((f) => f.name).join(', ') }}
                                </p>
                                <p class="text-meta text-ink-500">
                                    The footer adds the organiser's name, a link to manage email preferences, and the privacy and terms links.
                                    Formatting such as bold and links shows in the email.
                                </p>
                            </div>
                        </details>

                        <!-- Test send -->
                        <div class="border-t border-rule pt-4">
                            <FormField
                                id="comm-test-email"
                                label="Send a test to"
                                :error="form.errors.test_email"
                                hint="Only this address receives the test. Its subject starts with [TEST]."
                                v-slot="{ id, describedBy, invalid }"
                            >
                                <div class="flex flex-wrap gap-2">
                                    <TextInput :id="id" v-model="form.test_email" type="email" class="min-w-0 flex-1" autocomplete="email" :aria-describedby="describedBy" :aria-invalid="invalid" />
                                    <Button variant="secondary" :disabled="!messageReady || !form.test_email || form.processing" @click="sendTest">Send test</Button>
                                </div>
                            </FormField>
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-3 border-t border-ink-900 pt-4">
                            <p v-if="!messageReady" class="mr-auto text-meta text-ink-500">Add a subject and message to send.</p>
                            <Button :disabled="!canSend" @click="confirming = true">
                                Send to {{ plural(recipientCount, 'person', 'people') }}
                            </Button>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <Modal :show="confirming" title="Send this email?" @close="confirming = false">
            <div class="space-y-3 text-sm text-ink-800">
                <p>
                    <strong>“{{ form.subject }}”</strong> goes to
                    <strong>{{ plural(recipientCount, 'person', 'people') }}</strong>
                    as {{ category === 'marketing' ? 'a marketing email' : 'an event update' }}<template v-if="form.attachments.length">,
                    with {{ plural(form.attachments.length, 'attachment', 'attachments') }}</template>.
                </p>
                <p v-if="recipientCount > limits.per_minute" class="text-ink-500">
                    Emails go out at {{ limits.per_minute }} a minute, so this takes about {{ Math.ceil(recipientCount / limits.per_minute) }} minutes.
                </p>
                <p class="text-ink-500">Once sent it can't be recalled.</p>
            </div>
            <template #footer>
                <Button variant="secondary" @click="confirming = false">Cancel</Button>
                <Button :disabled="form.processing" @click="send">Send now</Button>
            </template>
        </Modal>
    </AdminLayout>
</template>
