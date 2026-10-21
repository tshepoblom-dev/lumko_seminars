<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <div class="border-t-2 border-ink-900 py-10">
        <p class="font-display text-xl text-ink-900" style="font-stretch: 86%; font-weight: 700">{{ title }}</p>
        <p v-if="description" class="mt-2 max-w-prose text-sm text-ink-500">{{ description }}</p>
        <div v-if="$slots.action" class="mt-5"><slot name="action" /></div>
    </div>
</template>
