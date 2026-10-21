<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AttendeeLayout from '@/Layouts/AttendeeLayout.vue';
import PortalPage from '@/Components/PortalPage.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({ resources: Array });

const grouped = computed(() => {
    const groups = { before_event: [], during_event: [], after_event: [] };
    for (const r of props.resources) groups[r.category]?.push(r);
    return groups;
});
</script>

<template>
    <AttendeeLayout>
    <Head title="Resources" />

    <PortalPage eyebrow="Shared by the organisers and speakers" title="Resources">
        <template v-for="(items, key) in grouped" :key="key">
            <section v-if="items.length" class="mb-12">
                <h2 class="eyebrow border-b border-rule pb-2 text-ink-400">{{ items[0].category_label }}</h2>
                <ul>
                    <li v-for="r in items" :key="r.id" class="border-b border-rule">
                        <a
                            :href="route('resources.show', r.id)"
                            target="_blank"
                            class="group flex items-start justify-between gap-6 py-4"
                        >
                            <span>
                                <span class="block font-medium text-ink-900 underline decoration-rule-strong underline-offset-4 group-hover:decoration-ink-900">{{ r.title }}</span>
                                <span v-if="r.description" class="mt-1 block text-sm text-ink-600">{{ r.description }}</span>
                                <span v-if="r.shared_by" class="mt-1 block text-meta text-ink-500">Shared by {{ r.shared_by }}</span>
                            </span>
                            <span aria-hidden="true" class="pt-0.5 text-ink-400 transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5 group-hover:text-ink-900">&nearr;</span>
                            <span class="sr-only">(opens in a new tab)</span>
                        </a>
                    </li>
                </ul>
            </section>
        </template>

        <EmptyState
            v-if="!resources.length"
            title="No resources are available yet."
            description="Slides, recordings and other materials appear here once the organisers share them."
        />
    </PortalPage>
    </AttendeeLayout>
</template>
