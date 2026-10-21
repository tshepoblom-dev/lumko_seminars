<script setup>
import { computed } from 'vue';
import { longDate, timeRange } from '@/lib/eventFormat';

// When and where, set once and reused everywhere the event is named, so the
// facts always read the same way. `row` is the hero line; `stack` is the
// narrow side-column ledger.
const props = defineProps({
    event: { type: Object, required: true }, // { starts_at, ends_at, venue_name, venue_address }
    layout: { type: String, default: 'row' }, // row | stack
    onStage: { type: Boolean, default: false },
    showVenue: { type: Boolean, default: true },
});

const facts = computed(() => [
    { label: 'Date', value: longDate(props.event.starts_at) },
    { label: 'Time', value: timeRange(props.event.starts_at, props.event.ends_at), tnum: true },
    ...(props.showVenue && props.event.venue_name
        ? [{ label: 'Venue', value: props.event.venue_name, detail: props.event.venue_address }]
        : []),
].filter((f) => f.value));

const labelTone = computed(() => (props.onStage ? 'text-white/60' : 'text-ink-400'));
const valueTone = computed(() => (props.onStage ? 'text-white' : 'text-ink-900'));
const detailTone = computed(() => (props.onStage ? 'text-white/70' : 'text-ink-500'));
const ruleTone = computed(() => (props.onStage ? 'border-white/20' : 'border-rule'));
</script>

<template>
    <dl
        v-if="layout === 'row'"
        class="grid grid-cols-2 gap-x-6 gap-y-5 sm:grid-cols-[auto_auto_minmax(0,1fr)] sm:gap-x-0"
    >
        <div
            v-for="(f, i) in facts"
            :key="f.label"
            class="min-w-0 sm:border-l sm:px-6 sm:first:border-l-0 sm:first:pl-0"
            :class="[ruleTone, f.label === 'Venue' ? 'col-span-2 sm:col-span-1' : '']"
        >
            <dt class="eyebrow" :class="labelTone">{{ f.label }}</dt>
            <dd class="mt-1.5 text-[0.9375rem] font-medium leading-snug" :class="[valueTone, f.tnum ? 'tnum' : '']">
                {{ f.value }}
                <span v-if="f.detail" class="block text-meta font-normal" :class="detailTone">{{ f.detail }}</span>
            </dd>
        </div>
    </dl>

    <dl v-else class="ledger border-y" :class="ruleTone">
        <div v-for="f in facts" :key="f.label" class="py-3" :class="ruleTone">
            <dt class="eyebrow" :class="labelTone">{{ f.label }}</dt>
            <dd class="mt-1 text-sm font-medium leading-snug" :class="[valueTone, f.tnum ? 'tnum' : '']">
                {{ f.value }}
                <span v-if="f.detail" class="block text-meta font-normal" :class="detailTone">{{ f.detail }}</span>
            </dd>
        </div>
    </dl>
</template>
