<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import LiveEmbed from '@/Components/LiveEmbed.vue';
import FloatingReactions from '@/Components/Live/FloatingReactions.vue';
import LivePollCard from '@/Components/Live/LivePollCard.vue';
import LiveSidePanel from '@/Components/Live/LiveSidePanel.vue';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import { useRealtime } from '@/composables/useRealtime';
import { liveAudience, setLiveEvent } from '@/lib/presence';
import { REACTIONS, emojiFor } from '@/lib/reactions';
import Button from '@/Components/Button.vue';

const props = defineProps({
    event: Object,
    // Staff "attendee view" from the admin Control room / Broadcast console.
    preview: { type: Boolean, default: false },
    questions: Array,
    myPendingQuestions: { type: Array, default: () => [] },
    poll: Object,
    chat: { type: Array, default: () => [] },
    reactionCounts: { type: Object, default: () => ({}) },
    canModerate: { type: Boolean, default: false },
});

const isLive = ref(props.event.is_live);
const playerOpen = ref(props.event.player_open);
// Going live, or staff opening the player early, opens it before the lead window.
const withinLiveWindow = computed(() => props.event.within_live_window || isLive.value || playerOpen.value);
const currentSessionTitle = ref(props.event.current_session?.title);
const questions = ref(props.questions);
const myPendingQuestions = ref(props.myPendingQuestions);
const poll = ref(props.poll);
const chat = ref(props.chat);

// C2 — an Inertia visit/partial-reload (including the polling fallback)
// refreshes these props; keep local state in sync rather than only
// seeding it once at mount.
watch(() => props.event, (e) => {
    isLive.value = e.is_live;
    playerOpen.value = e.player_open;
    currentSessionTitle.value = e.current_session?.title;
});
watch(() => props.questions, (q) => { questions.value = q; });
watch(() => props.myPendingQuestions, (q) => { myPendingQuestions.value = q; });
watch(() => props.poll, (p) => {
    // A poll that just closed stays up showing its results (below) even
    // though the server only returns the open one.
    if (!p && poll.value?.status === 'closed') return;
    poll.value = p;
});
watch(() => props.chat, (c) => { chat.value = c; });

// Attendees online on this page, from the presence heartbeat.
onMounted(() => setLiveEvent(props.event.id));
onBeforeUnmount(() => setLiveEvent(null));

// --- Layout: panel over the video on wide screens, under it on phones ----

const wideQuery = window.matchMedia?.('(min-width: 768px)');
const isWide = ref(wideQuery?.matches ?? true);
const onWideChange = (e) => { isWide.value = e.matches; };
onMounted(() => wideQuery?.addEventListener?.('change', onWideChange));
onBeforeUnmount(() => wideQuery?.removeEventListener?.('change', onWideChange));

const PANEL_KEY = 'lumko.live.panelOpen';
function readPanelPref() {
    try { return window.localStorage.getItem(PANEL_KEY) !== 'false'; } catch { return true; }
}
const panelOpen = ref(readPanelPref());
watch(panelOpen, (open) => {
    try { window.localStorage.setItem(PANEL_KEY, String(open)); } catch { /* private mode */ }
});
const panelUnread = ref(0);

const isAgora = computed(() => props.event.livestream_provider === 'agora');
// Emoji live in the player's control bar once it's there (Agora, wide
// screens, player open); otherwise in a row under the stream.
const reactionsInPlayer = computed(() => isWide.value && isAgora.value && withinLiveWindow.value);

// --- Polls ----------------------------------------------------------------

const chosenOption = ref(null);
const pollBusy = ref(false);
let pollDismissTimer = null;

function respondToPoll(optionId) {
    if (!poll.value || poll.value.responded_by_me || pollBusy.value) return;
    pollBusy.value = true;
    chosenOption.value = optionId;
    router.post(route('live.polls.respond', [poll.value.id, optionId]), {}, {
        only: ['poll'],
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { pollBusy.value = false; },
    });
}

function onPollUpdated(e) {
    if (e.action === 'opened') {
        clearTimeout(pollDismissTimer);
        chosenOption.value = null;
        poll.value = { ...e.poll, responded_by_me: false };
        return;
    }
    if (poll.value?.id !== e.poll.id) return;

    poll.value = { ...poll.value, options: e.poll.options, status: e.poll.status };

    // Closed: the final results stay up for a moment, then the card goes.
    if (e.action === 'closed') {
        clearTimeout(pollDismissTimer);
        pollDismissTimer = setTimeout(() => {
            if (poll.value?.id === e.poll.id && poll.value.status === 'closed') poll.value = null;
        }, 20000);
    }
}
onBeforeUnmount(() => clearTimeout(pollDismissTimer));

// --- Reactions -----------------------------------------------------------

const floating = ref(null);
// Last counts seen, and this viewer's own clicks not yet reflected in a
// broadcast: those already floated when clicked.
let lastCounts = { ...props.reactionCounts };
const ownPending = {};
const sending = ref(false);

function sendReaction(type) {
    if (sending.value) return;
    floating.value?.burst(emojiFor(type), 1);
    ownPending[type] = (ownPending[type] ?? 0) + 1;

    // In the background: a tap shouldn't reload the page. A short pause
    // between taps keeps inside the per-minute limit.
    sending.value = true;
    setTimeout(() => { sending.value = false; }, 700);
    window.axios.post(route('live.reactions.store', props.event.id), { type }, { headers: { Accept: 'application/json' } })
        .catch(() => { ownPending[type] = Math.max(0, (ownPending[type] ?? 0) - 1); });
}

function onReactionAggregate(counts) {
    REACTIONS.forEach(({ type, emoji }) => {
        const added = (counts[type] ?? 0) - (lastCounts[type] ?? 0);
        if (added <= 0) return;
        const mine = Math.min(added, ownPending[type] ?? 0);
        ownPending[type] = (ownPending[type] ?? 0) - mine;
        floating.value?.burst(emoji, Math.min(added - mine, 6));
    });
    lastCounts = { ...counts };
}

// --- Real time --------------------------------------------------------------

const { state: realtimeState } = useRealtime(`event.${props.event.id}`, {
    '.session.updated': (e) => {
        isLive.value = e.is_live;
        playerOpen.value = e.player_open;
        currentSessionTitle.value = e.current_session?.title;
    },
    '.question.updated': (e) => {
        const idx = questions.value.findIndex((q) => q.id === e.question.id);
        if (e.action === 'hidden' || e.action === 'archived') {
            if (idx !== -1) questions.value.splice(idx, 1);
            return;
        }
        if (idx !== -1) {
            questions.value[idx] = { ...questions.value[idx], ...e.question };
        } else if (e.question.body) {
            questions.value = [{ ...e.question, voted_by_me: false }, ...questions.value];
        }
        // If this was one of my own pending questions, drop it from that list.
        const pendingIdx = myPendingQuestions.value.findIndex((q) => q.id === e.question.id);
        if (pendingIdx !== -1) myPendingQuestions.value.splice(pendingIdx, 1);
    },
    '.poll.updated': onPollUpdated,
    '.reaction.aggregate': (e) => onReactionAggregate(e.counts ?? {}),
    '.chat.posted': (e) => {
        if (chat.value.some((m) => m.id === e.message.id)) return;
        chat.value = [...chat.value, e.message].slice(-100);
    },
    '.chat.hidden': (e) => {
        chat.value = chat.value.filter((m) => m.id !== e.id);
    },
}, {
    only: ['event', 'questions', 'myPendingQuestions', 'poll', 'chat'],
});

const reactionButtonClass = 'inline-flex h-9 w-9 items-center justify-center rounded-full text-xl leading-none transition-transform hover:scale-110 active:scale-95 disabled:opacity-60 motion-reduce:transition-none motion-reduce:hover:scale-100';
</script>

<template>
    <AttendeeLayout>
    <Head title="Live now" />

    <p v-if="preview" class="bg-signal-700 px-4 py-2 text-center text-sm text-white" role="note">
        Staff preview of what attendees see. Questions, chat, reactions and poll answers you send here are real.
    </p>

    <!-- The stage: title, status and the stream, with chat, polls and reactions on it. -->
    <section class="stage border-t border-white/10">
        <div class="shell pb-12 pt-8 sm:pt-10">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="eyebrow flex items-center gap-2 text-white/60">
                        <span aria-hidden="true" class="h-2 w-2 rounded-full" :class="isLive ? 'animate-pulse bg-red-500 motion-reduce:animate-none' : 'bg-white/40'"></span>
                        {{ isLive ? 'LIVE' : 'Not live yet' }}
                        <template v-if="liveAudience">
                            <span aria-hidden="true">·</span>
                            <span data-testid="live-audience">{{ liveAudience }} online</span>
                        </template>
                    </p>
                    <h1 class="display mt-3 text-display-sm text-white">{{ event.name }}</h1>
                    <p v-if="currentSessionTitle" class="mt-2 text-sm text-white/70">Now: <span class="text-white">{{ currentSessionTitle }}</span></p>
                </div>
                <p
                    v-if="realtimeState !== 'connected'"
                    role="status"
                    aria-live="polite"
                    class="text-meta text-white/60"
                >
                    Live updates paused — refreshing automatically.
                </p>
            </div>

            <template v-if="event.has_ended">
                <div class="mt-8 flex aspect-video w-full flex-col items-center justify-center bg-ink-950 px-6 text-center">
                    <p class="font-display text-2xl text-white" style="font-stretch: 86%; font-weight: 740">This session has ended</p>
                    <Button v-if="event.recording_url" :href="event.recording_url" external variant="inverse" arrow class="mt-6">
                        Watch the recording
                    </Button>
                    <p v-else class="mt-3 text-sm text-white/60">A recording will be linked here if one becomes available.</p>
                </div>
                <div class="mt-6 h-[28rem]">
                    <LiveSidePanel
                        :event-id="event.id"
                        :chat="chat"
                        :questions="questions"
                        :my-pending-questions="myPendingQuestions"
                        has-ended
                        :can-moderate="canModerate"
                    />
                </div>
            </template>

            <template v-else>
                <div class="mt-8">
                    <LiveEmbed
                        v-model:panel-open="panelOpen"
                        :panel-badge="panelUnread"
                        :provider="event.livestream_provider"
                        :url="event.livestream_url"
                        :event-id="event.id"
                        :channel-name="event.livestream_channel_name"
                        :secondary-provider="event.livestream_secondary_provider"
                        :secondary-url="event.livestream_secondary_url"
                        :recording-url="event.recording_url"
                        :presenters="event.presenters"
                        :within-live-window="withinLiveWindow"
                        :opens-at="event.opens_at"
                    >
                        <template v-if="isWide" #panel>
                            <LiveSidePanel
                                overlay
                                :visible="panelOpen"
                                :event-id="event.id"
                                :chat="chat"
                                :questions="questions"
                                :my-pending-questions="myPendingQuestions"
                                :can-moderate="canModerate"
                                @collapse="panelOpen = false"
                                @unread="panelUnread = $event"
                            />
                        </template>

                        <template #stage>
                            <FloatingReactions ref="floating" />
                            <div v-if="isWide && poll" class="absolute bottom-16 left-3 z-30 w-[min(22rem,calc(100%-7rem))]">
                                <LivePollCard :poll="poll" :chosen-id="chosenOption" :busy="pollBusy" @vote="respondToPoll" />
                            </div>
                        </template>

                        <template v-if="reactionsInPlayer" #controls>
                            <button
                                v-for="r in REACTIONS"
                                :key="r.type"
                                type="button"
                                :class="[reactionButtonClass, 'bg-black/40 hover:bg-black/60']"
                                :aria-label="`React: ${r.label}`"
                                :title="r.label"
                                :disabled="sending"
                                @click="sendReaction(r.type)"
                            >{{ r.emoji }}</button>
                        </template>
                    </LiveEmbed>
                </div>

                <!-- Reactions below the player: phones, streams we don't control
                     (YouTube, Facebook), and before the player opens. -->
                <div v-if="!reactionsInPlayer" class="mt-4 flex flex-wrap items-center gap-2">
                    <span class="eyebrow mr-1 text-white/60">React</span>
                    <button
                        v-for="r in REACTIONS"
                        :key="r.type"
                        type="button"
                        :class="[reactionButtonClass, 'bg-white/10 hover:bg-white/20']"
                        :aria-label="`React: ${r.label}`"
                        :title="r.label"
                        :disabled="sending"
                        @click="sendReaction(r.type)"
                    >{{ r.emoji }}</button>
                </div>

                <!-- Phones: the poll and the chat/Q&A panel sit under the stream. -->
                <template v-if="!isWide">
                    <div v-if="poll" class="mt-4">
                        <LivePollCard :poll="poll" :chosen-id="chosenOption" :busy="pollBusy" @vote="respondToPoll" />
                    </div>
                    <div class="mt-4 h-[30rem]">
                        <LiveSidePanel
                            :event-id="event.id"
                            :chat="chat"
                            :questions="questions"
                            :my-pending-questions="myPendingQuestions"
                            :can-moderate="canModerate"
                        />
                    </div>
                </template>
            </template>
        </div>
    </section>
    </AttendeeLayout>
</template>
