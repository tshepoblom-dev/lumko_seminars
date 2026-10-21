<script setup>
// Account pages (sign in, password, verification) on the briefing grid:
// the heading holds the left columns, the form sits in a narrow right column.
defineProps({
    title: { type: String, required: true },
    eyebrow: { type: String, default: 'Your Lumko account' },
});
</script>

<template>
    <div class="shell grid gap-y-8 py-14 sm:py-20 lg:grid-cols-12 lg:py-28">
        <div class="lg:col-span-5">
            <p class="eyebrow text-ink-400">{{ eyebrow }}</p>
            <h1 class="display mt-4 max-w-[12ch] text-display-md text-ink-900">{{ title }}</h1>
        </div>
        <div class="border-t border-ink-900 pt-8 lg:col-span-5 lg:col-start-8 lg:pt-10">
            <slot />
        </div>
    </div>
</template>
