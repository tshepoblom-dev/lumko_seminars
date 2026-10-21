<script setup>
import { computed } from 'vue';

// Result bars for a poll: { options: [{ id, label, votes }] }. Options
// without counts (results hidden from attendees) render as a plain list.
const props = defineProps({
    poll: { type: Object, required: true },
    // light: white admin cards · dark: over the video or on the navy stage
    tone: { type: String, default: 'light' },
    // The option this viewer voted for, if any.
    chosenId: { type: Number, default: null },
});

const hasCounts = computed(() => props.poll.options?.some((o) => typeof o.votes === 'number'));
const total = computed(() => props.poll.options?.reduce((sum, o) => sum + (o.votes ?? 0), 0) ?? 0);
const pct = (votes) => (total.value ? Math.round(((votes ?? 0) / total.value) * 100) : 0);

const dark = computed(() => props.tone === 'dark');
</script>

<template>
    <div>
        <ul class="space-y-2">
            <li v-for="option in poll.options" :key="option.id">
                <div class="flex items-baseline justify-between gap-3 text-sm" :class="dark ? 'text-white' : 'text-gray-800'">
                    <span class="min-w-0 break-words">
                        {{ option.label }}
                        <span v-if="option.id === chosenId" :class="dark ? 'text-white/75' : 'text-gray-600'"> (your vote)</span>
                    </span>
                    <span v-if="hasCounts" class="tnum shrink-0" :class="dark ? 'text-white/75' : 'text-gray-600'">
                        {{ pct(option.votes) }}% · {{ option.votes ?? 0 }}
                    </span>
                </div>
                <div
                    v-if="hasCounts"
                    class="mt-1 h-1.5 w-full overflow-hidden rounded-full"
                    :class="dark ? 'bg-white/15' : 'bg-gray-100'"
                    role="presentation"
                >
                    <div
                        class="h-full rounded-full motion-safe:transition-[width] motion-safe:duration-500"
                        :class="dark ? 'bg-white' : 'bg-signal-600'"
                        :style="{ width: pct(option.votes) + '%' }"
                    ></div>
                </div>
            </li>
        </ul>
        <p v-if="hasCounts" class="mt-2 text-xs" :class="dark ? 'text-white/60' : 'text-gray-600'">
            {{ total }} {{ total === 1 ? 'vote' : 'votes' }}
        </p>
    </div>
</template>
