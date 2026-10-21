<script setup>
import { computed } from 'vue';

const props = defineProps({
    steps: { type: Array, required: true }, // [{ label: string }]
    current: { type: Number, required: true }, // 1-based; steps.length + 1 = review
    layout: { type: String, default: 'bar' }, // bar | list
});

const total = computed(() => props.steps.length);
const isReview = computed(() => props.current > total.value);
const currentLabel = computed(() => (isReview.value ? 'Review' : props.steps[props.current - 1]?.label));

const state = (i) => {
    if (i + 1 < props.current) return 'done';
    if (i + 1 === props.current) return 'current';
    return 'todo';
};
</script>

<template>
    <!-- Bar: a segmented progress rule with a running head, used on narrow screens. -->
    <div v-if="layout === 'bar'">
        <p class="flex items-baseline justify-between gap-4 text-meta">
            <span class="font-semibold text-ink-900">{{ currentLabel }}</span>
            <span class="tnum text-ink-500">
                <template v-if="isReview">All {{ total }} steps done</template>
                <template v-else>Step {{ current }} of {{ total }}</template>
            </span>
        </p>
        <ol class="mt-2.5 flex gap-1" aria-label="Registration progress">
            <li
                v-for="(step, i) in steps"
                :key="i"
                class="h-1 flex-1 transition-colors duration-300"
                :class="{
                    'bg-ink-900': state(i) === 'done',
                    'bg-signal-500': state(i) === 'current',
                    'bg-rule': state(i) === 'todo',
                }"
                :aria-current="state(i) === 'current' ? 'step' : undefined"
            >
                <span class="sr-only">{{ step.label }}{{ state(i) === 'done' ? ' (complete)' : '' }}</span>
            </li>
        </ol>
    </div>

    <!-- List: the running order of the form, used in the desktop side column. -->
    <ol v-else class="space-y-0" aria-label="Registration progress">
        <li
            v-for="(step, i) in steps"
            :key="i"
            class="flex items-baseline gap-3 border-l-2 py-1.5 pl-4 text-sm transition-colors"
            :class="{
                'border-ink-900 text-ink-500': state(i) === 'done',
                'border-signal-500 font-semibold text-ink-900': state(i) === 'current',
                'border-rule text-ink-400': state(i) === 'todo',
            }"
            :aria-current="state(i) === 'current' ? 'step' : undefined"
        >
            <span class="tnum w-5 shrink-0 text-meta">{{ String(i + 1).padStart(2, '0') }}</span>
            <span>
                {{ step.label }}
                <span v-if="state(i) === 'done'" class="sr-only">(complete)</span>
            </span>
        </li>
        <li
            class="flex items-baseline gap-3 border-l-2 py-1.5 pl-4 text-sm"
            :class="isReview ? 'border-signal-500 font-semibold text-ink-900' : 'border-rule text-ink-400'"
            :aria-current="isReview ? 'step' : undefined"
        >
            <span class="tnum w-5 shrink-0 text-meta">&rarr;</span>
            <span>Review &amp; confirm</span>
        </li>
    </ol>
</template>
