<script setup>
import { computed } from 'vue';
import Button from '@/Components/Button.vue';

// The "Lumko Seminars" series on a paper band: what the series is, its three
// pillars and the sectors it covers. Copy comes from config('seminar.series').
// It closes the home page (after the venue), and stands on its own under the
// hero when no event is published, so the page always explains the series.
const props = defineProps({
    series: { type: Object, required: true }, // { name, pillars: [{ name, summary }], example_sectors: [] }
    index: { type: String, default: '01' },
});

// "LegalTech, EduTech, MiningTech and FinTech". The sentence around it says
// "fields such as", because the list is examples, not the full scope.
const sectors = computed(() => {
    const list = props.series.example_sectors ?? [];
    return list.length > 1 ? `${list.slice(0, -1).join(', ')} and ${list.at(-1)}` : (list[0] ?? '');
});

const facts = [
    { label: 'Format', value: 'Hybrid breakfast' },
    { label: 'Where', value: 'A different city each time' },
    { label: 'Voices', value: 'Industry, academia, leadership' },
    { label: 'Runs', value: 'Over the coming year' },
];
</script>

<template>
    <section id="series" class="scroll-mt-4 bg-paper py-20 sm:py-24 lg:py-32" aria-labelledby="series-title">
        <div class="shell">
            <div class="grid gap-y-6 lg:grid-cols-12">
                <p class="section-index lg:col-span-2 lg:pt-1">{{ index }} <span class="text-ink-400">/</span> The series</p>

                <div class="lg:col-span-7 lg:pr-8">
                    <h2 id="series-title">
                        <span class="eyebrow block text-ink-400">{{ series.name }}</span>
                        <span class="display mt-5 block max-w-[16ch] text-display-md text-ink-900">Conversations for a changing world</span>
                    </h2>
                    <p class="mt-6 max-w-[42ch] text-lede text-ink-700">
                        {{ series.name }} is a series of hybrid breakfast conversations that brings together industry
                        leaders, academics, executives and professionals to explore the ideas, technologies and trends
                        shaping organisations and society.
                    </p>
                    <p class="mt-5 max-w-prose text-[0.9375rem] leading-relaxed text-ink-600">
                        Each seminar is an open platform for practical insight, expert perspectives and meaningful
                        discussion, connecting organisations, government, businesses and professionals with knowledge
                        that can help them navigate what comes next.
                    </p>
                </div>

                <dl class="ledger border-y border-rule text-sm lg:col-span-3 lg:col-start-10 lg:self-start">
                    <div v-for="f in facts" :key="f.label" class="flex justify-between gap-4 py-3">
                        <dt class="text-ink-500">{{ f.label }}</dt>
                        <dd class="text-right font-medium text-ink-900">{{ f.value }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Three pillars: typeset columns under a rule, not boxed cards. -->
            <div class="mt-16 grid gap-y-6 lg:mt-24 lg:grid-cols-12">
                <p class="eyebrow text-ink-400 lg:col-span-2 lg:pr-6 lg:pt-6">Built on three pillars</p>
                <ol class="grid gap-10 md:grid-cols-3 md:gap-6 lg:col-span-10">
                    <li
                        v-for="(pillar, i) in series.pillars"
                        :key="pillar.name"
                        class="border-t-2 border-signal-500 pt-5"
                    >
                        <span class="tnum text-meta text-ink-400">{{ String(i + 1).padStart(2, '0') }}</span>
                        <h3 class="mt-2 text-display-sm text-ink-900">{{ pillar.name }}</h3>
                        <p class="mt-3 max-w-[34ch] text-[0.9375rem] leading-relaxed text-ink-600">{{ pillar.summary }}</p>
                    </li>
                </ol>
            </div>

            <!-- Where the series goes, set as one sentence. -->
            <div class="mt-16 grid gap-y-6 lg:mt-24 lg:grid-cols-12">
                <p class="eyebrow text-ink-400 lg:col-span-2 lg:pt-2">The coming year</p>
                <p
                    class="font-display text-display-sm text-ink-500 lg:col-span-9"
                    style="font-stretch: 92%; font-weight: 500"
                >
                    The series travels to <span class="text-ink-900">different cities</span>, exploring fields such
                    as <span class="text-ink-900">{{ sectors }}</span> through all three pillars.
                </p>
            </div>

            <div class="mt-16 grid gap-y-6 border-t border-ink-900 pt-8 lg:grid-cols-12 lg:items-end">
                <p class="max-w-prose text-ink-600 lg:col-span-6 lg:col-start-3">
                    Follow the series to hear when the next city and topic are announced.
                </p>
                <div class="lg:col-span-4 lg:col-start-9 lg:justify-self-end">
                    <Button
                        :href="$page.props.auth?.user ? route('attendee.preferences.edit') : route('login')"
                        variant="outline"
                        size="lg"
                        arrow
                        class="w-full sm:w-auto"
                    >
                        {{ $page.props.auth?.user ? 'Manage your updates' : 'Sign in to follow the series' }}
                    </Button>
                </div>
            </div>
        </div>
    </section>
</template>
