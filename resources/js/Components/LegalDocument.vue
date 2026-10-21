<script setup>
import { longDate } from '@/lib/eventFormat';

// The Privacy Policy and the Terms: a long read set on the briefing grid,
// with a contents list beside it on wide screens.
defineProps({
    title: { type: String, required: true },
    facts: { type: Object, required: true },
    version: { type: String, required: true },
    sections: { type: Array, required: true }, // [{ id, title }]
});
</script>

<template>
    <article class="shell grid gap-y-10 py-14 sm:py-20 lg:grid-cols-12 lg:gap-x-6 lg:py-24">
        <header class="lg:col-span-8 lg:col-start-4">
            <p class="eyebrow text-ink-400">{{ facts.legal_name }}</p>
            <h1 class="display mt-4 text-display-md text-ink-900">{{ title }}</h1>
            <p class="mt-4 text-sm text-ink-600">
                Effective {{ longDate(facts.effective_date) }} · Version {{ version }}
            </p>
            <p
                v-if="facts.is_draft"
                class="mt-6 border-t-2 border-amber-500 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                role="note"
            >
                Draft pending legal review. Items marked {{ facts.placeholder }} are still to be supplied by the organiser.
            </p>
        </header>

        <nav aria-label="Contents" class="lg:col-span-3 lg:row-start-2">
            <div class="lg:sticky lg:top-8">
                <p class="eyebrow text-ink-400">Contents</p>
                <ol class="mt-3 space-y-1.5 text-sm">
                    <li v-for="(section, i) in sections" :key="section.id" class="flex gap-2">
                        <span class="tnum w-5 shrink-0 text-ink-400">{{ i + 1 }}</span>
                        <a :href="`#${section.id}`" class="text-ink-700 hover:text-ink-900 hover:underline">{{ section.title }}</a>
                    </li>
                </ol>
            </div>
        </nav>

        <div class="legal-prose lg:col-span-8 lg:col-start-4 lg:row-start-2">
            <slot />
        </div>
    </article>
</template>

<style scoped>
.legal-prose {
    max-width: 68ch;
    @apply text-[0.9375rem] leading-[1.7] text-ink-700;
}
.legal-prose :deep(section) {
    scroll-margin-top: 2rem;
    @apply mt-7 border-t border-rule pt-7;
}
.legal-prose :deep(section:first-child) {
    @apply mt-0 border-t-0 pt-0;
}
.legal-prose :deep(h2) {
    @apply text-xl font-semibold leading-snug text-ink-900;
}
.legal-prose :deep(h3) {
    @apply mt-5 font-semibold text-ink-900;
}
.legal-prose :deep(p),
.legal-prose :deep(ul),
.legal-prose :deep(dl) {
    @apply mt-3;
}
.legal-prose :deep(ul) {
    @apply list-disc pl-5;
}
.legal-prose :deep(li + li) {
    @apply mt-1.5;
}
.legal-prose :deep(a) {
    @apply text-signal-700 underline underline-offset-[3px];
}
.legal-prose :deep(strong) {
    @apply font-semibold text-ink-900;
}
.legal-prose :deep(.todo) {
    @apply bg-amber-100 px-1 font-semibold text-amber-900;
}
</style>
