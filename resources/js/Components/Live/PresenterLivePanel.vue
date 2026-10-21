<script setup>
import PollResults from '@/Components/Live/PollResults.vue';
import { useRealtime } from '@/composables/useRealtime';
import { computed, onMounted, reactive, ref } from 'vue';

// Polls, Q&A and chat for whoever is presenting, without leaving the page
// (leaving the Broadcast console or stage takes you off air). Staff (the
// console) also moderate here; speakers (their stage link) create, launch
// and close polls and read questions and chat.
//
// `api` does the talking, so the same panel serves both:
//   load()               → { polls, questions, chat }
//   createPoll(data)     → { polls }
//   openPoll(id)         → { polls }
//   closePoll(id)        → { polls }
//   moderate(action, id) → { questions }   (staff only)
//   hideChat(id)                             (staff only)
const props = defineProps({
    eventId: { type: Number, required: true },
    staff: { type: Boolean, default: false },
    api: { type: Object, required: true },
    // light: the white admin console · dark: the navy speaker stage
    tone: { type: String, default: 'light' },
});

const dark = computed(() => props.tone === 'dark');
const idBase = `presenter-${Math.random().toString(36).slice(2, 8)}`;

const polls = ref([]);
const questions = ref([]);
const chat = ref([]);
const loaded = ref(false);
const loadError = ref(null);
const actionError = ref(null);
const busy = ref(false);

function apply(data) {
    if (data?.polls) polls.value = data.polls;
    if (data?.questions) questions.value = data.questions;
    if (data?.chat) chat.value = data.chat;
}

async function load() {
    try {
        apply(await props.api.load());
        loadError.value = null;
        loaded.value = true;
    } catch {
        loadError.value = 'Could not load polls and questions. Retrying…';
    }
}

async function run(action) {
    if (busy.value) return;
    busy.value = true;
    actionError.value = null;
    try {
        apply(await action());
    } catch (e) {
        actionError.value = e?.response?.data?.message || 'That didn’t work. Try again in a moment.';
    } finally {
        busy.value = false;
    }
}

onMounted(load);

// --- Real time --------------------------------------------------------------

function upsertQuestion(q) {
    const i = questions.value.findIndex((x) => x.id === q.id);
    if (i === -1) questions.value = [q, ...questions.value];
    else questions.value = questions.value.map((x) => (x.id === q.id ? { ...x, ...q } : x));
}

const channels = [
    {
        name: `event.${props.eventId}`,
        handlers: {
            '.question.updated': (e) => {
                const q = e.question;
                if (!props.staff) {
                    // Speakers see what attendees see: approved and answered only.
                    if (q.status === 'approved' || q.status === 'answered') {
                        if (q.body || questions.value.some((x) => x.id === q.id)) upsertQuestion(q);
                    } else {
                        questions.value = questions.value.filter((x) => x.id !== q.id);
                    }
                    return;
                }
                if (questions.value.some((x) => x.id === q.id)) upsertQuestion(q);
            },
            '.chat.posted': (e) => {
                if (!chat.value.some((m) => m.id === e.message.id)) chat.value = [...chat.value, e.message].slice(-100);
            },
            '.chat.hidden': (e) => { chat.value = chat.value.filter((m) => m.id !== e.id); },
        },
    },
    {
        name: `presenters.event.${props.eventId}`,
        handlers: {
            '.poll.results': (e) => {
                const exists = polls.value.some((p) => p.id === e.poll.id);
                polls.value = exists ? polls.value.map((p) => (p.id === e.poll.id ? e.poll : p)) : [e.poll, ...polls.value];
            },
        },
    },
];
if (props.staff) {
    channels.push({
        name: `admin.event.${props.eventId}`,
        handlers: { '.question.pending': (e) => upsertQuestion(e) },
    });
}

const { state: realtimeState } = useRealtime(channels, { poll: load, pollMs: 8000 });

// --- Tabs -----------------------------------------------------------------

const TABS = [
    { id: 'polls', label: 'Polls' },
    { id: 'qa', label: 'Q&A' },
    { id: 'chat', label: 'Chat' },
];
const tab = ref('polls');

// --- Polls ------------------------------------------------------------------

const openPoll = computed(() => polls.value.find((p) => p.status === 'open') ?? null);
const drafts = computed(() => polls.value.filter((p) => p.status === 'draft'));
const closedPolls = computed(() => polls.value.filter((p) => p.status === 'closed').slice(0, 5));

const composing = ref(false);
const draft = reactive({ question: '', options: ['', ''], show_results_live: true });
const draftErrors = ref({});

function resetDraft() {
    Object.assign(draft, { question: '', options: ['', ''], show_results_live: true });
    draftErrors.value = {};
}

async function savePoll(launch) {
    draftErrors.value = {};
    const options = draft.options.map((o) => o.trim()).filter(Boolean);
    if (!draft.question.trim()) draftErrors.value.question = 'Write the question.';
    if (options.length < 2) draftErrors.value.options = 'Give at least two options.';
    if (Object.keys(draftErrors.value).length) return;

    await run(async () => {
        const before = new Set(polls.value.map((p) => p.id));
        const data = await props.api.createPoll({ question: draft.question.trim(), options, show_results_live: draft.show_results_live });
        if (launch) {
            const created = data.polls?.find((p) => !before.has(p.id));
            if (created) return props.api.openPoll(created.id);
        }
        return data;
    });
    if (!actionError.value) {
        composing.value = false;
        resetDraft();
    }
}

function launch(poll) {
    if (openPoll.value && !confirm(`Launching this closes "${openPoll.value.question}". Go ahead?`)) return;
    run(() => props.api.openPoll(poll.id));
}

function closePoll(poll) {
    run(() => props.api.closePoll(poll.id));
}

// --- Q&A ------------------------------------------------------------------

const pending = computed(() => questions.value.filter((q) => q.status === 'submitted'));
const answerable = computed(() => questions.value
    .filter((q) => q.status === 'approved' || q.status === 'answered')
    .sort((a, b) => (b.is_featured - a.is_featured) || ((a.status === 'answered') - (b.status === 'answered')) || (b.votes_count - a.votes_count)));

function moderate(action, q) {
    run(() => props.api.moderate(action, q.id));
}

// --- Chat -------------------------------------------------------------------

function hideChat(m) {
    if (!confirm(`Hide this message from ${m.author}? Everyone stops seeing it.`)) return;
    run(async () => {
        await props.api.hideChat(m.id);
        chat.value = chat.value.filter((x) => x.id !== m.id);
        return null;
    });
}

const clock = (iso) => (iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '');

// --- Styling for the two tones -------------------------------------------------

const c = computed(() => (dark.value
    ? {
        text: 'text-white',
        muted: 'text-white/75',
        faint: 'text-white/60',
        rule: 'border-white/15',
        card: 'border border-white/15 p-3',
        field: 'block w-full rounded-ctl border border-white/30 bg-ink-950 px-3 py-2 text-sm text-white placeholder:text-white/60 focus:border-white focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500',
        primary: 'rounded-ctl bg-white px-3 py-1.5 text-sm font-medium text-ink-900 hover:bg-signal-50 disabled:opacity-60',
        secondary: 'rounded-ctl border border-white/40 px-3 py-1.5 text-sm text-white hover:border-white disabled:opacity-60',
        link: 'text-sm text-white underline underline-offset-2',
        danger: 'text-sm text-red-300 underline underline-offset-2',
        tabOn: 'text-white',
        tabOff: 'text-white/75 hover:text-white',
        bar: 'bg-white',
        error: 'text-red-300',
    }
    : {
        text: 'text-gray-900',
        muted: 'text-gray-700',
        faint: 'text-gray-600',
        rule: 'border-gray-200',
        card: 'rounded border border-gray-200 p-3',
        field: 'block w-full rounded border-gray-300 text-sm',
        primary: 'rounded bg-ink-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-ink-800 disabled:opacity-60',
        secondary: 'rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-800 hover:border-gray-500 disabled:opacity-60',
        link: 'text-sm text-signal-700 underline underline-offset-2',
        danger: 'text-sm text-red-700 underline underline-offset-2',
        tabOn: 'text-gray-900',
        tabOff: 'text-gray-600 hover:text-gray-900',
        bar: 'bg-ink-900',
        error: 'text-red-700',
    }));
</script>

<template>
    <div :class="c.text">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b" :class="c.rule">
            <div role="tablist" aria-label="Polls, questions and chat" class="flex gap-1">
                <button
                    v-for="t in TABS"
                    :id="`${idBase}-tab-${t.id}`"
                    :key="t.id"
                    type="button"
                    role="tab"
                    :aria-selected="tab === t.id"
                    :aria-controls="`${idBase}-panel-${t.id}`"
                    class="relative inline-flex min-h-10 items-center gap-1.5 px-3 text-sm font-medium"
                    :class="tab === t.id ? c.tabOn : c.tabOff"
                    @click="tab = t.id"
                >
                    {{ t.label }}
                    <span v-if="t.id === 'polls' && openPoll" class="tnum text-xs" :class="c.faint">· live</span>
                    <span v-if="t.id === 'qa' && staff && pending.length" class="tnum text-xs" :class="c.faint">· {{ pending.length }} waiting</span>
                    <span v-if="tab === t.id" aria-hidden="true" class="absolute inset-x-2 bottom-0 h-0.5" :class="c.bar"></span>
                </button>
            </div>
            <p class="text-xs" :class="c.faint" role="status">
                {{ realtimeState === 'connected' ? '● Live' : 'Refreshing every few seconds' }}
            </p>
        </div>

        <p v-if="loadError" class="mt-3 text-sm" :class="c.error" role="alert">{{ loadError }}</p>
        <p v-if="actionError" class="mt-3 text-sm" :class="c.error" role="alert">{{ actionError }}</p>
        <p v-if="!loaded && !loadError" class="mt-3 text-sm" :class="c.muted">Loading…</p>

        <!-- Polls -->
        <div v-show="tab === 'polls'" :id="`${idBase}-panel-polls`" role="tabpanel" :aria-labelledby="`${idBase}-tab-polls`" class="mt-4 space-y-4">
            <div v-if="openPoll" :class="c.card">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <p class="text-sm font-semibold">
                        <span class="mr-1 inline-block h-2 w-2 rounded-full bg-verified-500 align-middle" aria-hidden="true"></span>
                        Live now: {{ openPoll.question }}
                    </p>
                    <button type="button" :class="c.secondary" :disabled="busy" @click="closePoll(openPoll)">Close poll</button>
                </div>
                <PollResults :poll="openPoll" :tone="tone" class="mt-3" />
                <p class="mt-2 text-xs" :class="c.faint">
                    {{ openPoll.show_results_live ? 'Attendees see these results as they come in.' : 'Attendees see the results when you close it.' }}
                </p>
            </div>

            <div v-if="!composing">
                <button type="button" :class="c.primary" @click="composing = true">New poll</button>
            </div>
            <form v-else class="space-y-3" :class="c.card" @submit.prevent="savePoll(true)">
                <div>
                    <label class="block text-sm font-medium" :for="`${idBase}-q`">Question</label>
                    <input :id="`${idBase}-q`" v-model="draft.question" type="text" maxlength="255" class="mt-1" :class="c.field" />
                    <p v-if="draftErrors.question" class="mt-1 text-sm" :class="c.error">{{ draftErrors.question }}</p>
                </div>
                <fieldset>
                    <legend class="text-sm font-medium">Options</legend>
                    <div v-for="(_, i) in draft.options" :key="i" class="mt-1.5 flex gap-2">
                        <label class="sr-only" :for="`${idBase}-o${i}`">Option {{ i + 1 }}</label>
                        <input :id="`${idBase}-o${i}`" v-model="draft.options[i]" type="text" maxlength="255" :class="c.field" />
                        <button
                            v-if="draft.options.length > 2"
                            type="button"
                            :class="c.danger"
                            :aria-label="`Remove option ${i + 1}`"
                            @click="draft.options.splice(i, 1)"
                        >Remove</button>
                    </div>
                    <button v-if="draft.options.length < 8" type="button" class="mt-2" :class="c.link" @click="draft.options.push('')">Add an option</button>
                    <p v-if="draftErrors.options" class="mt-1 text-sm" :class="c.error">{{ draftErrors.options }}</p>
                </fieldset>
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="draft.show_results_live" type="checkbox" class="mt-0.5 rounded" />
                    <span>Show attendees the results while it runs</span>
                </label>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" :class="c.primary" :disabled="busy">Launch now</button>
                    <button type="button" :class="c.secondary" :disabled="busy" @click="savePoll(false)">Save for later</button>
                    <button type="button" :class="c.link" @click="composing = false; resetDraft()">Cancel</button>
                </div>
            </form>

            <div v-if="drafts.length">
                <p class="text-sm font-semibold">Ready to launch</p>
                <ul class="mt-2 divide-y" :class="c.rule">
                    <li v-for="p in drafts" :key="p.id" class="flex items-center justify-between gap-3 py-2">
                        <span class="min-w-0 break-words text-sm">{{ p.question }}</span>
                        <button type="button" :class="c.secondary" :disabled="busy" @click="launch(p)">Launch</button>
                    </li>
                </ul>
            </div>

            <details v-if="closedPolls.length">
                <summary class="cursor-pointer text-sm font-semibold">Earlier results ({{ closedPolls.length }})</summary>
                <div v-for="p in closedPolls" :key="p.id" class="mt-3">
                    <p class="text-sm font-medium">{{ p.question }}</p>
                    <PollResults :poll="p" :tone="tone" class="mt-2" />
                </div>
            </details>
        </div>

        <!-- Q&A -->
        <div v-show="tab === 'qa'" :id="`${idBase}-panel-qa`" role="tabpanel" :aria-labelledby="`${idBase}-tab-qa`" class="mt-4 space-y-4">
            <div v-if="staff">
                <p class="text-sm font-semibold">Waiting for approval ({{ pending.length }})</p>
                <p v-if="!pending.length" class="mt-1 text-sm" :class="c.muted">Nothing waiting.</p>
                <ul class="mt-2 space-y-2">
                    <li v-for="q in pending" :key="q.id" :class="c.card">
                        <p class="break-words text-sm">{{ q.body }}</p>
                        <p v-if="q.user_name" class="mt-1 text-xs" :class="c.faint">{{ q.user_name }}</p>
                        <div class="mt-2 flex gap-3">
                            <button type="button" :class="c.link" :disabled="busy" @click="moderate('approve', q)">Approve</button>
                            <button type="button" :class="c.danger" :disabled="busy" @click="moderate('hide', q)">Hide</button>
                        </div>
                    </li>
                </ul>
            </div>

            <div>
                <p class="text-sm font-semibold">Questions from the audience</p>
                <p v-if="!answerable.length" class="mt-1 text-sm" :class="c.muted">No approved questions yet.</p>
                <ul class="mt-2 divide-y" :class="c.rule">
                    <li v-for="q in answerable" :key="q.id" class="flex gap-3 py-2.5">
                        <span class="tnum w-10 shrink-0 text-center text-sm font-semibold" :aria-label="`${q.votes_count} votes`">▲ {{ q.votes_count }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm" :class="q.status === 'answered' ? c.muted : ''">{{ q.body }}</p>
                            <p class="mt-1 flex flex-wrap gap-3 text-xs" :class="c.faint">
                                <span v-if="q.is_featured">Featured</span>
                                <span v-if="q.status === 'answered'">Answered</span>
                                <template v-if="staff">
                                    <button v-if="!q.is_featured && q.status !== 'answered'" type="button" :class="c.link" :disabled="busy" @click="moderate('feature', q)">Feature</button>
                                    <button v-if="q.status !== 'answered'" type="button" :class="c.link" :disabled="busy" @click="moderate('mark-answered', q)">Mark answered</button>
                                    <button type="button" :class="c.danger" :disabled="busy" @click="moderate('hide', q)">Hide</button>
                                </template>
                            </p>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Chat -->
        <div v-show="tab === 'chat'" :id="`${idBase}-panel-chat`" role="tabpanel" :aria-labelledby="`${idBase}-tab-chat`" class="mt-4">
            <p v-if="!chat.length" class="text-sm" :class="c.muted">No messages yet.</p>
            <ol class="max-h-96 space-y-2 overflow-y-auto" aria-label="Chat messages">
                <li v-for="m in [...chat].reverse()" :key="m.id" class="text-sm">
                    <span class="font-semibold">{{ m.author }}</span>
                    <span class="ml-1 text-xs" :class="c.faint">{{ clock(m.created_at) }}</span>
                    <button v-if="staff" type="button" class="ml-2" :class="c.danger" @click="hideChat(m)">Hide</button>
                    <span class="block break-words">{{ m.body }}</span>
                </li>
            </ol>
        </div>
    </div>
</template>
