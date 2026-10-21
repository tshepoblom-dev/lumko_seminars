<script setup>
import { computed } from 'vue';

const props = defineProps({
    variant: { type: String, default: 'neutral' }, // neutral | success | warning | danger | info
    onStage: { type: Boolean, default: false },
});

// Status indicator: a dot and a label, never a filled pill. Green (verified)
// only ever appears as the dot; the label stays in ink for contrast.
const dots = {
    neutral: 'bg-ink-300',
    success: 'bg-verified-500',
    warning: 'bg-amber-500',
    danger: 'bg-red-600',
    info: 'bg-signal-500',
};

const classes = computed(() => [
    'inline-flex items-center gap-2 text-meta font-medium',
    props.onStage ? 'text-white' : 'text-ink-800',
]);
</script>

<template>
    <span :class="classes">
        <span aria-hidden="true" :class="['h-2 w-2 shrink-0 rounded-full', dots[variant] ?? dots.neutral]"></span>
        <slot />
    </span>
</template>
