<script setup>
import Badge from '@/Components/Badge.vue';
import { router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

// Chat and Q&A beside the stream. Over the right of the video on wide
// screens (collapsible), under it on phones. The page owns the lists and
// keeps them live; this panel shows them and sends what the viewer writes.
const props = defineProps({
    eventId: { type: Number, required: true },
    chat: { type: Array, default: () => [] },
    questions: { type: Array, default: () => [] },
    myPendingQuestions: { type: Array, default: () => [] },
    hasEnded: { type: Boolean, default: false },
    // Staff (the admin "attendee view") can hide chat posts.
    canModerate: { type: Boolean, default: false },
    // Overlaid on the video: shows a collapse button and the glass backdrop.
    overlay: { type: Boolean, default: false },
    // False while collapsed: everything new counts as unread.
    visible: { type: Boolean, default: true },
});

const emit = defineEmits(['collapse', 'unread']);

// --- Tabs ---------------------------------------------------------------

const TABS = [
    { id: 'chat', label: 'Chat' },
    { id: 'qa', label: 'Q&A' },
];
const tab = ref('chat');
const unread = ref({ chat: 0, qa: 0 });
const idBase = `live-panel-${Math.random().toString(36).slice(2, 8)}`;

function selectTab(id) {
    tab.value = id;
    unread.value = { ...unread.value, [id]: 0 };
    if (id === 'chat') nextTick(() => scrollChatToEnd());
}

function onTabKeydown(e) {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
    const i = TABS.findIndex((t) => t.id === tab.value);
    const next = TABS[(i + (e.key === 'ArrowRight' ? 1 : TABS.length - 1)) % TABS.length];
    selectTab(next.id);
    nextTick(() => document.getElementById(`${idBase}-tab-${next.id}`)?.focus());
}

function countNew(which, added) {
    if (added <= 0) return;
    if (!props.visible || tab.value !== which) unread.value = { ...unread.value, [which]: unread.value[which] + added };
}

watch(() => props.visible, (v) => { if (v) unread.value = { ...unread.value, [tab.value]: 0 }; });
watch(unread, (u) => emit('unread', u.chat + u.qa), { deep: true });

// --- Chat -----------------------------------------------------------------

const chatList = ref(null);
const pinnedToEnd = ref(true);
const newBelow = ref(false);

function scrollChatToEnd() {
    const el = chatList.value;
    if (!el) return;
    el.scrollTop = el.scrollHeight;
    pinnedToEnd.value = true;
    newBelow.value = false;
}

function onChatScroll() {
    const el = chatList.value;
    pinnedToEnd.value = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
    if (pinnedToEnd.value) newBelow.value = false;
}

watch(() => props.chat.length, async (now, before) => {
    countNew('chat', now - before);
    await nextTick();
    if (pinnedToEnd.value) scrollChatToEnd();
    else if (now > before) newBelow.value = true;
});

onMounted(() => scrollChatToEnd());

const chatForm = useForm({ body: '' });
const chatInputId = `${idBase}-chat-input`;

function sendChat() {
    if (!chatForm.body.trim() || chatForm.processing) return;
    chatForm.post(route('live.chat.store', props.eventId), {
        only: ['chat'],
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            chatForm.reset();
            nextTick(() => scrollChatToEnd());
        },
    });
}

function hideChat(message) {
    if (!confirm(`Hide this message from ${message.author}? Everyone stops seeing it.`)) return;
    router.post(route('admin.live.chat.hide', { event: props.eventId, chatMessage: message.id }), {}, {
        only: ['chat'],
        preserveScroll: true,
        preserveState: true,
    });
}

const clock = (iso) => (iso ? new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '');

// --- Q&A ------------------------------------------------------------------

watch(() => props.questions.length, (now, before) => countNew('qa', now - before));

const questionForm = useForm({ body: '' });
const questionInputId = `${idBase}-question-input`;

function submitQuestion() {
    if (!questionForm.body.trim() || questionForm.processing) return;
    questionForm.post(route('live.questions.store', props.eventId), {
        only: ['myPendingQuestions', 'questions'],
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => questionForm.reset(),
    });
}

function upvote(question) {
    if (question.voted_by_me || props.hasEnded) return;
    router.post(route('live.questions.upvote', question.id), {}, {
        only: ['questions'],
        preserveScroll: true,
        preserveState: true,
    });
}

const sortedQuestions = computed(() => [...props.questions].sort(
    (a, b) => (b.is_featured - a.is_featured) || (b.votes_count - a.votes_count),
));

const fieldClass = 'block w-full min-w-0 rounded-ctl border border-white/25 bg-white/5 px-3 py-2 text-sm text-white placeholder:text-white/60 focus:border-white focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500';
</script>

<template>
    <div
        class="flex h-full min-h-0 flex-col text-white"
        :class="overlay ? 'border-l border-white/10 bg-ink-950/85 backdrop-blur-md' : 'bg-ink-950 ring-1 ring-white/10'"
    >
        <!-- Header: tabs, and a collapse button when over the video -->
        <div class="flex items-center gap-1 border-b border-white/10 px-2">
            <div role="tablist" aria-label="Chat and questions" class="flex flex-1 gap-1" @keydown="onTabKeydown">
                <button
                    v-for="t in TABS"
                    :id="`${idBase}-tab-${t.id}`"
                    :key="t.id"
                    type="button"
                    role="tab"
                    :aria-selected="tab === t.id"
                    :aria-controls="`${idBase}-panel-${t.id}`"
                    :tabindex="tab === t.id ? 0 : -1"
                    class="relative inline-flex min-h-11 items-center gap-2 px-3 text-sm font-medium"
                    :class="tab === t.id ? 'text-white' : 'text-white/75 hover:text-white'"
                    @click="selectTab(t.id)"
                >
                    {{ t.label }}
                    <span v-if="unread[t.id]" class="tnum rounded-full bg-white px-1.5 text-xs font-semibold text-ink-900">
                        {{ unread[t.id] }}<span class="sr-only"> new</span>
                    </span>
                    <span v-if="tab === t.id" aria-hidden="true" class="absolute inset-x-2 bottom-0 h-0.5 bg-white"></span>
                </button>
            </div>
            <button
                v-if="overlay"
                type="button"
                class="inline-flex h-9 w-9 items-center justify-center rounded text-white/75 hover:bg-white/10 hover:text-white"
                aria-label="Hide chat and questions"
                title="Hide chat and questions"
                @click="emit('collapse')"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 6l6 6-6 6" />
                </svg>
            </button>
        </div>

        <!-- Chat -->
        <div
            v-show="tab === 'chat'"
            :id="`${idBase}-panel-chat`"
            role="tabpanel"
            :aria-labelledby="`${idBase}-tab-chat`"
            class="relative flex min-h-0 flex-1 flex-col"
        >
            <ol
                ref="chatList"
                class="min-h-0 flex-1 space-y-2.5 overflow-y-auto px-3 py-3"
                aria-live="polite"
                aria-relevant="additions"
                aria-label="Chat messages"
                @scroll.passive="onChatScroll"
            >
                <li v-if="!chat.length" class="text-sm text-white/75">No messages yet. Say hello.</li>
                <li v-for="m in chat" :key="m.id" class="group text-sm leading-snug">
                    <span class="font-semibold text-white">{{ m.author }}</span>
                    <span class="ml-1 text-xs text-white/60">{{ clock(m.created_at) }}</span>
                    <button
                        v-if="canModerate"
                        type="button"
                        class="ml-2 text-xs text-red-300 underline underline-offset-2"
                        @click="hideChat(m)"
                    >Hide</button>
                    <span class="block break-words text-white/90">{{ m.body }}</span>
                </li>
            </ol>
            <button
                v-if="newBelow"
                type="button"
                class="absolute bottom-16 left-1/2 -translate-x-1/2 rounded-full bg-white px-3 py-1 text-xs font-semibold text-ink-900 shadow-float"
                @click="scrollChatToEnd"
            >
                New messages ↓
            </button>
            <form v-if="!hasEnded" class="flex gap-2 border-t border-white/10 p-3" @submit.prevent="sendChat">
                <label :for="chatInputId" class="sr-only">Chat message</label>
                <input
                    :id="chatInputId"
                    v-model="chatForm.body"
                    type="text"
                    maxlength="280"
                    autocomplete="off"
                    placeholder="Say something…"
                    :class="fieldClass"
                />
                <button
                    type="submit"
                    class="shrink-0 rounded-ctl bg-white px-3 text-sm font-medium text-ink-900 hover:bg-signal-50 disabled:opacity-60"
                    :disabled="chatForm.processing || !chatForm.body.trim()"
                >Send</button>
            </form>
            <p v-if="chatForm.errors.body" class="px-3 pb-3 text-sm text-red-300" role="alert">{{ chatForm.errors.body }}</p>
        </div>

        <!-- Q&A -->
        <div
            v-show="tab === 'qa'"
            :id="`${idBase}-panel-qa`"
            role="tabpanel"
            :aria-labelledby="`${idBase}-tab-qa`"
            class="flex min-h-0 flex-1 flex-col"
        >
            <div class="min-h-0 flex-1 overflow-y-auto px-3 py-3">
                <p v-if="hasEnded" class="text-sm text-white/75">This session has ended. Here's what was asked.</p>

                <div v-if="myPendingQuestions.length" class="mb-4">
                    <p class="eyebrow text-white/60">Your questions, awaiting moderation</p>
                    <p
                        v-for="q in myPendingQuestions"
                        :key="q.id"
                        class="mt-2 border border-dashed border-white/25 px-3 py-2 text-sm text-white/80"
                    >{{ q.body }}</p>
                </div>

                <p v-if="!sortedQuestions.length" class="text-sm text-white/75">
                    No questions yet. Questions appear here once a moderator approves them.
                </p>
                <ul>
                    <li
                        v-for="q in sortedQuestions"
                        :key="q.id"
                        class="flex gap-3 border-b border-white/10 py-3 last:border-b-0"
                    >
                        <button
                            type="button"
                            :disabled="q.voted_by_me || hasEnded"
                            class="flex w-10 shrink-0 flex-col items-center rounded-ctl border py-1 text-xs transition-colors disabled:cursor-default"
                            :class="q.voted_by_me ? 'border-white bg-white text-ink-900' : 'border-white/30 text-white hover:border-white'"
                            :aria-label="`Upvote, ${q.votes_count} ${q.votes_count === 1 ? 'vote' : 'votes'}`"
                            @click="upvote(q)"
                        >
                            <svg aria-hidden="true" viewBox="0 0 12 12" class="h-3 w-3" fill="currentColor"><path d="M6 2.5 10.5 9h-9z" /></svg>
                            <span class="tnum font-semibold">{{ q.votes_count }}</span>
                        </button>
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm text-white">{{ q.body }}</p>
                            <p v-if="q.is_featured || q.status === 'answered'" class="mt-1.5 flex gap-2">
                                <Badge v-if="q.is_featured" variant="info">Featured</Badge>
                                <Badge v-if="q.status === 'answered'" variant="success">Answered</Badge>
                            </p>
                        </div>
                    </li>
                </ul>
            </div>
            <form v-if="!hasEnded" class="border-t border-white/10 p-3" @submit.prevent="submitQuestion">
                <label :for="questionInputId" class="sr-only">Your question for the speakers</label>
                <div class="flex gap-2">
                    <input
                        :id="questionInputId"
                        v-model="questionForm.body"
                        type="text"
                        maxlength="500"
                        autocomplete="off"
                        placeholder="Ask the speakers…"
                        :class="fieldClass"
                    />
                    <button
                        type="submit"
                        class="shrink-0 rounded-ctl bg-white px-3 text-sm font-medium text-ink-900 hover:bg-signal-50 disabled:opacity-60"
                        :disabled="questionForm.processing || !questionForm.body.trim()"
                    >Ask</button>
                </div>
                <p class="mt-1.5 text-xs text-white/60">A moderator approves questions before everyone sees them.</p>
                <p v-if="questionForm.errors.body" class="mt-1 text-sm text-red-300" role="alert">{{ questionForm.errors.body }}</p>
            </form>
        </div>
    </div>
</template>
