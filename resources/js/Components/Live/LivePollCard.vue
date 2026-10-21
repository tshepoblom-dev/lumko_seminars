<script setup>
import PollResults from '@/Components/Live/PollResults.vue';
import { computed, ref, watch } from 'vue';

// The open poll, over the stream (or above the chat on phones). Vote with
// one tap; after that it thanks you and shows results if the poll shares
// them. It minimises to a pill, and reopens by itself for a new poll.
const props = defineProps({
    poll: { type: Object, required: true },
    chosenId: { type: Number, default: null },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['vote']);

const minimised = ref(false);
watch(() => props.poll.id, () => { minimised.value = false; });

const closed = computed(() => props.poll.status === 'closed');
const voted = computed(() => props.poll.responded_by_me || props.chosenId !== null);
const showResults = computed(() => props.poll.options?.some((o) => typeof o.votes === 'number'));
const headingId = computed(() => `live-poll-${props.poll.id}`);
</script>

<template>
    <button
        v-if="minimised"
        type="button"
        data-stage-overlay
        class="inline-flex min-h-9 items-center gap-2 rounded-ctl bg-ink-950/90 px-3 text-sm font-medium text-white shadow-float ring-1 ring-white/20 backdrop-blur hover:bg-ink-950"
        @click="minimised = false"
    >
        <span aria-hidden="true" class="h-2 w-2 rounded-full" :class="closed ? 'bg-white/60' : 'animate-pulse bg-verified-500 motion-reduce:animate-none'"></span>
        {{ closed ? 'Poll results' : 'Poll open: answer' }}
    </button>

    <section
        v-else
        data-stage-overlay
        :aria-labelledby="headingId"
        class="w-full max-w-sm rounded-ctl bg-ink-950/90 p-4 text-white shadow-float ring-1 ring-white/20 backdrop-blur"
    >
        <div class="flex items-start justify-between gap-3">
            <p class="eyebrow text-white/75">{{ closed ? 'Poll closed' : 'Live poll' }}</p>
            <button
                type="button"
                class="-mr-1 -mt-1 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded text-white/75 hover:bg-white/10 hover:text-white"
                aria-label="Minimise poll"
                @click="minimised = true"
            >
                <svg aria-hidden="true" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14" /></svg>
            </button>
        </div>
        <h2 :id="headingId" class="mt-1 text-[0.9375rem] font-semibold leading-snug">{{ poll.question }}</h2>

        <div v-if="!voted && !closed" class="mt-3 space-y-2">
            <button
                v-for="option in poll.options"
                :key="option.id"
                type="button"
                class="flex w-full items-center rounded-ctl border border-white/30 px-3 py-2 text-left text-sm transition-colors hover:border-white hover:bg-white/10 disabled:opacity-60"
                :disabled="busy"
                @click="emit('vote', option.id)"
            >
                {{ option.label }}
            </button>
        </div>

        <template v-else>
            <p v-if="voted && !closed" class="mt-2 text-sm text-white/75" role="status">Thanks for voting.</p>
            <PollResults v-if="showResults" :poll="poll" tone="dark" :chosen-id="chosenId" class="mt-3" />
            <p v-else-if="!closed" class="mt-2 text-sm text-white/75">Results appear when the poll closes.</p>
        </template>
    </section>
</template>
