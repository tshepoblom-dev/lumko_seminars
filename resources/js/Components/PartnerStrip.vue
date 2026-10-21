<script setup>
import { Link } from '@inertiajs/vue3';

// An event's partners grouped by tier (PartnerListing). A logo once staff
// approve it, otherwise the name set in type; never a broken image.
defineProps({
    groups: { type: Array, required: true }, // [{ tier, partners: [{ name, logo_url, logo_alt, href, internal }] }]
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div :class="compact ? 'space-y-6' : 'space-y-12'">
        <div v-for="group in groups" :key="group.tier">
            <h3 class="eyebrow text-ink-500">{{ group.tier }}</h3>
            <ul :class="['mt-4 grid gap-px border border-rule bg-rule', compact ? 'grid-cols-2' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4']">
                <li v-for="p in group.partners" :key="p.name" class="bg-white">
                    <component
                        :is="p.href ? (p.internal ? Link : 'a') : 'div'"
                        :href="p.href || undefined"
                        :target="p.href && !p.internal ? '_blank' : undefined"
                        :rel="p.href && !p.internal ? 'noopener' : undefined"
                        :class="[
                            'flex h-full items-center justify-center px-5 text-center',
                            compact ? 'min-h-20 py-4' : 'min-h-28 py-6',
                            p.href ? 'transition-colors hover:bg-paper focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500' : '',
                        ]"
                    >
                        <img v-if="p.logo_url" :src="p.logo_url" :alt="p.logo_alt" loading="lazy" :class="compact ? 'max-h-10' : 'max-h-14'" class="w-auto max-w-full object-contain" />
                        <span v-else class="font-display text-lg text-ink-900" style="font-stretch: 86%; font-weight: 700">{{ p.name }}</span>
                        <span v-if="p.href && !p.internal" class="sr-only"> (opens in a new tab)</span>
                    </component>
                </li>
            </ul>
        </div>
    </div>
</template>
