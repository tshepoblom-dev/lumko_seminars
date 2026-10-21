<script setup>
// A full-page message (confirmation, closed, empty, error), set left-aligned
// on the briefing grid rather than centred in a box.
defineProps({
    eyebrow: { type: String, default: null },
    title: { type: String, required: true },
    tone: { type: String, default: 'paper' }, // paper | stage
});
</script>

<template>
    <section :class="tone === 'stage' ? 'stage' : ''">
        <div class="shell grid gap-y-5 py-20 sm:py-28 lg:grid-cols-12 lg:py-32">
            <p
                v-if="eyebrow"
                class="section-index lg:col-span-2 lg:pt-4"
                :class="tone === 'stage' ? 'text-white/60' : ''"
            >{{ eyebrow }}</p>
            <div class="lg:col-span-8" :class="eyebrow ? '' : 'lg:col-start-3'">
                <h1 class="display max-w-[18ch] text-display-md" :class="tone === 'stage' ? 'text-white' : 'text-ink-900'">{{ title }}</h1>
                <div
                    v-if="$slots.default"
                    class="mt-6 max-w-prose space-y-4 text-[0.9375rem] leading-relaxed"
                    :class="tone === 'stage' ? 'text-white/75' : 'text-ink-600'"
                >
                    <slot />
                </div>
                <div v-if="$slots.actions" class="mt-10 flex flex-wrap items-center gap-x-6 gap-y-4">
                    <slot name="actions" />
                </div>
            </div>
        </div>
    </section>
</template>
