<script setup>
defineProps({
    title: { type: String, default: null },
});
</script>

<template>
    <div class="border border-rule bg-white p-5 shadow-lift sm:p-6">
        <div v-if="title || $slots.actions" class="mb-4 flex items-center justify-between gap-4 border-b border-rule pb-3">
            <h3 v-if="title" class="font-display text-lg text-ink-900">{{ title }}</h3>
            <div v-if="$slots.actions"><slot name="actions" /></div>
        </div>
        <slot />
    </div>
</template>
