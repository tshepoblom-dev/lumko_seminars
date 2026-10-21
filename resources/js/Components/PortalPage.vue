<script setup>
// Attendee portal page frame: a running head (eyebrow + display title) over
// a ruled content column on the briefing grid.
defineProps({
    title: { type: String, required: true },
    eyebrow: { type: String, default: null },
    width: { type: String, default: 'narrow' }, // narrow | wide
});
</script>

<template>
    <div class="shell pb-20 pt-10 sm:pt-14">
        <header class="grid gap-4 border-b border-ink-900 pb-8 lg:grid-cols-12 lg:items-end">
            <div class="lg:col-span-8">
                <p v-if="eyebrow" class="eyebrow text-ink-400">{{ eyebrow }}</p>
                <h1 class="display mt-3 text-display-md text-ink-900">{{ title }}</h1>
            </div>
            <div v-if="$slots.aside" class="text-sm text-ink-500 lg:col-span-4 lg:text-right">
                <slot name="aside" />
            </div>
        </header>
        <div class="mt-10 grid lg:grid-cols-12">
            <div :class="width === 'wide' ? 'lg:col-span-12' : 'lg:col-span-8'">
                <slot />
            </div>
        </div>
    </div>
</template>
