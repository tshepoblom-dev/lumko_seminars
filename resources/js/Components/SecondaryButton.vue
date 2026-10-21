<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-ctl border border-rule-strong bg-white px-5 text-sm font-medium text-ink-800 transition-colors duration-150 hover:border-ink-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500 focus-visible:ring-offset-2 disabled:opacity-40"
    >
        <slot />
    </button>
</template>
