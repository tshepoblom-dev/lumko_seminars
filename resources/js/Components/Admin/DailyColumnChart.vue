<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * One measure per day as columns (Admin → Analytics). A single series, so
 * no legend: the heading names it. Hover or arrow keys show a day's value;
 * the table view on the page carries every value without hovering.
 */
const props = defineProps({
    // [{ date: 'YYYY-MM-DD', value: Number }]
    points: { type: Array, required: true },
    label: { type: String, required: true },
    unit: { type: String, required: true }, // "visitors", "hits"
    unitOne: { type: String, required: true }, // "visitor", "hit"
});

const withUnit = (n) => `${n.toLocaleString('en-ZA')} ${n === 1 ? props.unitOne : props.unit}`;

// Drawn at the container's real pixel width, so axis text stays 11px on a phone.
const width = ref(640);
const HEIGHT = 200;
const PAD = { top: 12, right: 8, bottom: 26, left: 44 };
const plotW = computed(() => width.value - PAD.left - PAD.right);
const plotH = HEIGHT - PAD.top - PAD.bottom;

const figure = ref(null);
let observer = null;
onMounted(() => {
    width.value = Math.max(240, figure.value?.clientWidth || 640);
    observer = new ResizeObserver(([entry]) => { width.value = Math.max(240, Math.round(entry.contentRect.width)); });
    observer.observe(figure.value);
});
onBeforeUnmount(() => observer?.disconnect());

// Whole-number ticks at a clean step (1, 2 or 5 × a power of ten), at most
// five steps up to the first tick at or above the highest value.
const step = computed(() => {
    const highest = Math.max(1, ...props.points.map((p) => p.value));
    const magnitude = 10 ** Math.floor(Math.log10(highest));
    const nice = [0.1, 0.2, 0.5, 1, 2, 5, 10].map((m) => Math.max(1, m * magnitude)).find((s) => highest / s <= 5);
    return nice ?? magnitude * 10;
});
const max = computed(() => {
    const highest = Math.max(1, ...props.points.map((p) => p.value));
    return Math.ceil(highest / step.value) * step.value;
});
const ticks = computed(() => Array.from({ length: max.value / step.value + 1 }, (_, i) => i * step.value));
const slot = computed(() => plotW.value / Math.max(1, props.points.length));
// Capped at 24px; the rest of the slot is air (and at least a 2px gap).
const barW = computed(() => Math.max(1, Math.min(24, slot.value * 0.7, slot.value - 2)));

const y = (value) => PAD.top + plotH - (value / max.value) * plotH;

const bars = computed(() =>
    props.points.map((p, i) => {
        const h = Math.max(0, (p.value / max.value) * plotH);
        const x = PAD.left + i * slot.value + (slot.value - barW.value) / 2;
        const r = Math.min(4, barW.value / 2, h);
        const top = PAD.top + plotH - h;
        const bottom = PAD.top + plotH;
        // Rounded data end, square at the baseline.
        const d = h === 0 ? '' : `M${x},${bottom} V${top + r} Q${x},${top} ${x + r},${top} H${x + barW.value - r} Q${x + barW.value},${top} ${x + barW.value},${top + r} V${bottom} Z`;
        return { ...p, x, d, index: i };
    }),
);

const dayFormat = new Intl.DateTimeFormat('en-ZA', { day: 'numeric', month: 'short', timeZone: 'UTC' });
const longFormat = new Intl.DateTimeFormat('en-ZA', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
const shortDate = (iso) => dayFormat.format(new Date(`${iso}T00:00:00Z`));
const longDate = (iso) => longFormat.format(new Date(`${iso}T00:00:00Z`));

// First, middle and last day on the x axis.
const xLabels = computed(() => {
    const n = props.points.length;
    if (!n) return [];
    const picks = n < 3 ? [...Array(n).keys()] : [0, Math.floor((n - 1) / 2), n - 1];
    return picks.map((i) => ({
        x: PAD.left + i * slot.value + slot.value / 2,
        text: shortDate(props.points[i].date),
        anchor: i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle',
    }));
});

const total = computed(() => props.points.reduce((sum, p) => sum + p.value, 0));
const peak = computed(() => props.points.reduce((best, p) => (p.value > (best?.value ?? -1) ? p : best), null));

// --- Hover / keyboard -------------------------------------------------------

const active = ref(null);
const svg = ref(null);

function onPointer(event) {
    const rect = svg.value.getBoundingClientRect();
    const xInPlot = event.clientX - rect.left - PAD.left;
    const index = Math.floor(xInPlot / slot.value);
    active.value = index >= 0 && index < props.points.length ? index : null;
}

function onKey(event) {
    const last = props.points.length - 1;
    if (event.key === 'ArrowRight') active.value = Math.min(last, (active.value ?? -1) + 1);
    else if (event.key === 'ArrowLeft') active.value = Math.max(0, (active.value ?? last + 1) - 1);
    else if (event.key === 'Home') active.value = 0;
    else if (event.key === 'End') active.value = last;
    else if (event.key === 'Escape') active.value = null;
    else return;
    event.preventDefault();
}

const activePoint = computed(() => (active.value === null ? null : props.points[active.value]));
const tooltipLeft = computed(() => {
    if (active.value === null) return 0;
    return ((PAD.left + active.value * slot.value + slot.value / 2) / width.value) * 100;
});
</script>

<template>
    <figure ref="figure" class="relative">
        <svg
            ref="svg"
            :viewBox="`0 0 ${width} ${HEIGHT}`"
            :height="HEIGHT"
            class="block w-full touch-none select-none focus:outline-none focus-visible:ring-2 focus-visible:ring-signal-500"
            role="img"
            tabindex="0"
            :aria-label="`${label}: ${withUnit(total)} over ${points.length} days${peak && peak.value ? `, highest ${peak.value.toLocaleString('en-ZA')} on ${longDate(peak.date)}` : ''}. Use the left and right arrow keys to read each day.`"
            @pointermove="onPointer"
            @pointerleave="active = null"
            @keydown="onKey"
            @blur="active = null"
        >
            <!-- Gridlines + y ticks: hairline, recessive. -->
            <g>
                <template v-for="t in ticks" :key="t">
                    <line :x1="PAD.left" :x2="width - PAD.right" :y1="y(t)" :y2="y(t)" stroke="#dde1e8" stroke-width="1" shape-rendering="crispEdges" />
                    <text :x="PAD.left - 8" :y="y(t)" dy="0.32em" text-anchor="end" class="fill-ink-500 text-[11px] tabular-nums">{{ t.toLocaleString('en-ZA') }}</text>
                </template>
            </g>

            <!-- Columns -->
            <g>
                <path
                    v-for="bar in bars"
                    :key="bar.date"
                    :d="bar.d"
                    :fill="active === null || active === bar.index ? '#2C7BE5' : '#7fb0ef'"
                />
            </g>

            <!-- Hovered day: a hairline marker even when its value is 0. -->
            <line
                v-if="active !== null"
                :x1="PAD.left + active * slot + slot / 2"
                :x2="PAD.left + active * slot + slot / 2"
                :y1="PAD.top"
                :y2="PAD.top + plotH"
                stroke="#071130"
                stroke-opacity="0.25"
                stroke-width="1"
            />

            <!-- X labels -->
            <text
                v-for="l in xLabels"
                :key="l.text + l.x"
                :x="l.x"
                :y="HEIGHT - 6"
                :text-anchor="l.anchor"
                class="fill-ink-500 text-[11px]"
            >{{ l.text }}</text>
        </svg>

        <div
            v-if="activePoint"
            class="pointer-events-none absolute top-0 z-10 w-max -translate-x-1/2 whitespace-nowrap border border-rule bg-white px-3 py-2 text-sm shadow-lift"
            :style="{ left: `clamp(4.5rem, ${tooltipLeft}%, calc(100% - 4.5rem))` }"
            role="status"
        >
            <p class="font-semibold tabular-nums text-ink-900">{{ withUnit(activePoint.value) }}</p>
            <p class="text-meta text-ink-500">{{ longDate(activePoint.date) }}</p>
        </div>
    </figure>
</template>
